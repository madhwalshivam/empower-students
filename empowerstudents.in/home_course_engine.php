<?php
/**
 * includes/home_course_engine.php
 *
 * 7-Day Home Environment Improvement Course — engine.
 *
 * Concept (parent-level course; no child_id link):
 *   1. Parent finishes a Parent Reflection → sees 5-axis Home Climate report → buys course
 *   2. course-start charges wallet, calls home_course_create()
 *      - new course row with weak_axes_json from the reflection's signals + fixed day themes
 *   3. Parent visits /home-course.php?id=N → today's task auto-generated + cached
 *   4. Parent + (spouse / family / alone) do the task (2/5/10 min based on SKU)
 *   5. Parent records ONE voice answer to the SAME anchor question every day:
 *      "How did today feel?" / "आज का दिन कैसा रहा?"
 *   6. Engine computes a sentiment+state snapshot from this recording
 *   7. Reload shows progress chart — 5 axes across days, daily anchor sentiment band
 *
 * 7 fixed day themes (rotate weak axes):
 *   Day 1: Praise practice         (child_climate)
 *   Day 2: Couple connection       (couple_harmony)
 *   Day 3: Discipline reset        (child_climate)
 *   Day 4: Self-care window        (parent_wellbeing)
 *   Day 5: Boundary practice       (joint_family)
 *   Day 6: Reach out               (support_network)
 *   Day 7: Reflection share        (couple_harmony + support_network)
 *
 * Claude generates the SPECIFIC daily task tailored to:
 *   - this parent's weak axes (from Home Climate report)
 *   - daily SKU duration (2/5/10 min)
 *   - the day's theme
 *   - phrases the parent used in their reflection (passed in as context)
 *
 * Daily anchor question (same all 7 days): "How did today feel?"
 *   - Parent records 30-90s voice answer
 *   - Web Speech transcript + acoustic features
 *   - Each day we run a tiny scoring pass:
 *       sentiment 0-100 (rule-based + emotion detection)
 *       energy 0-100 (acoustic features: WPM, volume, speaking rate)
 *       openness 0-100 (length of answer, hesitation patterns)
 *   - Saved per day, rendered as a 3-line chart over 7 days
 *
 * Missed-day rule: STRICT. Miss 2+ days → failed. 1 day grace.
 * Pricing: ₹99 / ₹199 / ₹350 (2 / 5 / 10 min per day).
 *
 * Cost per course: ~₹14 (7 daily task generations × ~₹2/call). Daily anchor
 * scoring is local PHP (no Claude call).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/claude.php';


// ─────────────────────────────────────────────────────────────
// Schema migration (idempotent)
// ─────────────────────────────────────────────────────────────
function _home_course_ensure_schema(): void {
    static $done = false;
    if ($done) return;

    db()->exec("CREATE TABLE IF NOT EXISTS home_courses (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id         INTEGER NOT NULL,
        reflect_session_id INTEGER,                            -- which reflection triggered this
        sku               TEXT NOT NULL,                        -- 'home_course_2min' | '5min' | '10min'
        daily_minutes     INTEGER NOT NULL,                     -- 2 | 5 | 10
        price_paid        INTEGER NOT NULL,                     -- 99 | 199 | 350
        weak_axes_json    TEXT,                                  -- JSON with home climate scores baseline
        anchor_question_en TEXT NOT NULL DEFAULT 'How did today feel — overall?',
        anchor_question_hi TEXT NOT NULL DEFAULT 'आज का दिन कैसा रहा — overall?',
        language          TEXT NOT NULL DEFAULT 'hi',
        status            TEXT NOT NULL DEFAULT 'active',        -- active | completed | failed | abandoned
        started_at        TEXT DEFAULT CURRENT_TIMESTAMP,
        completed_at      TEXT,
        last_active_at    TEXT,
        notes             TEXT
    );");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hc_parent ON home_courses(parent_id, status);");

    db()->exec("CREATE TABLE IF NOT EXISTS home_course_days (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id         INTEGER NOT NULL REFERENCES home_courses(id) ON DELETE CASCADE,
        day_no            INTEGER NOT NULL,                     -- 1..7
        theme_key         TEXT NOT NULL,                         -- 'praise' | 'couple' | etc
        task_title        TEXT,
        task_md           TEXT,                                  -- parent-facing task instructions
        task_target_axis  TEXT,                                  -- couple_harmony / parent_wellbeing / etc
        task_generated_at TEXT,
        recording_path    TEXT,
        transcript        TEXT,
        acoustic_json     TEXT,
        snapshot_json     TEXT,                                  -- sentiment/energy/openness 0-100 + per-axis estimate
        parent_note       TEXT,
        completed_at      TEXT,
        UNIQUE(course_id, day_no)
    );");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hcd ON home_course_days(course_id, day_no);");

    // Seed SKU prices
    try {
        $skus = [
            ['home_course_2min',  '7-Day Home Environment Course — 2 min/day',  99,  'parent', 1],
            ['home_course_5min',  '7-Day Home Environment Course — 5 min/day',  199, 'parent', 1],
            ['home_course_10min', '7-Day Home Environment Course — 10 min/day', 350, 'parent', 1],
        ];
        foreach ($skus as $s) {
            db()->prepare("INSERT OR REPLACE INTO service_prices
                           (service_key, label, price, audience, is_active)
                           VALUES (?, ?, ?, ?, ?)")
               ->execute($s);
        }
    } catch (Throwable $e) {
        error_log('[home_course schema seed] ' . $e->getMessage());
    }

    $done = true;
}


// ─────────────────────────────────────────────────────────────
// Day themes — fixed schedule
// ─────────────────────────────────────────────────────────────
function _home_course_day_themes(): array {
    return [
        1 => [
            'key' => 'praise',
            'name_en' => 'Praise practice',
            'name_hi' => 'तारीफ़ का अभ्यास',
            'target_axis' => 'child_climate',
            'gist_en' => 'Give 3 SPECIFIC praises to your child today (not generic "good boy"). Name exactly what they did.',
            'gist_hi' => 'बच्चे को आज 3 SPECIFIC तारीफ़ें दें (सिर्फ़ "अच्छा बच्चा" नहीं — बल्कि नाम लेकर बताएँ कि क्या किया अच्छा)।',
        ],
        2 => [
            'key' => 'couple',
            'name_en' => 'Couple connection',
            'name_hi' => 'पति-पत्नी का जुड़ाव',
            'target_axis' => 'couple_harmony',
            'gist_en' => 'One uninterrupted 5-minute conversation with your partner — kids asleep, phones away. Not about logistics.',
            'gist_hi' => 'Partner से 5 minute की uninterrupted बातचीत — बच्चे सो रहे हों, phone दूर हो। बच्चे की planning नहीं।',
        ],
        3 => [
            'key' => 'reset',
            'name_en' => 'Discipline reset',
            'name_hi' => 'Discipline का रीसेट',
            'target_axis' => 'child_climate',
            'gist_en' => 'Replace ONE "don\'t" with naming the feeling first. ("I see you\'re tired" before "stop doing that".)',
            'gist_hi' => 'एक "मत करो" को feeling को नाम देने से बदलें। ("मुझे दिख रहा है तुम्हें थकान है" पहले, "मत करो" बाद में।)',
        ],
        4 => [
            'key' => 'self_care',
            'name_en' => 'Self-care window',
            'name_hi' => 'अपने लिए वक़्त',
            'target_axis' => 'parent_wellbeing',
            'gist_en' => 'One 10-minute thing JUST for you — guilt-free. Walk, chai in sun, a book chapter. Not "self-care branding".',
            'gist_hi' => 'सिर्फ़ अपने लिए 10 minute — बिना guilt। Walk, धूप में चाय, किताब। "Self-care" वाला brand नहीं — असली काम।',
        ],
        5 => [
            'key' => 'boundary',
            'name_en' => 'Boundary practice',
            'name_hi' => 'सीमाएँ निभाना',
            'target_axis' => 'joint_family',
            'gist_en' => 'Practice ONE respectful "no" or "we\'ll try our way" upward — to in-laws or family overreach on one specific topic.',
            'gist_hi' => 'एक respectful "नहीं" या "हम अपने तरीक़े से try करते हैं" practice करें — किसी एक topic पर ससुराल या परिवार के overreach को।',
        ],
        6 => [
            'key' => 'reach_out',
            'name_en' => 'Reach out',
            'name_hi' => 'किसी को message करें',
            'target_axis' => 'support_network',
            'gist_en' => 'One WhatsApp message to a friend you haven\'t spoken to in months. No agenda. Just "thinking of you".',
            'gist_hi' => 'किसी ऐसे दोस्त को WhatsApp message जिससे महीनों से बात नहीं हुई। कोई agenda नहीं। बस "तुम्हारी याद आ रही थी"।',
        ],
        7 => [
            'key' => 'share',
            'name_en' => 'Reflection share',
            'name_hi' => 'सप्ताह की झलक',
            'target_axis' => 'couple_harmony',
            'gist_en' => 'Tell your partner ONE thing you appreciated about them this week. Specific. Out loud. Not in a card.',
            'gist_hi' => 'Partner से एक बात कहें जो इस हफ़्ते उनके बारे में अच्छी लगी। Specific। ज़ुबान से। Card में नहीं।',
        ],
    ];
}


// ─────────────────────────────────────────────────────────────
// Course creation
// ─────────────────────────────────────────────────────────────
function home_course_create(int $parent_id, string $sku, ?int $reflect_session_id = null): array {
    _home_course_ensure_schema();
    // Ensure the home_climate columns exist on parent_reflect_sessions before
    // querying them (in case Part A's ALTER didn't run on this DB).
    if (function_exists('_home_climate_ensure_columns')) {
        try { _home_climate_ensure_columns(); } catch (Throwable $_) {}
    }

    $sku_meta = [
        'home_course_2min'  => ['minutes' =>  2, 'price' =>  99],
        'home_course_5min'  => ['minutes' =>  5, 'price' => 199],
        'home_course_10min' => ['minutes' => 10, 'price' => 350],
        /* fresh-v8: single comprehensive SKU @ ₹999 — premium 10-min daily, all 7 days */
        'home_course_999'   => ['minutes' => 10, 'price' => 999],
    ];
    if (!isset($sku_meta[$sku])) {
        return ['ok' => false, 'course_id' => null, 'error' => 'Unknown SKU'];
    }

    // Pull weak axes from the reflection session if provided
    $weak_axes_json = null;
    $language = 'hi';
    if ($reflect_session_id) {
        try {
            $st = db()->prepare("SELECT home_climate_cards_json FROM parent_reflect_sessions
                                  WHERE id = ? AND parent_id = ?");
            $st->execute([$reflect_session_id, $parent_id]);
            $cards_json = $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[home_course_create] cards_json read failed: ' . $e->getMessage());
            $cards_json = null;
        }
        if ($cards_json) {
            $cards = json_decode($cards_json, true);
            if (is_array($cards) && !empty($cards['cards'])) {
                // Extract scores
                $scores = [];
                foreach (['couple_harmony','joint_family','parent_wellbeing','child_climate','support_network'] as $k) {
                    $scores[$k] = (int)($cards['cards'][$k]['score'] ?? 60);
                }
                asort($scores);  // lowest first = weakest first
                $weak_two = array_slice(array_keys($scores), 0, 2);
                $weak_axes_json = json_encode([
                    'weakest_axis' => $weak_two[0] ?? null,
                    'weak_two'     => $weak_two,
                    'baseline'     => $scores,
                ], JSON_UNESCAPED_UNICODE);
                $language = $cards['language'] ?? 'hi';
            }
        }
    }
    // Default baseline if no reflection link
    if (!$weak_axes_json) {
        $weak_axes_json = json_encode([
            'weakest_axis' => 'parent_wellbeing',
            'weak_two'     => ['parent_wellbeing', 'couple_harmony'],
            'baseline'     => [
                'couple_harmony' => 60, 'joint_family' => 60, 'parent_wellbeing' => 50,
                'child_climate' => 70, 'support_network' => 60,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    db()->prepare("INSERT INTO home_courses
                   (parent_id, reflect_session_id, sku, daily_minutes, price_paid,
                    weak_axes_json, language, last_active_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
       ->execute([
           $parent_id, $reflect_session_id, $sku,
           $sku_meta[$sku]['minutes'], $sku_meta[$sku]['price'],
           $weak_axes_json, $language,
       ]);

    return ['ok' => true, 'course_id' => (int) db()->lastInsertId(), 'error' => null];
}


// ─────────────────────────────────────────────────────────────
// Today day calculation + missed-day enforcement
// ─────────────────────────────────────────────────────────────
function home_course_today_day(int $course_id): array {
    $st = db()->prepare("SELECT * FROM home_courses WHERE id = ?");
    $st->execute([$course_id]);
    $course = $st->fetch();
    if (!$course) {
        return ['day_no' => 0, 'status' => 'not_found', 'days_since_last' => 0, 'failed_reason' => 'Course not found'];
    }

    if ($course['status'] === 'completed') {
        return ['day_no' => 7, 'status' => 'completed', 'days_since_last' => 0, 'failed_reason' => null];
    }
    if ($course['status'] === 'failed' || $course['status'] === 'abandoned') {
        return ['day_no' => 0, 'status' => $course['status'], 'days_since_last' => 0,
                'failed_reason' => $course['notes'] ?: 'Course was discontinued'];
    }

    $dst = db()->prepare("SELECT MAX(day_no) AS max_day, MAX(completed_at) AS last_day_at
                          FROM home_course_days
                          WHERE course_id = ? AND completed_at IS NOT NULL");
    $dst->execute([$course_id]);
    $done = $dst->fetch();
    $last_done_day = (int)($done['max_day'] ?? 0);
    $last_day_at   = $done['last_day_at'] ?? $course['started_at'];

    $days_since_last = floor((time() - strtotime((string)$last_day_at . ' UTC')) / 86400);
    if ($days_since_last < 0) $days_since_last = 0;

    if ($last_done_day >= 7) {
        db()->prepare("UPDATE home_courses SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$course_id]);
        return ['day_no' => 7, 'status' => 'completed', 'days_since_last' => 0, 'failed_reason' => null];
    }

    if ($last_done_day === 0) {
        if ($days_since_last >= 7) {
            $reason = 'Course not started within 7 days of purchase.';
            db()->prepare("UPDATE home_courses SET status = 'failed', notes = ? WHERE id = ?")
               ->execute([$reason, $course_id]);
            return ['day_no' => 0, 'status' => 'failed', 'days_since_last' => $days_since_last, 'failed_reason' => $reason];
        }
    } else {
        if ($days_since_last >= 2) {
            $reason = "Missed too many days. Last completed Day {$last_done_day}, {$days_since_last} days ago. Max gap is 1 day.";
            db()->prepare("UPDATE home_courses SET status = 'failed', notes = ? WHERE id = ?")
               ->execute([$reason, $course_id]);
            return ['day_no' => 0, 'status' => 'failed', 'days_since_last' => $days_since_last, 'failed_reason' => $reason];
        }
    }

    return [
        'day_no'          => $last_done_day + 1,
        'status'          => 'active',
        'days_since_last' => (int)$days_since_last,
        'failed_reason'   => null,
    ];
}


// ─────────────────────────────────────────────────────────────
// Daily task generation — Claude, cached
// ─────────────────────────────────────────────────────────────
function home_course_generate_daily_task(int $course_id, int $day_no): array {
    $st = db()->prepare("SELECT * FROM home_courses WHERE id = ?");
    $st->execute([$course_id]);
    $course = $st->fetch();
    if (!$course) return ['ok' => false, 'error' => 'Course not found'];

    // Cache check
    $cst = db()->prepare("SELECT task_md, task_title, task_target_axis, theme_key FROM home_course_days
                          WHERE course_id = ? AND day_no = ?");
    $cst->execute([$course_id, $day_no]);
    $cached = $cst->fetch();
    if ($cached && !empty($cached['task_md'])) {
        return [
            'ok' => true,
            'task_md' => $cached['task_md'],
            'title' => $cached['task_title'] ?? '',
            'target_axis' => $cached['task_target_axis'],
            'theme_key' => $cached['theme_key'],
            'from_cache' => true,
        ];
    }

    $themes = _home_course_day_themes();
    $theme = $themes[$day_no] ?? $themes[1];

    $weak = json_decode($course['weak_axes_json'] ?: '{}', true) ?: [];
    $baseline = $weak['baseline'] ?? [];
    $axis_score = (int)($baseline[$theme['target_axis']] ?? 50);

    $minutes = (int)$course['daily_minutes'];
    $is_hindi = $course['language'] === 'hi';

    // Pull a short slice of the reflection transcript for context
    $convo_snippet = '';
    if (!empty($course['reflect_session_id'])) {
        $tst = db()->prepare("SELECT transcript FROM parent_reflect_turns
                              WHERE session_id = ? AND transcript IS NOT NULL AND transcript != ''
                              ORDER BY turn_no DESC LIMIT 3");
        $tst->execute([(int)$course['reflect_session_id']]);
        $rows = $tst->fetchAll();
        if ($rows) {
            $snippets = [];
            foreach (array_reverse($rows) as $r) {
                $t = trim((string)$r['transcript']);
                if ($t !== '') $snippets[] = mb_substr($t, 0, 300);
            }
            $convo_snippet = implode("\n", $snippets);
        }
    }

    $axis_descriptions = [
        'couple_harmony'   => 'partner relationship — connection, communication, shared moments',
        'joint_family'     => 'extended family dynamics — in-laws, boundaries, intergenerational space',
        'parent_wellbeing' => "the parent's own restoration — rest, identity beyond caregiving",
        'child_climate'    => "the child's emotional climate — how the child feels in the home",
        'support_network'  => 'support beyond the household — friends, peers, outside connection',
    ];

    $theme_gist = $is_hindi ? $theme['gist_hi'] : $theme['gist_en'];
    $theme_name = $is_hindi ? $theme['name_hi'] : $theme['name_en'];

    $sys = "You are a senior family psychologist designing one specific daily home-practice task for a parent. "
         . "This is Day {$day_no} of a 7-day home environment course. Today's theme is fixed: '{$theme_name}'. "
         . "Take {$minutes} minutes EXACTLY. Use the parent's actual situation from their reflection where shown.\n\n"
         . "Output JSON ONLY: { \"title\": \"...\", \"task_md\": \"markdown content with ### sections\" }\n\n"
         . "RULES:\n"
         . "- " . ($is_hindi
              ? "Write the task in warm conversational Hindi (Devanagari, 'आप' respectful). English words for modern concepts are fine."
              : "Write in warm, conversational English.") . "\n"
         . "- The task duration is {$minutes} minutes. Tasks for 2-min are tiny + concrete. Tasks for 10-min have build-up.\n"
         . "- Theme to honor today: \"{$theme_gist}\" — but TAILOR it to this parent's specific situation in the transcript snippet.\n"
         . "- Target axis: {$theme['target_axis']} ({$axis_descriptions[$theme['target_axis']]}).\n"
         . "- Parent's baseline on this axis: {$axis_score}/100. Calibrate difficulty.\n"
         . "- India-aware (joint families, in-law dynamics, log kya kahenge, financial reality, generational beliefs).\n"
         . "- task_md structure: ### What you need (1 line) ### How to do it (3-5 short steps) ### What to notice (2-3 bullets — what to pay attention to in yourself or the family member, NOT what to make the other person do).\n"
         . "- Be HONEST. The task should be doable today, even on a hard day.\n";

    $usr_lines = [
        "Day: {$day_no} of 7",
        "Theme: {$theme_name}",
        "Theme gist: {$theme_gist}",
        "Target axis: {$theme['target_axis']}",
        "Parent's score on this axis (from reflection): {$axis_score}/100",
        "Daily minutes: {$minutes}",
    ];
    if ($convo_snippet !== '') {
        $usr_lines[] = '';
        $usr_lines[] = "Parent's recent reflection snippet (use phrases from this if natural):";
        $usr_lines[] = $convo_snippet;
    }
    $usr_lines[] = '';
    $usr_lines[] = 'Generate the task. JSON only.';
    $usr = implode("\n", $usr_lines);

    $resp = function_exists('claude_chat')
        ? claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1500, 0.6)
        : '';

    $task_title = '';
    $task_md = '';

    $clean = trim((string)$resp);
    if ($clean !== '') {
        if (strpos($clean, '```') !== false) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = preg_replace('/\s*```\s*$/', '', $clean);
            $clean = trim($clean);
        }
        $parsed = json_decode($clean, true);
        if (is_array($parsed)) {
            $task_title = trim((string)($parsed['title'] ?? ''));
            $task_md    = trim((string)($parsed['task_md'] ?? ''));
        }
    }

    // Fallback if Claude failed
    if ($task_md === '') {
        error_log("[home_course_generate_daily_task] Claude failed for course=$course_id day=$day_no");
        $task_title = $theme_name;
        $task_md = ($is_hindi
            ? "### क्या चाहिए\n- {$minutes} minute का शांत समय\n\n### कैसे करें\n1. {$theme_gist}\n2. कुछ ज़बरदस्ती मत करें — जितना natural लगे उतना।\n3. अंत में देखें कि कैसा लगा।\n\n### क्या ध्यान दें\n- अपनी feeling को observe करें\n- किसी और को judge करने से बचें"
            : "### What you need\n- {$minutes} quiet minutes\n\n### How to do it\n1. {$theme_gist}\n2. Don't force it — only as much as feels natural.\n3. Notice how it felt afterwards.\n\n### What to notice\n- Your own feeling\n- Avoid judging anyone else");
    }

    // Cache + upsert
    db()->prepare("INSERT INTO home_course_days
                   (course_id, day_no, theme_key, task_title, task_md, task_target_axis, task_generated_at)
                   VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                   ON CONFLICT(course_id, day_no) DO UPDATE SET
                     theme_key = excluded.theme_key,
                     task_title = excluded.task_title,
                     task_md = excluded.task_md,
                     task_target_axis = excluded.task_target_axis,
                     task_generated_at = excluded.task_generated_at")
       ->execute([
           $course_id, $day_no, $theme['key'], $task_title, $task_md, $theme['target_axis'],
       ]);

    return [
        'ok' => true,
        'task_md' => $task_md,
        'title' => $task_title,
        'target_axis' => $theme['target_axis'],
        'theme_key' => $theme['key'],
        'from_cache' => false,
    ];
}


// ─────────────────────────────────────────────────────────────
// Daily anchor recording — score sentiment + energy + openness (local PHP)
// ─────────────────────────────────────────────────────────────
/**
 * Local rule-based scoring of the parent's anchor-question voice answer.
 * No Claude call — keeps cost zero per day.
 *
 * Returns snapshot:
 *   sentiment: 0-100 (rough emotional polarity from word patterns)
 *   energy:    0-100 (from WPM, volume variance, speaking duration)
 *   openness:  0-100 (length and detail of answer)
 */
function _home_course_score_anchor(string $transcript, array $acoustic, string $lang = 'hi'): array {
    $text = trim($transcript);

    // Word count
    $words = preg_split('/[\s,.\?!।]+/u', $text);
    $words = array_filter($words, function($w) { return mb_strlen(trim($w)) >= 1; });
    $wc = count($words);

    // Openness: scale from answer length
    //   0 words → 0
    //   5 words → 30
    //   20 words → 70
    //   50+ words → 95
    if      ($wc === 0)  $openness = 0;
    elseif  ($wc <= 5)   $openness = (int) round(($wc / 5) * 30);
    elseif  ($wc <= 20)  $openness = 30 + (int) round((($wc - 5) / 15) * 40);
    elseif  ($wc <= 50)  $openness = 70 + (int) round((($wc - 20) / 30) * 25);
    else                 $openness = 95;

    // Energy: from acoustic features
    $wpm = (float)($acoustic['wpm'] ?? 0);
    $volume_var = (float)($acoustic['volume_variance'] ?? 0);
    $pauses = (int)($acoustic['pause_count'] ?? 0);

    // Adult speaker baseline: 110-150 WPM normal, <80 = low energy, >170 = animated
    if      ($wpm <= 0)   $energy_wpm = 30;
    elseif  ($wpm < 60)   $energy_wpm = 25;
    elseif  ($wpm < 90)   $energy_wpm = 45;
    elseif  ($wpm < 130)  $energy_wpm = 70;
    elseif  ($wpm < 170)  $energy_wpm = 80;
    else                  $energy_wpm = 85;

    // Volume variance: more variance = more animated; flat = low energy
    $energy_var = 50;
    if ($volume_var > 0.3) $energy_var = 70;
    elseif ($volume_var > 0.15) $energy_var = 60;
    elseif ($volume_var > 0.05) $energy_var = 55;
    else $energy_var = 40;

    // Pauses penalize energy slightly
    $energy = (int) round(($energy_wpm * 0.7) + ($energy_var * 0.3) - min($pauses, 5) * 2);
    $energy = max(0, min(100, $energy));

    // Sentiment: rule-based word matching (Hindi + English)
    $positive_words = [
        // English
        'good','great','nice','well','better','happy','peaceful','calm','okay','fine','grateful','thank','smile','laugh','easy','light','love','joy','best','great',
        // Hindi (transliterated and devanagari)
        'अच्छा','अच्छी','ठीक','शांति','शांत','खुश','खुशी','मज़ा','मज़े','सुकून','हँसी','हँसा','हँसी','प्यार','धन्यवाद','शुक्रिया','बेहतर',
        'achha','acha','accha','theek','thik','khush','shanti','sukoon','behtar','badhiya','mast',
    ];
    $negative_words = [
        // English
        'tired','exhausted','frustrated','angry','sad','difficult','hard','stressful','worried','anxious','upset','cry','fight','argue','alone','lonely','overwhelmed','depressed','hopeless','crying','fighting',
        // Hindi
        'थक','थका','थकी','थकान','परेशान','गुस्सा','नाराज़','दुख','दुखी','मुश्किल','चिंता','चिंतित','अकेला','अकेली','रोना','रोई','रोया','झगड़ा','लड़ाई','भारी',
        'thak','thaka','thaki','pareshaan','gussa','dukh','mushkil','chinta','akela','akeli','rona','jhagda',
    ];

    $low = mb_strtolower($text);
    $pos_count = 0; $neg_count = 0;
    foreach ($positive_words as $w) {
        if (mb_stripos($low, $w) !== false) $pos_count++;
    }
    foreach ($negative_words as $w) {
        if (mb_stripos($low, $w) !== false) $neg_count++;
    }

    // Sentiment = 50 (neutral) + 10 per positive - 10 per negative, clamped
    $sentiment = 50 + ($pos_count - $neg_count) * 10;
    // If transcript is empty or "no response", default to low
    if ($wc === 0) $sentiment = 30;
    $sentiment = max(5, min(95, $sentiment));

    return [
        'sentiment'    => $sentiment,
        'energy'       => $energy,
        'openness'     => $openness,
        'transcript_words' => $wc,
        'wpm'          => (int) $wpm,
        'pos_signals'  => $pos_count,
        'neg_signals'  => $neg_count,
        'recorded_at'  => gmdate('Y-m-d H:i:s'),
    ];
}


/**
 * Score the daily anchor recording and persist on the day row.
 * Auto-completes course on Day 7.
 */
function home_course_score_daily_recording(int $course_id, int $day_no, ?string $audio_path,
                                            string $transcript, array $acoustic,
                                            string $parent_note = ''): array {
    $st = db()->prepare("SELECT * FROM home_courses WHERE id = ?");
    $st->execute([$course_id]);
    $course = $st->fetch();
    if (!$course) return ['ok' => false, 'error' => 'Course not found'];

    $snapshot = _home_course_score_anchor($transcript, $acoustic, (string)$course['language']);
    $snapshot['day_no'] = $day_no;

    // Pull the day row (it MUST exist already from task generation)
    db()->prepare("INSERT INTO home_course_days
                   (course_id, day_no, theme_key, task_md, task_target_axis,
                    recording_path, transcript, acoustic_json, snapshot_json,
                    parent_note, completed_at)
                   VALUES (?, ?, '', '', '', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                   ON CONFLICT(course_id, day_no) DO UPDATE SET
                     recording_path = excluded.recording_path,
                     transcript = excluded.transcript,
                     acoustic_json = excluded.acoustic_json,
                     snapshot_json = excluded.snapshot_json,
                     parent_note = COALESCE(NULLIF(excluded.parent_note, ''), parent_note),
                     completed_at = CURRENT_TIMESTAMP")
       ->execute([
           $course_id, $day_no, $audio_path, $transcript,
           json_encode($acoustic, JSON_UNESCAPED_UNICODE),
           json_encode($snapshot, JSON_UNESCAPED_UNICODE),
           $parent_note,
       ]);

    db()->prepare("UPDATE home_courses SET last_active_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$course_id]);

    if ($day_no >= 7) {
        db()->prepare("UPDATE home_courses SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$course_id]);
    }

    return ['ok' => true, 'snapshot' => $snapshot];
}


// ─────────────────────────────────────────────────────────────
// Progress data for chart
// ─────────────────────────────────────────────────────────────
function home_course_progress_data(int $course_id): array {
    $st = db()->prepare("SELECT * FROM home_courses WHERE id = ?");
    $st->execute([$course_id]);
    $course = $st->fetch();
    if (!$course) return ['error' => 'Course not found'];

    $today = home_course_today_day($course_id);

    $weak = json_decode($course['weak_axes_json'] ?: '{}', true) ?: [];
    $baseline = $weak['baseline'] ?? [];

    $dst = db()->prepare("SELECT day_no, snapshot_json, theme_key, task_target_axis
                          FROM home_course_days
                          WHERE course_id = ? AND completed_at IS NOT NULL
                          ORDER BY day_no");
    $dst->execute([$course_id]);
    $rows = $dst->fetchAll();

    $days = [];
    $series = ['sentiment' => [], 'energy' => [], 'openness' => []];
    foreach ($rows as $r) {
        $days[] = (int)$r['day_no'];
        $snap = json_decode($r['snapshot_json'] ?: '{}', true) ?: [];
        foreach (array_keys($series) as $k) {
            $series[$k][] = isset($snap[$k]) ? (int)$snap[$k] : null;
        }
    }

    $themes = _home_course_day_themes();

    return [
        'days'            => $days,
        'series'          => $series,
        'baseline'        => $baseline,
        'today_day'       => $today['day_no'],
        'status'          => $today['status'],
        'failed_reason'   => $today['failed_reason'],
        'days_since_last' => $today['days_since_last'],
        'daily_minutes'   => (int)$course['daily_minutes'],
        'price_paid'      => (int)$course['price_paid'],
        'language'        => $course['language'],
        'anchor_question' => $course['language'] === 'hi' ? $course['anchor_question_hi'] : $course['anchor_question_en'],
        'weak_axes'       => $weak['weak_two'] ?? [],
        'themes'          => $themes,
    ];
}


// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function home_course_find_active(int $parent_id): ?array {
    $st = db()->prepare("SELECT * FROM home_courses WHERE parent_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $st->execute([$parent_id]);
    $r = $st->fetch();
    return $r ?: null;
}
