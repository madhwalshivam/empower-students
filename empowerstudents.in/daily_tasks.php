<?php
/**
 * includes/daily_tasks.php
 *
 * Adaptive practice system. Plan tab uses these helpers to:
 *   1. Pick the next skill the child should work on (mastery-based, not calendar).
 *   2. Hand back a question set — reused from cached pool or freshly generated.
 *   3. Track mastery (>= 80% on 2+ sessions per skill -> advance).
 */

require_once __DIR__ . '/claude.php';

const MASTERY_SCORE_PCT     = 80;
const MASTERY_SESSIONS_REQD = 2;
const POOL_TARGET_SIZE      = 5;

/** Returns one of '5-7' | '8-10' | '11-13' | '14-17'. */
function age_bucket(float $age_yrs): string {
    if ($age_yrs < 8)  return '5-7';
    if ($age_yrs < 11) return '8-10';
    if ($age_yrs < 14) return '11-13';
    return '14-17';
}

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

function is_skill_mastered(int $child_id, string $service_key, string $skill_id): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ? AND skill_id = ?
                           AND status = 'submitted' AND score >= ?");
    $st->execute([$child_id, $service_key, $skill_id, MASTERY_SCORE_PCT]);
    return (int)$st->fetchColumn() >= MASTERY_SESSIONS_REQD;
}

/**
 * Walk the curriculum in order, return the first non-mastered, age-appropriate skill.
 * If all mastered, return the last age-appropriate skill so they can keep practicing.
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

function recent_skill_sessions(int $child_id, string $service_key, string $skill_id, int $limit = 5): array {
    $st = db()->prepare("SELECT score, submitted_at FROM plan_daily_tasks
                         WHERE child_id = ? AND service_key = ? AND skill_id = ?
                           AND status = 'submitted'
                         ORDER BY submitted_at DESC LIMIT ?");
    $st->execute([$child_id, $service_key, $skill_id, $limit]);
    return $st->fetchAll();
}

/** 'easier' | 'standard' | 'harder' from most recent assessment score. */
function compute_difficulty(int $child_id, string $service_key): string {
    $alias_keys = function_exists('legacy_keys_for_catalogue')
        ? legacy_keys_for_catalogue($service_key)
        : [$service_key];
    $ph = implode(',', array_fill(0, count($alias_keys), '?'));
    $st = db()->prepare("SELECT score FROM assessments
                         WHERE child_id = ? AND module IN ($ph) AND status = 'done'
                         ORDER BY id DESC LIMIT 1");
    $st->execute(array_merge([$child_id], $alias_keys));
    $score = $st->fetchColumn();
    if ($score === false || $score === null) return 'standard';
    $score = (float)$score;
    if ($score < 40)  return 'easier';
    if ($score >= 80) return 'harder';
    return 'standard';
}

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
 * Get a question set for (skill, age_bucket, difficulty).
 *   - 'easier'/'harder' difficulty bypasses cache (per spec)
 *   - 'standard' tries pool first; on miss, generates + caches
 */
function get_or_generate_task_set(array $child, array $meta, array $skill, string $difficulty = 'standard'): ?array {
    $service_key = $meta['service_key'];
    $age_yrs     = (float) calc_age_years($child['dob']);
    $bucket      = age_bucket($age_yrs);

    if ($difficulty === 'easier' || $difficulty === 'harder') {
        return generate_skill_tasks($child, $meta, $skill, $difficulty);
    }

    // Pool lookup: pick a row this child hasn't already seen
    $st = db()->prepare("
        SELECT tp.* FROM task_pool tp
        WHERE tp.service_key = ? AND tp.skill_id = ? AND tp.age_bucket = ? AND tp.difficulty = ?
          AND NOT EXISTS (
            SELECT 1 FROM plan_daily_tasks pdt
             WHERE pdt.child_id = ? AND pdt.service_key = tp.service_key
               AND pdt.skill_id = tp.skill_id
               AND pdt.tasks_json = tp.tasks_json
          )
        ORDER BY tp.used_count ASC, RANDOM() LIMIT 1
    ");
    $st->execute([$service_key, $skill['skill_id'], $bucket, $difficulty, (int)$child['id']]);
    $hit = $st->fetch();

    if ($hit) {
        db()->prepare("UPDATE task_pool SET used_count = used_count + 1 WHERE id = ?")->execute([(int)$hit['id']]);
        $tasks = json_decode((string)$hit['tasks_json'], true);
        if (is_array($tasks) && !empty($tasks['questions'])) return $tasks;
    }

    $tasks = generate_skill_tasks($child, $meta, $skill, $difficulty);
    if ($tasks && !empty($tasks['questions'])) {
        try {
            db()->prepare("INSERT INTO task_pool (service_key, skill_id, age_bucket, tasks_json, difficulty, used_count)
                           VALUES (?, ?, ?, ?, ?, 1)")
               ->execute([$service_key, $skill['skill_id'], $bucket,
                          json_encode($tasks, JSON_UNESCAPED_UNICODE), $difficulty]);
        } catch (Throwable $e) { /* don't fail the user just for cache write */ }
    }
    return $tasks;
}

function generate_skill_tasks(array $child, array $meta, array $skill, string $difficulty = 'standard'): ?array {
    $service_key  = $meta['service_key'];
    $module_label = $meta['label'];
    $brief        = daily_tasks_module_brief($service_key);
    $count        = (int)$brief['count'];
    $audience     = $brief['audience'];
    $age_yrs      = round((float)calc_age_years($child['dob']), 1);

    $diff_note = '';
    switch ($difficulty) {
        case 'easier':   $diff_note = "DIFFICULTY: easier than typical for this skill — gentler numbers, simpler wording.";       break;
        case 'harder':   $diff_note = "DIFFICULTY: harder than typical — multi-step thinking, plausible distractors."; break;
        default:         $diff_note = "DIFFICULTY: standard for the skill and age bucket.";
    }

    $sys = "You generate practice multiple-choice questions for an Indian student.\n\n"
         . "Module: {$module_label}\n"
         . "Subject brief: {$brief['subject']}\n"
         . "Audience: {$audience} (write the question and options addressing the {$audience} directly)\n"
         . "{$diff_note}\n"
         . "Tone: warm, encouraging, age-appropriate. Indian context (rupees, common Indian foods, Indian schools).\n\n"
         . "Output JSON with exactly this shape (no markdown, no preamble):\n"
         . "{\n"
         . "  \"intro\": \"1 short sentence framing today's exercise\",\n"
         . "  \"questions\": [\n"
         . "    { \"q\": \"...\", \"options\": [\"A. ...\", \"B. ...\", \"C. ...\", \"D. ...\"], \"answer\": 0, \"explain\": \"why\" }\n"
         . "  ]\n"
         . "}\n\n"
         . "Generate exactly {$count} questions, all on the SAME skill defined below.\n"
         . "'answer' is the 0-indexed correct option. Vary which position is correct across the {$count} questions.\n"
         . "Make questions specific and varied — different scenarios, not the same template repeated.";

    $user = "Child: {$child['name']}, age {$age_yrs} yrs"
          . (!empty($child['gender']) ? ", " . $child['gender'] : '')
          . (!empty($child['mother_tongue']) ? ", mother tongue: " . $child['mother_tongue'] : '')
          . (!empty($child['diagnosis']) ? "\nKnown diagnosis: " . $child['diagnosis'] : '')
          . "\n\nSKILL TO PRACTICE: {$skill['skill_label']}\n"
          . "Skill description: {$skill['skill_brief']}\n\n"
          . "Generate {$count} multiple-choice questions on this skill. Each must be solvable in 1-2 minutes.";

    $j = claude_json($sys, $user, 1800, 0.7);
    if (!$j || !isset($j['questions']) || !is_array($j['questions'])) return null;

    $clean = [];
    foreach ($j['questions'] as $q) {
        if (!is_array($q) || empty($q['q']) || empty($q['options'])) continue;
        $opts = array_values((array)$q['options']);
        if (count($opts) < 2) continue;
        $opts = array_slice($opts, 0, 4);
        $ans  = max(0, min(count($opts) - 1, (int)($q['answer'] ?? 0)));
        $clean[] = [
            'q'       => mb_substr((string)$q['q'], 0, 500),
            'options' => array_map(fn($o) => mb_substr((string)$o, 0, 200), $opts),
            'answer'  => $ans,
            'explain' => mb_substr((string)($q['explain'] ?? ''), 0, 400),
        ];
    }
    if (empty($clean)) return null;

    return [
        'intro'     => mb_substr((string)($j['intro'] ?? ''), 0, 300),
        'questions' => $clean,
    ];
}

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

function score_daily_tasks(array $tasks, array $answers): array {
    $questions = $tasks['questions'] ?? [];
    $total = count($questions);
    if ($total === 0) return ['score' => 0, 'correct' => 0, 'total' => 0];
    $correct = 0;
    foreach ($questions as $i => $q) {
        $picked = isset($answers[$i]) ? (int)$answers[$i] : -1;
        if ($picked === (int)($q['answer'] ?? -1)) $correct++;
    }
    return [
        'score'   => (int) round($correct * 100 / $total),
        'correct' => $correct,
        'total'   => $total,
    ];
}
