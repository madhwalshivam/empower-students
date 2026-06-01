<?php
/**
 * includes/daily_tasks.php
 *
 * Adaptive practice — one question at a time, level adjusts after each answer.
 *
 * Per-question state machine:
 *   correct + fast (under threshold)  →  comfortable_streak++
 *                                         streak >= 3 at offset 0  → END (mastery)
 *                                         streak >= 3 at offset <0 → step UP one rung, reset streak
 *   correct + slow                    →  hold, reset streak
 *   wrong                             →  step DOWN one rung, reset streak
 *
 * Level rungs (offset -> resolved):
 *   offset  0:  target_skill,   standard
 *   offset -1:  target_skill,   easier
 *   offset -2:  prev_skill_1,   standard
 *   offset -3:  prev_skill_1,   easier
 *   offset -4:  prev_skill_2,   standard
 *   ...
 *
 * Session ends when: (mastery streak >= 3 at offset 0) OR
 *                    (questions_answered >= MAX_QUESTIONS) OR
 *                    (manual end_session_now).
 * Min questions enforced: even if mastery hit early, we keep going to MIN_QUESTIONS.
 */

require_once __DIR__ . '/claude.php';

// ─────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────
const MASTERY_STREAK_REQD   = 3;     // 3 comfortable-correct in a row
const MIN_SESSION_QUESTIONS = 10;
const MAX_SESSION_QUESTIONS = 20;

// Speed thresholds in seconds — what counts as "comfortable" for an age bucket
function speed_threshold_for_age_bucket(string $bucket): int {
    switch ($bucket) {
        case '5-7':   return 90;
        case '8-10':  return 60;
        case '11-13': return 45;
        case '14-17': return 30;
        default:      return 60;
    }
}

// ─────────────────────────────────────────────────────────────
// Age bucket
// ─────────────────────────────────────────────────────────────
function age_bucket(float $age_yrs): string {
    if ($age_yrs < 8)  return '5-7';
    if ($age_yrs < 11) return '8-10';
    if ($age_yrs < 14) return '11-13';
    return '14-17';
}

// ─────────────────────────────────────────────────────────────
// Curriculum lookup
// ─────────────────────────────────────────────────────────────
function curriculum_for(string $service_key): array {
    $st = db()->prepare("SELECT * FROM skill_curriculum WHERE service_key = ? ORDER BY sort_order ASC");
    $st->execute([$service_key]);
    return $st->fetchAll();
}

function skill_by_id(string $service_key, string $skill_id): ?array {
    $st = db()->prepare("SELECT * FROM skill_curriculum WHERE service_key = ? AND skill_id = ?");
    $st->execute([$service_key, $skill_id]);
    return $st->fetch() ?: null;
}

/**
 * Walk the curriculum and return the index (0-based) of $skill_id.
 * Returns -1 if not found.
 */
function curriculum_index_of(string $service_key, string $skill_id): int {
    $curr = curriculum_for($service_key);
    foreach ($curr as $i => $sk) {
        if ($sk['skill_id'] === $skill_id) return $i;
    }
    return -1;
}

// ─────────────────────────────────────────────────────────────
// Mastery + adaptive picker (long-term, across sessions)
// ─────────────────────────────────────────────────────────────

/**
 * Has this child mastered this skill long-term?
 * Definition: 2+ submitted sessions where they ended with mastery (end_reason='mastery')
 *             on this skill as target.
 */
function is_skill_mastered(int $child_id, string $service_key, string $skill_id): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ? AND target_skill_id = ?
                           AND status = 'submitted' AND end_reason = 'mastery'");
    $st->execute([$child_id, $service_key, $skill_id]);
    return (int)$st->fetchColumn() >= 2;
}

/**
 * Pick the next target skill for this child (across-sessions, mastery-based).
 */
function pick_current_skill(int $child_id, string $service_key, float $age_yrs): array {
    $curriculum = curriculum_for($service_key);
    if (empty($curriculum)) {
        return ['skill' => null, 'all_mastered' => false, 'progress' => ['mastered' => 0, 'total' => 0]];
    }

    $mastered_count = 0;
    $current = null;

    foreach ($curriculum as $sk) {
        $age_ok = ($age_yrs >= (float)$sk['age_min'] - 0.5) && ($age_yrs <= (float)$sk['age_max'] + 0.5);
        if (is_skill_mastered($child_id, $service_key, $sk['skill_id'])) {
            $mastered_count++;
            continue;
        }
        if ($current === null && $age_ok) {
            $current = $sk;
        }
    }

    if ($current === null) {
        $last_age_ok = null;
        foreach ($curriculum as $sk) {
            if ((float)$sk['age_min'] - 0.5 <= $age_yrs && $age_yrs <= (float)$sk['age_max'] + 0.5) {
                $last_age_ok = $sk;
            }
        }
        return ['skill' => $last_age_ok, 'all_mastered' => true,
                'progress' => ['mastered' => $mastered_count, 'total' => count($curriculum)]];
    }

    return ['skill' => $current, 'all_mastered' => false,
            'progress' => ['mastered' => $mastered_count, 'total' => count($curriculum)]];
}

// ─────────────────────────────────────────────────────────────
// Level resolution: offset -> (skill_id, difficulty)
// ─────────────────────────────────────────────────────────────

/**
 * Convert a level_offset into a concrete (skill, difficulty) pair.
 * Walks DOWN the curriculum from $target_skill_id by one rung per 2 negative offsets.
 *
 * offset  0  -> target_skill,  standard
 * offset -1  -> target_skill,  easier
 * offset -2  -> prev_skill,    standard
 * offset -3  -> prev_skill,    easier
 * ...
 *
 * Returns array with skill (full row) and difficulty, or null if no skill exists at that depth.
 */
function resolve_level(string $service_key, string $target_skill_id, int $offset): ?array {
    $curr = curriculum_for($service_key);
    if (empty($curr)) return null;

    $target_idx = curriculum_index_of($service_key, $target_skill_id);
    if ($target_idx < 0) return null;

    if ($offset > 0) $offset = 0;  // never go above target
    $offset_abs = abs($offset);
    $skill_steps_down = intval($offset_abs / 2);   // every 2 offsets = 1 skill rung
    $is_easier_variant = ($offset_abs % 2) === 1;

    $resolved_idx = $target_idx - $skill_steps_down;
    if ($resolved_idx < 0) $resolved_idx = 0;  // can't go below the first skill

    return [
        'skill'      => $curr[$resolved_idx],
        'difficulty' => $is_easier_variant ? 'easier' : 'standard',
    ];
}

// ─────────────────────────────────────────────────────────────
// Session lifecycle
// ─────────────────────────────────────────────────────────────

function has_session_today(int $child_id, string $service_key): bool {
    $today = date('Y-m-d');
    $st = db()->prepare("SELECT COUNT(*) FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ?
                           AND session_date = ? AND status = 'submitted'");
    $st->execute([$child_id, $service_key, $today]);
    return (int)$st->fetchColumn() > 0;
}

function in_progress_session(int $child_id, string $service_key): ?array {
    $st = db()->prepare("SELECT * FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ?
                           AND status IN ('ready', 'in_progress')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$child_id, $service_key]);
    return $st->fetch() ?: null;
}

/**
 * Create a fresh session header. Generates the FIRST question lazily on first render.
 * Returns the session_id.
 */
function create_session(int $child_id, string $service_key, array $target_skill, string $age_bucket): int {
    // Look up most recent submitted session for this skill — if it had a trick, link it
    $st = db()->prepare("SELECT id FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ? AND target_skill_id = ?
                           AND status = 'submitted' AND trick_md IS NOT NULL
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$child_id, $service_key, $target_skill['skill_id']]);
    $prev_with_trick = $st->fetchColumn();

    $st = db()->prepare("INSERT INTO plan_daily_tasks
                         (child_id, service_key, target_skill_id, age_bucket, session_date,
                          tasks_json, status, current_level_offset, current_difficulty,
                          comfortable_streak, questions_answered, trick_from_prev_id,
                          week_n, day_idx)
                         VALUES (?, ?, ?, ?, ?, '{}', 'ready', 0, 'standard', 0, 0, ?, 0, 0)");
    $st->execute([
        $child_id, $service_key, $target_skill['skill_id'],
        $age_bucket, date('Y-m-d'),
        $prev_with_trick ?: null,
    ]);
    return (int)db()->lastInsertId();
}

function get_session(int $sess_id, int $child_id): ?array {
    $st = db()->prepare("SELECT * FROM plan_daily_tasks WHERE id = ? AND child_id = ?");
    $st->execute([$sess_id, $child_id]);
    return $st->fetch() ?: null;
}

function get_session_questions(int $sess_id): array {
    $st = db()->prepare("SELECT * FROM practice_questions WHERE session_id = ? ORDER BY seq ASC");
    $st->execute([$sess_id]);
    return $st->fetchAll();
}

/**
 * Get the current unanswered question (most recent generated, picked_idx is null).
 * Returns null if no current question (need to generate next one).
 */
function current_unanswered_question(int $sess_id): ?array {
    $st = db()->prepare("SELECT * FROM practice_questions
                         WHERE session_id = ? AND picked_idx IS NULL
                         ORDER BY seq DESC LIMIT 1");
    $st->execute([$sess_id]);
    return $st->fetch() ?: null;
}

// ─────────────────────────────────────────────────────────────
// Question generation — single question, with cache
// ─────────────────────────────────────────────────────────────

/**
 * Generate or fetch from pool a SINGLE question for the (skill, difficulty, age_bucket)
 * combo. Avoids serving questions already used in this session.
 * Returns array with q_text, options (array), correct_idx, explain. Or null.
 */
function get_or_generate_question(array $child, array $meta, array $skill, string $difficulty, int $sess_id, ?string $trick_to_test = null): ?array {
    $service_key = $meta['service_key'];
    $age_yrs     = (float) calc_age_years($child['dob']);
    $bucket      = age_bucket($age_yrs);

    // If we're verifying a trick from a previous session, always generate fresh
    // (the prompt needs to reference the trick).
    $skip_cache = !empty($trick_to_test);

    if (!$skip_cache) {
        // Try cache: question pool entry not yet seen by this child in this session
        $st = db()->prepare("
            SELECT qp.* FROM question_pool qp
            WHERE qp.service_key = ? AND qp.skill_id = ? AND qp.difficulty = ? AND qp.age_bucket = ?
              AND NOT EXISTS (
                SELECT 1 FROM practice_questions pq
                WHERE pq.session_id = ? AND pq.q_text = qp.q_text
              )
            ORDER BY qp.used_count ASC, RANDOM() LIMIT 1
        ");
        $st->execute([$service_key, $skill['skill_id'], $difficulty, $bucket, $sess_id]);
        $hit = $st->fetch();
        if ($hit) {
            db()->prepare("UPDATE question_pool SET used_count = used_count + 1 WHERE id = ?")->execute([(int)$hit['id']]);
            return [
                'q_text'      => (string)$hit['q_text'],
                'options'     => json_decode((string)$hit['options_json'], true) ?: [],
                'correct_idx' => (int)$hit['correct_idx'],
                'explain'     => (string)($hit['explain'] ?? ''),
            ];
        }
    }

    // Generate fresh
    $q = generate_single_question($child, $meta, $skill, $difficulty, $trick_to_test);
    if (!$q) return null;

    // Add to pool unless this was a trick-test (those are too specific to reuse)
    if (!$skip_cache) {
        try {
            db()->prepare("INSERT INTO question_pool
                           (service_key, skill_id, difficulty, age_bucket, q_text, options_json, correct_idx, explain, used_count)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
               ->execute([
                   $service_key, $skill['skill_id'], $difficulty, $bucket,
                   $q['q_text'], json_encode($q['options'], JSON_UNESCAPED_UNICODE),
                   $q['correct_idx'], $q['explain'],
               ]);
        } catch (Throwable $e) { /* don't fail user for cache write */ }
    }
    return $q;
}

/**
 * Single-question Claude call.
 */
function generate_single_question(array $child, array $meta, array $skill, string $difficulty, ?string $trick_to_test = null): ?array {
    $brief    = daily_tasks_module_brief($meta['service_key']);
    $audience = $brief['audience'];
    $age_yrs  = round((float)calc_age_years($child['dob']), 1);

    $diff_hint = '';
    switch ($difficulty) {
        case 'easier':
            $diff_hint = "DIFFICULTY: easier than typical for this skill. Use small/round numbers, simpler wording, very obvious wrong answers, single-step thinking only.";
            break;
        case 'harder':
            $diff_hint = "DIFFICULTY: harder than typical. Multi-step reasoning, plausible distractors, slightly larger numbers.";
            break;
        default:
            $diff_hint = "DIFFICULTY: standard for this skill and age bucket.";
    }

    $trick_section = '';
    if ($trick_to_test) {
        $trick_section = "\n\nLAST SESSION TRICK: \"{$trick_to_test}\"\n"
                       . "Generate a question that naturally invites use of this trick. The child should be able to solve it faster if they apply the trick.";
    }

    $sys = "You generate ONE practice multiple-choice question for an Indian student.\n\n"
         . "Module: {$meta['label']}\n"
         . "Subject: {$brief['subject']}\n"
         . "Audience: {$audience} (write addressing the {$audience} directly)\n"
         . "{$diff_hint}\n"
         . "Tone: warm, clear, age-appropriate. Indian context (rupees, common Indian foods, schools, names).\n\n"
         . "Output JSON ONLY (no markdown, no preamble, no thinking):\n"
         . "{\n"
         . "  \"q\": \"the question text\",\n"
         . "  \"options\": [\"A. ...\", \"B. ...\", \"C. ...\", \"D. ...\"],\n"
         . "  \"answer\": 0,\n"
         . "  \"explain\": \"ONE clean sentence stating the correct answer and the key step. NO chain-of-thought, NO 'wait let me recalculate', NO showing your work mid-explanation.\"\n"
         . "}\n\n"
         . "CRITICAL: 'answer' is the 0-indexed position of the correct option (0..3). Vary which position holds the correct answer across questions.\n"
         . "CRITICAL: The 'explain' field is a final clean explanation, never reasoning trace. Maximum 25 words.{$trick_section}";

    $user = "Child: {$child['name']}, age {$age_yrs} yrs"
          . (!empty($child['gender']) ? ", " . $child['gender'] : '')
          . "\n\nSKILL TO TEST: {$skill['skill_label']}\n"
          . "Skill description: {$skill['skill_brief']}\n\n"
          . "Generate ONE question.";

    $j = claude_json($sys, $user, 600, 0.8);
    if (!$j || empty($j['q']) || empty($j['options'])) return null;

    $opts = array_values((array)$j['options']);
    if (count($opts) < 2) return null;
    $opts = array_slice($opts, 0, 4);
    $ans  = max(0, min(count($opts) - 1, (int)($j['answer'] ?? 0)));

    // Sanitize explain — strip thinking-trace markers if AI leaked them
    $explain = (string)($j['explain'] ?? '');
    $explain = preg_replace('/\b(wait|let me|actually|recalculate|hmm|on second thought)\b[^.]*\./i', '', $explain);
    $explain = trim(preg_replace('/\s+/', ' ', $explain));

    return [
        'q_text'      => mb_substr((string)$j['q'], 0, 500),
        'options'     => array_map(fn($o) => mb_substr((string)$o, 0, 200), $opts),
        'correct_idx' => $ans,
        'explain'     => mb_substr($explain, 0, 250),
    ];
}

// ─────────────────────────────────────────────────────────────
// Save the next question into practice_questions table
// ─────────────────────────────────────────────────────────────

/**
 * Generate (or pull from cache) the next question and INSERT it into practice_questions.
 * Returns the new practice_questions row, or null on failure.
 *
 * Looks at session state to decide skill/difficulty.
 */
function generate_and_save_next_question(array $child, array $meta, array $sess): ?array {
    $sess_id      = (int)$sess['id'];
    $target_skill_id = (string)$sess['target_skill_id'];
    $offset       = (int)$sess['current_level_offset'];

    $level = resolve_level($meta['service_key'], $target_skill_id, $offset);
    if (!$level) return null;

    // First question of the session AND a previous trick exists? Test it.
    $is_first_q = ((int)$sess['questions_answered'] === 0);
    $trick = null;
    $tests_trick = 0;
    if ($is_first_q && !empty($sess['trick_from_prev_id'])) {
        $tst = db()->prepare("SELECT trick_md FROM plan_daily_tasks WHERE id = ?");
        $tst->execute([(int)$sess['trick_from_prev_id']]);
        $trick = $tst->fetchColumn() ?: null;
        if ($trick) $tests_trick = 1;
    }

    $q = get_or_generate_question($child, $meta, $level['skill'], $level['difficulty'], $sess_id, $trick);
    if (!$q) return null;

    // Insert into practice_questions
    $st = db()->prepare("SELECT COALESCE(MAX(seq), 0) + 1 FROM practice_questions WHERE session_id = ?");
    $st->execute([$sess_id]);
    $next_seq = (int)$st->fetchColumn();

    db()->prepare("INSERT INTO practice_questions
                   (session_id, seq, skill_id, level_offset, difficulty, q_text, options_json,
                    correct_idx, explain, tests_trick)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([
           $sess_id, $next_seq, $level['skill']['skill_id'], $offset, $level['difficulty'],
           $q['q_text'], json_encode($q['options'], JSON_UNESCAPED_UNICODE),
           $q['correct_idx'], $q['explain'], $tests_trick,
       ]);

    $row_id = (int)db()->lastInsertId();
    $row = db()->prepare("SELECT * FROM practice_questions WHERE id = ?");
    $row->execute([$row_id]);
    return $row->fetch() ?: null;
}

// ─────────────────────────────────────────────────────────────
// Answer recording + state machine
// ─────────────────────────────────────────────────────────────

/**
 * Record a child's answer to question $q_id, update session state, decide next move.
 *
 * Returns:
 *  ['action' => 'next_question',  'session' => updated_session]
 *  ['action' => 'end_session',    'session' => updated_session, 'reason' => 'mastery'|'max_questions']
 */
function record_answer(int $sess_id, int $child_id, int $q_id, int $picked_idx, int $time_seconds): array {
    $sess = get_session($sess_id, $child_id);
    if (!$sess || $sess['status'] === 'submitted') {
        return ['action' => 'end_session', 'session' => $sess, 'reason' => 'already_ended'];
    }

    // Load the question
    $st = db()->prepare("SELECT * FROM practice_questions WHERE id = ? AND session_id = ?");
    $st->execute([$q_id, $sess_id]);
    $q = $st->fetch();
    if (!$q || $q['picked_idx'] !== null) {
        return ['action' => 'end_session', 'session' => $sess, 'reason' => 'invalid_question'];
    }

    // Clamp picked_idx
    $opts = json_decode((string)$q['options_json'], true) ?: [];
    $picked_idx = max(0, min(count($opts) - 1, $picked_idx));
    $is_correct = ($picked_idx === (int)$q['correct_idx']);

    // Speed threshold for this child's age bucket
    $threshold = speed_threshold_for_age_bucket((string)$sess['age_bucket']);
    $was_comfortable = $is_correct && ($time_seconds <= $threshold);

    // Persist answer to question
    db()->prepare("UPDATE practice_questions
                   SET picked_idx = ?, is_correct = ?, time_seconds = ?, was_comfortable = ?,
                       answered_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$picked_idx, $is_correct ? 1 : 0, $time_seconds, $was_comfortable ? 1 : 0, $q_id]);

    // ──────── State machine ────────
    $new_offset = (int)$sess['current_level_offset'];
    $new_streak = (int)$sess['comfortable_streak'];
    $end_reason = null;

    if ($was_comfortable) {
        $new_streak++;
        if ($new_streak >= MASTERY_STREAK_REQD) {
            if ($new_offset === 0) {
                // Hit mastery streak at TARGET level
                if ((int)$sess['questions_answered'] + 1 >= MIN_SESSION_QUESTIONS) {
                    $end_reason = 'mastery';
                } else {
                    // Keep going to min count; stay at offset 0 but keep streak so future Qs still count
                    // (we don't reset streak — they're cruising)
                }
            } else {
                // Recovered — step UP one rung, reset streak
                $new_offset = min(0, $new_offset + 1);
                $new_streak = 0;
            }
        }
    } elseif ($is_correct && !$was_comfortable) {
        // Correct but slow — hold, reset streak (they're not yet comfortable)
        $new_streak = 0;
    } else {
        // Wrong — step DOWN one rung, reset streak
        $new_streak = 0;
        // Don't go below the floor: 2 skills back × 2 (easier variant) = -5
        $new_offset = max(-5, $new_offset - 1);
    }

    $new_questions_answered = (int)$sess['questions_answered'] + 1;
    $new_questions_correct  = (int)$sess['questions_correct'] + ($is_correct ? 1 : 0);

    // Hit max length?
    if ($end_reason === null && $new_questions_answered >= MAX_SESSION_QUESTIONS) {
        $end_reason = 'max_questions';
    }

    // Persist session updates
    if ($end_reason !== null) {
        $score = (int) round($new_questions_correct * 100 / $new_questions_answered);
        db()->prepare("UPDATE plan_daily_tasks
                       SET current_level_offset = ?, comfortable_streak = ?,
                           questions_answered = ?, questions_correct = ?,
                           status = 'submitted', end_reason = ?,
                           ended_at = CURRENT_TIMESTAMP, submitted_at = CURRENT_TIMESTAMP,
                           session_date = ?, score = ?
                       WHERE id = ?")
           ->execute([
               $new_offset, $new_streak, $new_questions_answered, $new_questions_correct,
               $end_reason, date('Y-m-d'), $score, $sess_id,
           ]);
    } else {
        db()->prepare("UPDATE plan_daily_tasks
                       SET current_level_offset = ?, comfortable_streak = ?,
                           questions_answered = ?, questions_correct = ?,
                           status = 'in_progress'
                       WHERE id = ?")
           ->execute([
               $new_offset, $new_streak, $new_questions_answered, $new_questions_correct, $sess_id,
           ]);
    }

    $updated = get_session($sess_id, $child_id);
    return [
        'action'      => $end_reason ? 'end_session' : 'next_question',
        'session'     => $updated,
        'reason'      => $end_reason,
        'is_correct'  => $is_correct,
        'comfortable' => $was_comfortable,
    ];
}

/** Manually end a session (child clicks "End session here"). */
function end_session_manually(int $sess_id, int $child_id): void {
    $sess = get_session($sess_id, $child_id);
    if (!$sess || $sess['status'] === 'submitted') return;

    $answered = (int)$sess['questions_answered'];
    $correct  = (int)$sess['questions_correct'];
    $score = $answered > 0 ? (int) round($correct * 100 / $answered) : 0;

    db()->prepare("UPDATE plan_daily_tasks
                   SET status = 'submitted', end_reason = 'manual',
                       ended_at = CURRENT_TIMESTAMP, submitted_at = CURRENT_TIMESTAMP,
                       session_date = ?, score = ?
                   WHERE id = ?")
       ->execute([date('Y-m-d'), $score, $sess_id]);
}

// ─────────────────────────────────────────────────────────────
// Trick generation at session end
// ─────────────────────────────────────────────────────────────

/**
 * Ask Claude to generate ONE trick/mnemonic for the target skill, tailored to the
 * child's age. Stored as Markdown in plan_daily_tasks.trick_md.
 */
function generate_trick_for_session(int $sess_id, array $child, array $meta): ?string {
    $sess = get_session($sess_id, (int)$child['id']);
    if (!$sess || empty($sess['target_skill_id'])) return null;

    $skill = skill_by_id($meta['service_key'], (string)$sess['target_skill_id']);
    if (!$skill) return null;

    $age_yrs = round((float)calc_age_years($child['dob']), 1);

    $sys = "You give ONE memorable trick / mnemonic / shortcut to help an Indian student remember "
         . "or apply a math/learning skill. Output Markdown only — no preamble, no JSON.\n\n"
         . "Format:\n"
         . "**TITLE** (3-6 words)\n\n"
         . "1-2 sentence trick description. Include a tiny example if it helps.\n\n"
         . "Total: under 60 words. Friendly, kid-appropriate tone.";

    $user = "Skill: {$skill['skill_label']}\n"
          . "Description: {$skill['skill_brief']}\n"
          . "Child's age: {$age_yrs} yrs\n\n"
          . "Give ONE specific, memorable trick they can use tomorrow.";

    $txt = claude_chat($sys, [['role' => 'user', 'content' => $user]], 300, 0.7);
    if (!$txt) return null;

    $txt = trim($txt);
    if ($txt === '') return null;

    // Save to session
    db()->prepare("UPDATE plan_daily_tasks SET trick_md = ? WHERE id = ?")
       ->execute([mb_substr($txt, 0, 1000), $sess_id]);

    return $txt;
}

// ─────────────────────────────────────────────────────────────
// Per-module subject brief
// ─────────────────────────────────────────────────────────────

function daily_tasks_module_brief(string $service_key): array {
    switch ($service_key) {
        case 'mod_math':
            return ['subject' => 'Numeracy and math word problems with Indian context (rupees, shopping, fractions, percentages).', 'audience' => 'child', 'count' => 4];
        case 'mod_language':
            return ['subject' => 'English language: vocabulary, grammar, comprehension.', 'audience' => 'child', 'count' => 4];
        case 'mod_general_awareness':
            return ['subject' => 'General awareness: current affairs, Indian history/geography, science basics.', 'audience' => 'child', 'count' => 5];
        case 'mod_speech_language':
            return ['subject' => 'Speech practice: dialogue completion, real-life situations.', 'audience' => 'child', 'count' => 4];
        case 'mod_behaviour_emotion':
            return ['subject' => 'Social-emotional scenarios. Naming emotions, perspective-taking, impulse control.', 'audience' => 'child', 'count' => 4];
        case 'mod_parenting':
            return ['subject' => 'Parent-facing scenarios: tantrums, screen time, sibling disputes.', 'audience' => 'parent', 'count' => 4];
        case 'mod_family_wellness':
            return ['subject' => 'Family wellness: meals, sleep, screen time, exercise. Indian-context.', 'audience' => 'parent', 'count' => 4];
        case 'mod_developmental':
            return ['subject' => "Developmental milestones for the child's age.", 'audience' => 'parent', 'count' => 4];
        default:
            return ['subject' => 'Practice exercises tied to the skill.', 'audience' => 'child', 'count' => 4];
    }
}
