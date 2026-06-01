<?php
/**
 * includes/child_eval_engine.php
 *
 * Module-agnostic adaptive evaluation engine for child cognitive modules:
 *
 *   - Mind Power (memory, attention, reasoning)
 *   - Behavior (emotional regulation, social skills, self-control)
 *   - General Knowledge (age-appropriate world facts, curiosity)
 *   - Speech (handled separately because of voice — but reports unify here)
 *
 * Same shape for all modules. Claude generates each question adaptively based on:
 *   - Child's age
 *   - Module config (axes to probe, what good looks like at this age)
 *   - Previous turns (what the child got right/wrong, difficulty level)
 *
 * Public API:
 *
 *   ce_start_session(int $child_id, string $module): int
 *     Returns session_id. Schedules first turn.
 *
 *   ce_next_turn(int $session_id): array
 *     Returns ['ok' => true, 'turn_no' => N, 'question' => {...}, 'instructions' => "...", 'done' => false]
 *     OR ['ok' => true, 'done' => true, 'report' => {...}]
 *
 *   ce_submit_answer(int $session_id, array $answer_payload): array
 *     Records answer, scores it via Claude, returns ['ok' => true, 'feedback' => "...", 'is_correct' => bool]
 *
 *   ce_finalise_session(int $session_id): array
 *     Generates final per-module score + flags + 1-page report via Claude.
 *
 * Storage:
 *   child_eval_sessions    — one row per (child × module × attempt)
 *   child_eval_turns       — one row per question/answer pair
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/child_eval_modules.php';

// ────────────────────────────────────────────────────────────────────
// Schema
// ────────────────────────────────────────────────────────────────────
function ce_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS child_eval_sessions (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id        INTEGER NOT NULL,
        module          TEXT NOT NULL,
        age_at_session  REAL,
        status          TEXT DEFAULT 'in_progress',
        target_turns    INTEGER DEFAULT 10,
        turn_count      INTEGER DEFAULT 0,
        overall_score   REAL,
        report_json     TEXT,
        started_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        last_activity_at TEXT,
        completed_at    TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ce_sessions_child ON child_eval_sessions(child_id, module)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ce_sessions_status ON child_eval_sessions(status)");

    db()->exec("CREATE TABLE IF NOT EXISTS child_eval_turns (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id      INTEGER NOT NULL,
        turn_no         INTEGER NOT NULL,
        axis            TEXT,
        difficulty      INTEGER DEFAULT 3,
        question_json   TEXT NOT NULL,
        answer_json     TEXT,
        is_correct      INTEGER,
        score           REAL,
        response_seconds REAL,
        feedback        TEXT,
        ai_meta_json    TEXT,
        asked_at        TEXT DEFAULT CURRENT_TIMESTAMP,
        answered_at     TEXT,
        UNIQUE(session_id, turn_no)
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ce_turns_session ON child_eval_turns(session_id, turn_no)");

    // Migration: add response_seconds to existing tables that pre-date it
    try {
        $cols = db()->query("PRAGMA table_info(child_eval_turns)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('response_seconds', $names, true)) {
            @db()->exec("ALTER TABLE child_eval_turns ADD COLUMN response_seconds REAL");
        }
    } catch (Throwable $_) {}
}

// ────────────────────────────────────────────────────────────────────
// Helper: scrub speech-verb patterns from prompts (defensive layer)
// ────────────────────────────────────────────────────────────────────
function _ce_clean_prompt(string $s): string {
    // Strategy: split by sentence terminators, drop any sentence containing
    // a forbidden phrase, rejoin.
    $forbidden_patterns = [
        // English speech verbs
        '/\bI[\'’]?ll\s+(say|tell|read|speak)/i',
        '/\bI\s+(will|am\s+going\s+to)\s+(say|tell|read|speak)/i',
        '/\bListen\s+(carefully|to)/i',
        '/\bNow\s+I[\'’]?ll/i',
        '/\bRepeat\s+after\s+me\b/i',

        // Hindi speech verbs in Roman script — match the verb itself (any words before it)
        '/\bbolu?ng[aei]\b/i',
        '/\bpuchung[aei]\b/i',
        '/\bsunaau?ng[aei]\b/i',
        '/\bbataau?ng[aei]\b/i',
        '/\bsun\s+lo\b/i',
        '/\bdhyan\s+se\s+suno\b/i',
        '/\bbatat?[aei]?\s+(hoon|hu)\b/i',
        '/\bsuno\s+aur\b/i',
        '/\baur\s+yaad\s+rakho\b/i',
        '/\bmain\s+(tumhe|aapko|tumse)\b/i',  // any "main tumhe/aapko/tumse" pattern

        // Hindi speech verbs in Devanagari
        '/बोलूंगा|बोलूंगी|पूछूंगा|पूछूंगी|सुनाऊंगा|सुनाऊंगी|बताऊंगा/u',
        '/ध्यान\s*से\s*सुनो|सुन\s*लो/u',
    ];

    // Split on sentence terminators while keeping them
    $parts = preg_split('/([.!?।]+\s*)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!$parts) return trim($s);

    $kept = [];
    for ($i = 0; $i < count($parts); $i += 2) {
        $sentence = $parts[$i] ?? '';
        $term     = $parts[$i + 1] ?? '';
        if ($sentence === '') continue;

        $bad = false;
        foreach ($forbidden_patterns as $p) {
            if (preg_match($p, $sentence)) { $bad = true; break; }
        }
        if (!$bad) $kept[] = $sentence . $term;
    }

    $out = trim(implode('', $kept));

    // Remove trailing "Ready?" leftovers
    $out = preg_replace('/\bReady\s*\??\s*$/iu', '', $out);

    // Tidy spacing
    $out = preg_replace('/\s{2,}/', ' ', $out);
    $out = trim((string)$out);

    // If we ended up empty (entire prompt was speech-verb noise),
    // fall back to a safe generic instruction.
    if ($out === '' || mb_strlen($out) < 6) {
        $out = 'Look at the content below, then type your answer.';
    }
    return $out;
}


// ────────────────────────────────────────────────────────────────────
// Session lifecycle
// ────────────────────────────────────────────────────────────────────
function ce_start_session(int $child_id, string $module): int {
    ce_ensure_schema();

    // If an in_progress session exists for this child+module within 24h, resume it
    $st = db()->prepare("SELECT id FROM child_eval_sessions
                          WHERE child_id = ? AND module = ? AND status = 'in_progress'
                            AND started_at > datetime('now', '-24 hours')
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$child_id, $module]);
    if ($existing = (int)$st->fetchColumn()) return $existing;

    // Get child's age
    $cst = db()->prepare("SELECT dob FROM children WHERE id = ?");
    $cst->execute([$child_id]);
    $dob = $cst->fetchColumn();
    $age = $dob ? round((time() - strtotime((string)$dob)) / 86400 / 365.25, 1) : 7.0;

    $cfg = ce_module_config($module, $age);
    $target_turns = (int)($cfg['turns'] ?? 10);

    db()->prepare("INSERT INTO child_eval_sessions (child_id, module, age_at_session, target_turns, last_activity_at)
                   VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)")
       ->execute([$child_id, $module, $age, $target_turns]);

    return (int)db()->lastInsertId();
}

function ce_load_session(int $session_id): ?array {
    $st = db()->prepare("SELECT s.*, c.name AS child_name, c.dob
                          FROM child_eval_sessions s
                          LEFT JOIN children c ON c.id = s.child_id
                          WHERE s.id = ?");
    $st->execute([$session_id]);
    return $st->fetch() ?: null;
}

function ce_load_turns(int $session_id): array {
    $st = db()->prepare("SELECT * FROM child_eval_turns WHERE session_id = ? ORDER BY turn_no ASC");
    $st->execute([$session_id]);
    return $st->fetchAll() ?: [];
}

// ────────────────────────────────────────────────────────────────────
// Generate next question (Claude)
// ────────────────────────────────────────────────────────────────────
function ce_generate_next_question(int $session_id): array {
    $session = ce_load_session($session_id);
    if (!$session) return ['ok' => false, 'error' => 'Session not found'];
    if ($session['status'] !== 'in_progress') return ['ok' => false, 'error' => 'Session not active', 'done' => true];

    $turns = ce_load_turns($session_id);
    $next_turn_no = count($turns) + 1;
    $target_turns = (int)$session['target_turns'];

    if ($next_turn_no > $target_turns) {
        // Time to finalise
        return ['ok' => true, 'done' => true, 'message' => 'All turns complete'];
    }

    $module = (string)$session['module'];
    $age = (float)$session['age_at_session'];
    $cfg = ce_module_config($module, $age);

    // Build conversation history summary for the prompt
    $history_summary = '';
    $axes_covered = [];
    $correct_streak = 0;
    $last_difficulty = 3;
    foreach ($turns as $t) {
        $q = json_decode((string)$t['question_json'], true) ?: [];
        $a = json_decode((string)($t['answer_json'] ?? ''), true) ?: [];
        $axis = (string)($t['axis'] ?? '?');
        $axes_covered[$axis] = ($axes_covered[$axis] ?? 0) + 1;
        $history_summary .= "Turn {$t['turn_no']} (axis={$axis}, difficulty={$t['difficulty']}): "
                          . "Asked: " . mb_substr((string)($q['prompt'] ?? ''), 0, 100) . " | "
                          . "Child answered: " . mb_substr(json_encode($a, JSON_UNESCAPED_UNICODE), 0, 100) . " | "
                          . "Correct=" . ((int)$t['is_correct'] ? 'YES' : 'no') . " score=" . (float)$t['score'] . "\n";
        $last_difficulty = (int)$t['difficulty'];
        if ((int)$t['is_correct']) $correct_streak++; else $correct_streak = 0;
    }

    // Decide next axis to probe — least-covered first
    $axes = $cfg['axes'];
    $axis_counts = [];
    foreach ($axes as $a => $_) $axis_counts[$a] = $axes_covered[$a] ?? 0;
    asort($axis_counts);
    $next_axis = array_key_first($axis_counts);

    // Difficulty band: 1-5. Start at 3. +1 if correct streak >= 2, -1 if last 2 wrong.
    $next_difficulty = $last_difficulty;
    if ($correct_streak >= 2) $next_difficulty = min(5, $last_difficulty + 1);
    if (count($turns) >= 2) {
        $last_two_correct = (int)$turns[count($turns)-1]['is_correct'] + (int)$turns[count($turns)-2]['is_correct'];
        if ($last_two_correct === 0) $next_difficulty = max(1, $last_difficulty - 1);
    }

    $sys = $cfg['system_prompt'] ?? '';
    $sys .= "\n\nChild details:\n"
          . "  Name: " . $session['child_name'] . "\n"
          . "  Age: " . $age . " years\n";
    if (function_exists('age_band')) {
        $sys .= "  Age band: " . age_band($age) . "\n";
    }

    // Pass child's mother tongue so prompts match language
    try {
        $mtst = db()->prepare("SELECT mother_tongue FROM children WHERE id = ?");
        $mtst->execute([(int)$session['child_id']]);
        $mtongue = (string)$mtst->fetchColumn();
    } catch (Throwable $_) { $mtongue = ''; }
    if ($mtongue === '') $mtongue = 'English';
    $sys .= "  Mother tongue: $mtongue\n";

    $sys .= "\nThis is turn $next_turn_no of $target_turns.\n";
    $sys .= "Probe this axis: $next_axis (description: " . ($axes[$next_axis]['desc'] ?? '?') . ")\n";
    $sys .= "Target difficulty: $next_difficulty (1=very easy for age, 5=very hard for age).\n";
    if ($history_summary) {
        $sys .= "\nConversation so far:\n$history_summary";
    }

    $usr_question = (string)($cfg['question_user_prompt'] ?? 'Generate the next question now in JSON.');

    $resp = claude_chat($sys, [['role' => 'user', 'content' => $usr_question]], 800, 0.7);
    $clean = trim((string)$resp);
    if ($clean === '') {
        return ['ok' => false, 'error' => 'AI did not respond. Please try again.'];
    }
    // Strip code fences
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }
    $q = json_decode($clean, true);
    if (!is_array($q) || empty($q['prompt'])) {
        error_log("[ce_generate_next_question] bad JSON: " . substr($clean, 0, 200));
        return ['ok' => false, 'error' => 'AI returned unexpected format.'];
    }

    // ── Post-process: enforce memory_mode rules even if Claude forgets ──
    $memory_types = ['digit_span', 'word_recall', 'follow_instruction'];
    $reasoning_types = ['find_pattern', 'odd_one_out', 'mental_math', 'category_speed', 'spot_difference', 'ranking_logic'];
    $qtype = (string)($q['type'] ?? '');

    if (in_array($qtype, $memory_types, true)) {
        $q['memory_mode'] = true;
        if (empty($q['display_seconds']) || (int)$q['display_seconds'] < 2) {
            // Calculate from stimulus length
            $stim = (string)($q['stimulus'] ?? '');
            if ($qtype === 'digit_span') {
                $n = strlen(preg_replace('/\D/', '', $stim));
                $q['display_seconds'] = max(3, min(10, $n + 1));
            } elseif ($qtype === 'word_recall') {
                $n = count(array_filter(preg_split('/[\s,]+/', $stim)));
                $q['display_seconds'] = max(4, min(12, $n + 2));
            } else { // follow_instruction
                $q['display_seconds'] = 6;
            }
        }
        // Strip any "I'll say" / "listen" patterns that may have slipped through
        $q['prompt'] = _ce_clean_prompt((string)$q['prompt']);
    } elseif (in_array($qtype, $reasoning_types, true)) {
        $q['memory_mode'] = false;
    }

    // Persist
    db()->prepare("INSERT INTO child_eval_turns (session_id, turn_no, axis, difficulty, question_json, asked_at)
                   VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
       ->execute([$session_id, $next_turn_no, $next_axis, $next_difficulty, json_encode($q, JSON_UNESCAPED_UNICODE)]);

    db()->prepare("UPDATE child_eval_sessions SET turn_count = ?, last_activity_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$next_turn_no, $session_id]);

    return [
        'ok'         => true,
        'turn_no'    => $next_turn_no,
        'total'      => $target_turns,
        'axis'       => $next_axis,
        'difficulty' => $next_difficulty,
        'question'   => $q,
    ];
}

// ────────────────────────────────────────────────────────────────────
// Submit answer (scored by Claude)
// ────────────────────────────────────────────────────────────────────
function ce_submit_answer(int $session_id, array $answer_payload): array {
    $session = ce_load_session($session_id);
    if (!$session) return ['ok' => false, 'error' => 'Session not found'];
    if ($session['status'] !== 'in_progress') return ['ok' => false, 'error' => 'Session not active'];

    // Find latest unanswered turn
    $st = db()->prepare("SELECT * FROM child_eval_turns WHERE session_id = ? AND answer_json IS NULL ORDER BY turn_no DESC LIMIT 1");
    $st->execute([$session_id]);
    $turn = $st->fetch();
    if (!$turn) return ['ok' => false, 'error' => 'No pending question'];

    $module = (string)$session['module'];
    $age = (float)$session['age_at_session'];
    $cfg = ce_module_config($module, $age);
    $axis = (string)$turn['axis'];

    $q = json_decode((string)$turn['question_json'], true) ?: [];

    // Score via Claude
    $sys = $cfg['scoring_system_prompt'] ?? "You are an evaluator for a child cognitive test. Score the child's answer fairly.";
    $sys .= "\n\nChild's age: $age years.\nQuestion axis: $axis\nQuestion difficulty: " . (int)$turn['difficulty'] . "/5\n";

    $usr = "Question asked:\n" . json_encode($q, JSON_UNESCAPED_UNICODE)
         . "\n\nChild's answer:\n" . json_encode($answer_payload, JSON_UNESCAPED_UNICODE)
         . "\n\nScore it. Output JSON only:\n"
         . '{ "is_correct": true|false, "score_0_100": 0-100, "feedback_for_child": "1-sentence kind feedback, age-appropriate", "insight_for_report": "what this answer reveals about ability on this axis (1-2 lines, for the parent\'s report)" }';

    $resp = claude_chat($sys, [['role' => 'user', 'content' => $usr]], 500, 0.3);
    $clean = trim((string)$resp);
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }
    $j = json_decode($clean, true);
    if (!is_array($j)) {
        // Fallback: treat as half-credit
        $j = ['is_correct' => false, 'score_0_100' => 50, 'feedback_for_child' => 'Good try!', 'insight_for_report' => 'Could not auto-score; review needed.'];
    }

    $is_correct = !empty($j['is_correct']) ? 1 : 0;
    $score = (float)max(0, min(100, (int)($j['score_0_100'] ?? 50)));
    $feedback = (string)($j['feedback_for_child'] ?? '');

    // ── Response-time tracking + speed-weighted scoring ──
    // response_seconds comes from the client OR we calculate from asked_at→now
    $response_seconds = (float)($answer_payload['response_seconds'] ?? 0);
    if ($response_seconds <= 0) {
        $asked_at = strtotime((string)$turn['asked_at']);
        if ($asked_at) $response_seconds = max(0.5, round(time() - $asked_at, 1));
    }

    // Expected time band: scaled by difficulty + age
    // Base: 8s for difficulty 1, +4s per difficulty level
    // Multiplier: younger kids get more time
    $difficulty = (int)$turn['difficulty'];
    $expected = 8 + 4 * ($difficulty - 1);   // 8, 12, 16, 20, 24 seconds
    if ($age < 7) $expected *= 1.6;
    elseif ($age < 10) $expected *= 1.2;

    $speed_adjusted_score = $score;
    if ($is_correct) {
        // Fast + correct → bonus up to +10
        if ($response_seconds <= $expected * 0.7) {
            $speed_adjusted_score = min(100, $score + 10);
        }
        // Slow + correct → mild penalty -10 (took too long even though right)
        elseif ($response_seconds >= $expected * 1.5) {
            $speed_adjusted_score = max(0, $score - 10);
        }
    } else {
        // Fast + wrong → looks like guessing → bigger penalty
        if ($response_seconds <= $expected * 0.5) {
            $speed_adjusted_score = max(0, $score - 20);
        }
        // Slow + wrong → struggling, NO extra penalty (the wrong is already reflected in score)
    }

    db()->prepare("UPDATE child_eval_turns SET answer_json = ?, is_correct = ?, score = ?, feedback = ?, ai_meta_json = ?, response_seconds = ?, answered_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([
           json_encode($answer_payload, JSON_UNESCAPED_UNICODE),
           $is_correct,
           $speed_adjusted_score,
           $feedback,
           json_encode($j, JSON_UNESCAPED_UNICODE),
           $response_seconds,
           (int)$turn['id'],
       ]);

    db()->prepare("UPDATE child_eval_sessions SET last_activity_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$session_id]);

    return [
        'ok'              => true,
        'is_correct'      => (bool)$is_correct,
        'score'           => $speed_adjusted_score,
        'raw_score'       => $score,
        'response_seconds'=> $response_seconds,
        'expected_seconds'=> $expected,
        'feedback'        => $feedback,
    ];
}

// ────────────────────────────────────────────────────────────────────
// Finalise — generate per-module report
// ────────────────────────────────────────────────────────────────────
function ce_finalise_session(int $session_id): array {
    $session = ce_load_session($session_id);
    if (!$session) return ['ok' => false, 'error' => 'Session not found'];
    if ($session['status'] === 'completed' && !empty($session['report_json'])) {
        return ['ok' => true, 'report' => json_decode((string)$session['report_json'], true), 'cached' => true];
    }

    $module = (string)$session['module'];
    $age = (float)$session['age_at_session'];
    $cfg = ce_module_config($module, $age);
    $turns = ce_load_turns($session_id);

    if (empty($turns)) return ['ok' => false, 'error' => 'No turns to analyse'];

    // Aggregate per-axis
    $axis_data = [];
    foreach ($turns as $t) {
        $axis = (string)$t['axis'];
        if (!isset($axis_data[$axis])) $axis_data[$axis] = ['count' => 0, 'score_sum' => 0, 'correct' => 0, 'insights' => []];
        $axis_data[$axis]['count']++;
        $axis_data[$axis]['score_sum'] += (float)$t['score'];
        $axis_data[$axis]['correct']   += (int)$t['is_correct'];
        $ai = json_decode((string)($t['ai_meta_json'] ?? ''), true) ?: [];
        if (!empty($ai['insight_for_report'])) $axis_data[$axis]['insights'][] = (string)$ai['insight_for_report'];
    }

    $overall_score = 0; $denom = 0;
    foreach ($axis_data as $a) { $overall_score += $a['score_sum']; $denom += $a['count']; }
    $overall_score = $denom > 0 ? round($overall_score / $denom, 1) : 0;

    // Compose summary via Claude
    $sys = $cfg['report_system_prompt'] ?? "You are a child psychologist writing a 1-page evaluation report for the parent.";
    $sys .= "\n\nChild: {$session['child_name']}, age {$age} years.\n";
    $sys .= "Module: $module.\n";
    $sys .= "Overall score: {$overall_score}/100.\n\n";

    $sys .= "Per-axis performance:\n";
    foreach ($axis_data as $axis => $d) {
        $avg = $d['count'] > 0 ? round($d['score_sum'] / $d['count'], 0) : 0;
        $insights = implode(' | ', array_slice($d['insights'], 0, 3));
        $sys .= "  • $axis: {$d['correct']}/{$d['count']} correct, avg score $avg/100. Insights: $insights\n";
    }

    $usr = "Write the final report. Output JSON only:\n"
         . "{\n"
         . "  \"overall_score\": $overall_score,\n"
         . "  \"level\": \"emerging|developing|on-track|above-age\",\n"
         . "  \"summary\": \"3-4 sentences for the parent — warm, honest, specific to this child. Use child's name.\",\n"
         . "  \"strengths\": [\"specific strength 1\", \"strength 2\"],\n"
         . "  \"gaps\": [{\"axis\": \"axis_key\", \"label\": \"human label\", \"description\": \"1-2 lines\", \"course_day\": 1-7}],\n"
         . "  \"recommended_focus\": \"the ONE thing you'd tell this parent to focus on this week\"\n"
         . "}";

    $resp = claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1500, 0.5);
    $clean = trim((string)$resp);
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }
    $report = json_decode($clean, true);
    if (!is_array($report)) {
        $report = [
            'overall_score' => $overall_score,
            'level' => $overall_score >= 75 ? 'on-track' : ($overall_score >= 50 ? 'developing' : 'emerging'),
            'summary' => "Report generation failed. Score: $overall_score/100.",
            'strengths' => [],
            'gaps' => [],
        ];
    }
    $report['axis_breakdown'] = $axis_data;

    // Persist + also write to existing assessments table so the Hub picks it up
    db()->prepare("UPDATE child_eval_sessions
                   SET status = 'completed', overall_score = ?, report_json = ?, completed_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$overall_score, json_encode($report, JSON_UNESCAPED_UNICODE), $session_id]);

    // Bridge to assessments table for the existing Hub
    try {
        // Check assessments has required cols
        $st = db()->prepare("INSERT INTO assessments (child_id, module, age_band, status, score, level_reached, ai_summary, flags, completed_at)
                              VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $flags = [];
        foreach (($report['gaps'] ?? []) as $g) {
            $flags[] = [
                'q' => (string)($g['label'] ?? $g['axis'] ?? ''),
                'severity' => 'watch',
            ];
        }
        $st->execute([
            (int)$session['child_id'],
            $module,
            function_exists('age_band') ? age_band($age) : '',
            $overall_score,
            (string)($report['level'] ?? ''),
            (string)($report['summary'] ?? ''),
            json_encode($flags, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log("[ce_finalise] could not bridge to assessments: " . $e->getMessage());
    }

    return ['ok' => true, 'report' => $report];
}
