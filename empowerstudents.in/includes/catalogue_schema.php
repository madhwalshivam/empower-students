<?php
/**
 * includes/catalogue_schema.php — modular pay-per-use catalogue
 *
 * Idempotent. Safe to require on every request.
 *
 * Adds, on top of existing service_prices / wallet_ledger / assessments:
 *   • service_meta        — catalogue metadata (group, tier, icon, descriptions)
 *   • module_consults     — log of AI consult questions (per-module Q&A)
 *   • consult_balance     — per-parent consult-pack balance
 *   • module_plans        — AI-generated per-module plan (extends growth_plans)
 *   • module_log_fields   — module-added fields surfaced on the unified tracker
 *
 * Seeds 16 catalogue rows (13 modules + 3 packs).
 *
 * Pricing tiers:
 *   quick    = ₹199   (5–8 questions, report only)
 *   standard = ₹399   (10–15 questions, report + 4-week plan + 3 consults)
 *   deep     = ₹499   (15–20 questions, report + 12-week plan + 5 consults)
 *
 * PHP 7.4 compatible (no match, no nullsafe, no str_contains).
 */
require_once __DIR__ . '/db.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("
    -- ── 1. service_meta — catalogue metadata layer over service_prices ──
    CREATE TABLE IF NOT EXISTS service_meta (
        service_key             TEXT PRIMARY KEY,
        catalogue_group         TEXT,        -- 'special' | 'all' | 'parent' | 'pack' | 'consult'
        tier                    TEXT,        -- 'quick' | 'standard' | 'deep' | 'pack' | 'consult'
        icon                    TEXT,
        short_desc              TEXT,
        short_desc_hi           TEXT,
        long_desc_md            TEXT,
        long_desc_md_hi         TEXT,
        sample_question         TEXT,        -- one free preview question
        sample_question_hi      TEXT,
        age_min                 REAL DEFAULT 0,
        age_max                 REAL DEFAULT 18,
        plan_weeks              INTEGER DEFAULT 0,    -- 0 = no plan, 4 = 4-week, 12 = 12-week
        free_consults_included  INTEGER DEFAULT 0,
        sort_order              INTEGER DEFAULT 100,
        is_catalogue            INTEGER DEFAULT 1,    -- 0 = legacy hidden from catalogue
        bundle_keys             TEXT,                 -- JSON array of service_keys (for packs)
        bundle_discount_pct     INTEGER DEFAULT 0,    -- pack-level discount baked into price
        assessment_ready        INTEGER DEFAULT 1,    -- 0 = no assessment file yet; module hides from catalogue but stays in admin
        updated_at              TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- ── 2. module_consults — every AI consult question logged ──
    CREATE TABLE IF NOT EXISTS module_consults (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id     INTEGER NOT NULL,
        child_id      INTEGER NOT NULL,
        service_key   TEXT NOT NULL,
        question      TEXT NOT NULL,
        answer        TEXT,
        paid_from     TEXT,    -- 'free_included' | 'pack' | 'wallet'
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_mc_parent  ON module_consults(parent_id);
    CREATE INDEX IF NOT EXISTS idx_mc_child   ON module_consults(child_id);
    CREATE INDEX IF NOT EXISTS idx_mc_service ON module_consults(service_key);

    -- ── 3. consult_balance — per-parent pack balance ──
    CREATE TABLE IF NOT EXISTS consult_balance (
        parent_id     INTEGER PRIMARY KEY,
        balance       INTEGER DEFAULT 0,
        updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- ── 4. module_plans — per-module AI plan (4 or 12 weeks) ──
    CREATE TABLE IF NOT EXISTS module_plans (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL,
        service_key   TEXT NOT NULL,
        plan_md       TEXT,
        plan_json     TEXT,
        weeks         INTEGER DEFAULT 4,
        started_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id, service_key)
    );
    CREATE INDEX IF NOT EXISTS idx_mp_child ON module_plans(child_id);

    -- ── 5. module_log_fields — fields each module contributes to the unified tracker ──
    CREATE TABLE IF NOT EXISTS module_log_fields (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key   TEXT NOT NULL,
        field_key     TEXT NOT NULL,           -- e.g. 'speech_unprompted'
        label_en      TEXT NOT NULL,
        label_hi      TEXT,
        field_type    TEXT NOT NULL,           -- 'yesno' | 'likert05' | 'minutes'
        sort_order    INTEGER DEFAULT 100,
        UNIQUE (service_key, field_key)
    );

    -- ── 6. plan_activity_completions — per-day-per-activity Done tracking ──
    --    Each plan has weeks[].daily[2 items]. We index by (week_n, day_idx, item_idx)
    --    where day_idx = 0..6 (Sun..Sat) for the calendar day inside that week,
    --    and item_idx = 0 or 1 (first or second daily activity).
    --    A row presence = done; absence = not yet.
    CREATE TABLE IF NOT EXISTS plan_activity_completions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        service_key   TEXT NOT NULL,
        week_n        INTEGER NOT NULL,        -- 1-based week within the plan
        day_idx       INTEGER NOT NULL,        -- 0..6 within that week
        item_idx      INTEGER NOT NULL,        -- 0 or 1 (which daily activity)
        completed_at  TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id, service_key, week_n, day_idx, item_idx)
    );
    CREATE INDEX IF NOT EXISTS idx_pac_child ON plan_activity_completions(child_id, service_key);

    -- ── 7. plan_daily_tasks — repurposed: each row is a practice session ──
    --    Columns week_n/day_idx kept for backward compat but new sessions use
    --    skill_id (the skill being practiced) + session_seq (1, 2, 3... per child+module).
    CREATE TABLE IF NOT EXISTS plan_daily_tasks (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        service_key   TEXT NOT NULL,
        week_n        INTEGER NOT NULL DEFAULT 0,
        day_idx       INTEGER NOT NULL DEFAULT 0,
        tasks_json    TEXT NOT NULL,           -- AI-generated questions + correct answers
        answers_json  TEXT,                    -- child selections indexed by q-idx
        score         INTEGER,                 -- 0..100, null until submitted
        status        TEXT DEFAULT 'ready',    -- ready | in_progress | submitted
        generated_at  TEXT DEFAULT CURRENT_TIMESTAMP,
        submitted_at  TEXT,
        skill_id      TEXT,
        session_seq   INTEGER DEFAULT 0,
        age_bucket    TEXT,
        session_date  TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_pdt_child ON plan_daily_tasks(child_id, service_key);

    -- ── 8. skill_curriculum — ordered skill ladder per module ──
    CREATE TABLE IF NOT EXISTS skill_curriculum (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key   TEXT NOT NULL,
        skill_id      TEXT NOT NULL,           -- snake_case, e.g. addition_within_10
        skill_label   TEXT NOT NULL,           -- pretty label for UI
        skill_label_hi TEXT,
        skill_brief   TEXT NOT NULL,           -- 1-2 line description for the AI prompt
        age_min       INTEGER DEFAULT 5,       -- minimum recommended age
        age_max       INTEGER DEFAULT 17,
        sort_order    INTEGER NOT NULL,        -- ordering within the module's ladder
        UNIQUE (service_key, skill_id)
    );
    CREATE INDEX IF NOT EXISTS idx_sc_module ON skill_curriculum(service_key, sort_order);

    -- ── 9. task_pool — cached AI-generated question sets keyed by (skill, age_bucket) ──
    --    age_bucket is one of: '5-7' | '8-10' | '11-13' | '14-17'
    CREATE TABLE IF NOT EXISTS task_pool (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key   TEXT NOT NULL,
        skill_id      TEXT NOT NULL,
        age_bucket    TEXT NOT NULL,
        tasks_json    TEXT NOT NULL,
        difficulty    TEXT DEFAULT 'standard', -- 'easier' | 'standard' | 'harder'
        used_count    INTEGER DEFAULT 0,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_tp_lookup ON task_pool(service_key, skill_id, age_bucket, difficulty);
    ");

    // Add module_fields_json column to daily_logs if missing (idempotent)
    try {
        $cols = db()->query("PRAGMA table_info(daily_logs)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('module_fields_json', $names, true)) {
            db()->exec("ALTER TABLE daily_logs ADD COLUMN module_fields_json TEXT");
        }
    } catch (Throwable $e) { /* table doesn't exist yet — that's fine, paid_schema.php creates it */ }

    // Add assessment_ready column to service_meta if missing (idempotent — for upgrades)
    try {
        $cols = db()->query("PRAGMA table_info(service_meta)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('assessment_ready', $names, true)) {
            db()->exec("ALTER TABLE service_meta ADD COLUMN assessment_ready INTEGER DEFAULT 1");
        }
    } catch (Throwable $e) { /* freshly created above — already has the column */ }

    // Add ai_summary_hi column to assessments for cached Hindi translations
    try {
        $cols = db()->query("PRAGMA table_info(assessments)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('ai_summary_hi', $names, true)) {
            db()->exec("ALTER TABLE assessments ADD COLUMN ai_summary_hi TEXT");
        }
    } catch (Throwable $e) { /* assessments table missing — paid_schema.php / db.php handles it */ }

    // Add plan_md_hi column to module_plans for cached Hindi translations of plans
    try {
        $cols = db()->query("PRAGMA table_info(module_plans)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('plan_md_hi', $names, true)) {
            db()->exec("ALTER TABLE module_plans ADD COLUMN plan_md_hi TEXT");
        }
    } catch (Throwable $e) { /* table missing — created above */ }

    // Add adaptive-practice columns to plan_daily_tasks
    try {
        $cols = db()->query("PRAGMA table_info(plan_daily_tasks)")->fetchAll();
        $names = array_column($cols, 'name');
        foreach ([
            'skill_id'    => 'TEXT',
            'session_seq' => 'INTEGER DEFAULT 0',
            'age_bucket'  => 'TEXT',
            'session_date'=> 'TEXT',
        ] as $col => $type) {
            if (!in_array($col, $names, true)) {
                db()->exec("ALTER TABLE plan_daily_tasks ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Throwable $e) { /* table missing */ }

    // Migrate plan_daily_tasks: drop the old (child_id, service_key, week_n, day_idx)
    // UNIQUE constraint that prevented multiple sessions per child+module.
    // SQLite can't ALTER constraints, so we recreate the table when the old constraint exists.
    try {
        $sql = (string) db()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='plan_daily_tasks'")->fetchColumn();
        if (strpos($sql, 'UNIQUE (child_id, service_key, week_n, day_idx)') !== false) {
            // Disable FK checks during the migration (we're moving identical data 1:1).
            // Per SQLite docs, foreign_keys must be set OUTSIDE a transaction.
            db()->exec("PRAGMA foreign_keys = OFF");
            db()->exec("BEGIN");
            db()->exec("CREATE TABLE plan_daily_tasks_new (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
                service_key   TEXT NOT NULL,
                week_n        INTEGER NOT NULL DEFAULT 0,
                day_idx       INTEGER NOT NULL DEFAULT 0,
                tasks_json    TEXT NOT NULL,
                answers_json  TEXT,
                score         INTEGER,
                status        TEXT DEFAULT 'ready',
                generated_at  TEXT DEFAULT CURRENT_TIMESTAMP,
                submitted_at  TEXT,
                skill_id      TEXT,
                session_seq   INTEGER DEFAULT 0,
                age_bucket    TEXT,
                session_date  TEXT
            )");
            db()->exec("INSERT INTO plan_daily_tasks_new
                        (id, child_id, service_key, week_n, day_idx, tasks_json, answers_json,
                         score, status, generated_at, submitted_at, skill_id, session_seq,
                         age_bucket, session_date)
                        SELECT id, child_id, service_key, week_n, day_idx, tasks_json, answers_json,
                               score, status, generated_at, submitted_at, skill_id, session_seq,
                               age_bucket, session_date
                          FROM plan_daily_tasks");
            db()->exec("DROP TABLE plan_daily_tasks");
            db()->exec("ALTER TABLE plan_daily_tasks_new RENAME TO plan_daily_tasks");
            db()->exec("CREATE INDEX IF NOT EXISTS idx_pdt_child ON plan_daily_tasks(child_id, service_key)");
            db()->exec("COMMIT");
            db()->exec("PRAGMA foreign_keys = ON");
        }
    } catch (Throwable $e) {
        error_log('[migrate plan_daily_tasks] ' . $e->getMessage());
        try { db()->exec("ROLLBACK"); } catch (Throwable $e2) {}
        try { db()->exec("PRAGMA foreign_keys = ON"); } catch (Throwable $e2) {}
    }

    // Practice questions — each question is its own row (replaces the batched tasks_json model).
    // Created when AI generates a question, updated when child answers.
    db()->exec("CREATE TABLE IF NOT EXISTS practice_questions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id    INTEGER NOT NULL REFERENCES plan_daily_tasks(id) ON DELETE CASCADE,
        seq           INTEGER NOT NULL,
        skill_id      TEXT NOT NULL,
        level_offset  INTEGER NOT NULL DEFAULT 0,
        difficulty    TEXT NOT NULL DEFAULT 'standard',
        q_text        TEXT NOT NULL,
        options_json  TEXT NOT NULL,
        correct_idx   INTEGER NOT NULL,
        explain       TEXT,
        picked_idx    INTEGER,
        is_correct    INTEGER,
        time_seconds  INTEGER,
        was_comfortable INTEGER,
        tests_trick   INTEGER DEFAULT 0,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        answered_at   TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_pq_session ON practice_questions(session_id, seq)");

    // Extend plan_daily_tasks (now treated as session header) with adaptive-learning columns
    try {
        $cols = db()->query("PRAGMA table_info(plan_daily_tasks)")->fetchAll();
        $names = array_column($cols, 'name');
        foreach ([
            'target_skill_id'      => 'TEXT',
            'current_level_offset' => 'INTEGER DEFAULT 0',
            'current_difficulty'   => "TEXT DEFAULT 'standard'",
            'comfortable_streak'   => 'INTEGER DEFAULT 0',
            'questions_answered'   => 'INTEGER DEFAULT 0',
            'questions_correct'    => 'INTEGER DEFAULT 0',
            'ended_at'             => 'TEXT',
            'end_reason'           => 'TEXT',
            'trick_md'             => 'TEXT',
            'trick_from_prev_id'   => 'INTEGER',
        ] as $col => $type) {
            if (!in_array($col, $names, true)) {
                db()->exec("ALTER TABLE plan_daily_tasks ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Throwable $e) { /* table missing */ }

    // Cache pool for individual questions (not batches) keyed by skill+difficulty+age_bucket.
    // Used to deduplicate AI calls — when a 10yo asks for an addition_within_10/standard
    // question, we serve a previously-generated one from the pool that this child hasn't seen.
    db()->exec("CREATE TABLE IF NOT EXISTS question_pool (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key   TEXT NOT NULL,
        skill_id      TEXT NOT NULL,
        difficulty    TEXT NOT NULL,
        age_bucket    TEXT NOT NULL,
        q_text        TEXT NOT NULL,
        options_json  TEXT NOT NULL,
        correct_idx   INTEGER NOT NULL,
        explain       TEXT,
        used_count    INTEGER DEFAULT 0,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_qp_lookup ON question_pool(service_key, skill_id, difficulty, age_bucket)");

    // ─────────────────────────────────────────────────────────────
    // Seed catalogue rows (insert-or-replace into service_prices,
    // insert-or-ignore into service_meta so admin edits survive).
    // ─────────────────────────────────────────────────────────────

    // Pricing tier defaults
    $TIER_QUICK    = 199;
    $TIER_STANDARD = 399;
    $TIER_DEEP     = 499;

    $catalogue = [

        // ── SPECIAL CHILDREN (clinical-leaning) ──
        'mod_sensory_motor' => [
            'price' => $TIER_DEEP, 'label' => 'Sensory & Motor Skills',
            'group' => 'special', 'tier' => 'deep', 'icon' => '✋',
            'short' => 'Fine-motor, gross-motor, sensory integration screening.',
            'short_hi' => 'फ़ाइन-मोटर, ग्रॉस-मोटर और सेंसरी इंटीग्रेशन की जाँच।',
            'sample' => 'Does your child often bump into things or seem clumsy in everyday movement?',
            'sample_hi' => 'क्या आपका बच्चा अक्सर चीज़ों से टकराता है या रोज़मर्रा की हरकतों में अनाड़ी लगता है?',
            'age_min' => 1.5, 'age_max' => 14, 'plan_weeks' => 12, 'consults' => 5, 'sort' => 10,
        ],
        'mod_speech_language' => [
            'price' => $TIER_DEEP, 'label' => 'Speech & Language Development',
            'group' => 'special', 'tier' => 'deep', 'icon' => '💬',
            'short' => 'Read-aloud + open speech. AI scores fluency, articulation, expression.',
            'short_hi' => 'ज़ोर से पढ़ना और खुली बातचीत। AI fluency, articulation, expression को आंकता है।',
            'sample' => 'When your child speaks, do strangers usually understand them clearly?',
            'sample_hi' => 'जब आपका बच्चा बोलता है, तो क्या अजनबी आमतौर पर उसे साफ़ समझ पाते हैं?',
            'age_min' => 3, 'age_max' => 16, 'plan_weeks' => 12, 'consults' => 5, 'sort' => 20,
        ],
        'mod_behaviour_emotion' => [
            'price' => $TIER_DEEP, 'label' => 'Behavioural & Emotional Health',
            'group' => 'special', 'tier' => 'deep', 'icon' => '🧩',
            'short' => 'Age-appropriate behaviour + emotional regulation patterns.',
            'short_hi' => 'उम्र के हिसाब से व्यवहार और भावनाओं पर नियंत्रण के पैटर्न।',
            'sample' => 'How often does your child have meltdowns or tantrums that feel out of proportion?',
            'sample_hi' => 'आपके बच्चे को कितनी बार ऐसा गुस्सा या रोना आता है जो स्थिति से बड़ा लगे?',
            'age_min' => 1.5, 'age_max' => 16, 'plan_weeks' => 12, 'consults' => 5, 'sort' => 30,
        ],
        'mod_developmental' => [
            'price' => $TIER_DEEP, 'label' => 'Developmental Delays Screening',
            'group' => 'special', 'tier' => 'deep', 'icon' => '💡',
            'short' => 'Milestone-based screening. M-CHAT-inspired for under-2.',
            'short_hi' => 'माइलस्टोन आधारित जाँच। 2 साल से कम के लिए M-CHAT पर आधारित।',
            'sample' => 'Has your child reached the typical milestones for their age (sitting, walking, words)?',
            'sample_hi' => 'क्या आपके बच्चे ने अपनी उम्र के हिसाब के सामान्य माइलस्टोन (बैठना, चलना, शब्द) हासिल किए हैं?',
            'age_min' => 0, 'age_max' => 6, 'plan_weeks' => 12, 'consults' => 5, 'sort' => 40,
        ],
        'mod_learning_diff' => [
            'price' => $TIER_DEEP, 'label' => 'Learning Difficulties (Dyslexia / Dyscalculia / ADHD)',
            'group' => 'special', 'tier' => 'deep', 'icon' => '🎓',
            'short' => 'Clinical screening for SLD and ADHD-leaning patterns.',
            'short_hi' => 'पढ़ाई की दिक़्क़त (SLD) और ADHD जैसे पैटर्न की जाँच।',
            'sample' => 'Does your child reverse letters/numbers, or lose attention very quickly during schoolwork?',
            'sample_hi' => 'क्या आपका बच्चा अक्षर/अंक उलट देता है या स्कूल के काम में बहुत जल्दी ध्यान खो देता है?',
            'age_min' => 5, 'age_max' => 16, 'plan_weeks' => 12, 'consults' => 5, 'sort' => 50,
        ],

        // ── ALL CHILDREN (academic + growth) ──
        'mod_math' => [
            'price' => $TIER_STANDARD, 'label' => 'Math Level & Talent',
            'group' => 'all', 'tier' => 'standard', 'icon' => '🔢',
            'short' => 'Adaptive base-level finder. Spots gaps and talent.',
            'short_hi' => 'बच्चे का असली maths level और छिपी प्रतिभा।',
            'sample' => 'Does your child enjoy number games, puzzles, or mental math?',
            'sample_hi' => 'क्या आपके बच्चे को संख्याओं वाले खेल, पहेलियाँ या मन के गणित में मज़ा आता है?',
            'age_min' => 4, 'age_max' => 16, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 60,
        ],
        'mod_language' => [
            'price' => $TIER_STANDARD, 'label' => 'Language & Reading (English + Hindi)',
            'group' => 'all', 'tier' => 'standard', 'icon' => '📚',
            'short' => 'Word-power + timed comprehension. Both English and Hindi.',
            'short_hi' => 'शब्द-शक्ति और समयबद्ध पठन। English और Hindi दोनों में।',
            'sample' => 'How comfortable is your child reading a paragraph aloud at their grade level?',
            'sample_hi' => 'अपनी कक्षा का कोई पैराग्राफ़ ज़ोर से पढ़ने में आपका बच्चा कितना सहज है?',
            'age_min' => 5, 'age_max' => 16, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 70,
        ],
        'mod_general_awareness' => [
            'price' => $TIER_STANDARD, 'label' => 'General Awareness',
            'group' => 'all', 'tier' => 'standard', 'icon' => '🌍',
            'short' => 'Adaptive 2-min quiz. World, India, science, sport.',
            'short_hi' => 'अनुकूलनशील 2-मिनट क्विज़। विश्व, भारत, विज्ञान, खेल।',
            'sample' => 'Does your child ask curious questions about the world (why, how, what if)?',
            'sample_hi' => 'क्या आपका बच्चा दुनिया के बारे में जिज्ञासु सवाल पूछता है (क्यों, कैसे, अगर)?',
            'age_min' => 4, 'age_max' => 16, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 80,
        ],
        'mod_mind_power' => [
            'price' => $TIER_STANDARD, 'label' => 'Mind Power, Memory & Focus',
            'group' => 'all', 'tier' => 'standard', 'icon' => '🧠',
            'short' => 'Working memory, attention span, problem-solving.',
            'short_hi' => 'वर्किंग मेमोरी, ध्यान और समस्या-सुलझाने की क्षमता।',
            'sample' => 'Can your child follow a 3-step instruction without forgetting any step?',
            'sample_hi' => 'क्या आपका बच्चा बिना कोई कदम भूले 3-स्टेप निर्देश पूरा कर सकता है?',
            'age_min' => 4, 'age_max' => 16, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 90,
        ],
        'mod_special_talent' => [
            'price' => $TIER_STANDARD, 'label' => 'Special Talent & Aptitude Discovery',
            'group' => 'all', 'tier' => 'standard', 'icon' => '⭐',
            'short' => 'Spot the gift to nurture — multiple-intelligence map.',
            'short_hi' => 'जिस प्रतिभा को निखारना है उसे पहचानें — multiple-intelligence map।',
            'sample' => 'In what one activity does your child lose track of time and seem genuinely happy?',
            'sample_hi' => 'किस एक गतिविधि में आपका बच्चा समय भूल जाता है और सच में खुश दिखता है?',
            'age_min' => 3, 'age_max' => 16, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 100,
        ],
        'mod_career' => [
            'price' => $TIER_STANDARD, 'label' => 'Career & Future Readiness',
            'group' => 'all', 'tier' => 'standard', 'icon' => '🧭',
            'short' => 'Teen-only. Aptitude + interest mapping → realistic career paths.',
            'short_hi' => 'सिर्फ़ teens के लिए। योग्यता और रुचि का मिलान → वास्तविक career रास्ते।',
            'sample' => 'Has your teen ever talked about what they want to do after school?',
            'sample_hi' => 'क्या आपके teen ने कभी बताया है कि वे स्कूल के बाद क्या करना चाहते हैं?',
            'age_min' => 13, 'age_max' => 18, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 110,
        ],

        // ── PARENT TRACK ──
        'mod_parenting' => [
            'price' => $TIER_STANDARD, 'label' => 'Parenting Guidance',
            'group' => 'parent', 'tier' => 'standard', 'icon' => '👪',
            'short' => 'Your parenting style + age-appropriate strategies for THIS child.',
            'short_hi' => 'आपकी पैरेंटिंग शैली + इस बच्चे के लिए उम्र के हिसाब की रणनीतियाँ।',
            'sample' => 'When your child makes a mistake, can they come to you without fear?',
            'sample_hi' => 'जब आपका बच्चा ग़लती करता है, तो क्या वह बिना डर के आपके पास आ सकता है?',
            'age_min' => 0, 'age_max' => 18, 'plan_weeks' => 4, 'consults' => 3, 'sort' => 200,
        ],
        'mod_daily_practices' => [
            'price' => $TIER_QUICK, 'label' => 'Daily Practices (5-min Habits)',
            'group' => 'parent', 'tier' => 'quick', 'icon' => '🌱',
            'short' => 'A pocket guide of 5-minute daily habits, age-tuned.',
            'short_hi' => '5-मिनट की रोज़ की आदतों की pocket guide, उम्र के हिसाब से।',
            'sample' => 'Do you have a single 5-minute ritual you do with your child every day?',
            'sample_hi' => 'क्या एक भी 5-मिनट की चीज़ है जो आप अपने बच्चे के साथ हर रोज़ करते हैं?',
            'age_min' => 0, 'age_max' => 18, 'plan_weeks' => 4, 'consults' => 1, 'sort' => 210,
        ],
        'mod_parent_stress' => [
            'price' => $TIER_QUICK, 'label' => 'Stress Reduction (For You)',
            'group' => 'parent', 'tier' => 'quick', 'icon' => '🌿',
            'short' => 'Most parental stress traces to child-related anxiety. We address that root.',
            'short_hi' => 'पैरेंट का अधिकांश तनाव बच्चे से जुड़ी चिंता से आता है। हम जड़ पर काम करते हैं।',
            'sample' => 'In the last week, has worry about your child kept you awake at night?',
            'sample_hi' => 'पिछले हफ़्ते क्या बच्चे की चिंता ने आपकी नींद उड़ाई है?',
            'age_min' => 0, 'age_max' => 18, 'plan_weeks' => 4, 'consults' => 1, 'sort' => 220,
        ],
        'mod_family_wellness' => [
            'price' => $TIER_QUICK, 'label' => 'Family Wellness & Habits',
            'group' => 'parent', 'tier' => 'quick', 'icon' => '🥗',
            'short' => 'Diet, sleep, screen-time — practical, Indian-context guidance.',
            'short_hi' => 'खाना, नींद, स्क्रीन-टाइम — व्यावहारिक, भारतीय संदर्भ में।',
            'sample' => 'Does your family eat at least one meal a day together at the table?',
            'sample_hi' => 'क्या आपका परिवार दिन में कम-से-कम एक खाना मेज़ पर साथ खाता है?',
            'age_min' => 0, 'age_max' => 18, 'plan_weeks' => 4, 'consults' => 1, 'sort' => 230,
        ],
    ];

    // Bundles (priced via service_prices, metadata in service_meta with bundle_keys)
    $packs = [
        'pack_starter' => [
            'price' => 499, 'label' => 'Starter Pack — Pick 1 Module + 30-day Tracker',
            'group' => 'pack', 'tier' => 'pack', 'icon' => '🌟',
            'short' => 'Perfect first step. Choose any 1 Standard module + 30-day daily tracker. Save up to ₹50.',
            'short_hi' => 'पहला क़दम। कोई भी 1 Standard module + 30-दिन का tracker चुनें। ₹50 तक की बचत।',
            'bundle_keys' => '"choice"',  // special token: parent picks at checkout
            'discount' => 0, 'sort' => 5,
        ],
        'pack_special' => [
            'price' => 1299, 'label' => 'Special Children Pack',
            'group' => 'pack', 'tier' => 'pack', 'icon' => '🩺',
            'short' => 'All 5 clinical-leaning modules at 35% off. ₹2,495 → ₹1,299.',
            'short_hi' => 'सभी 5 क्लिनिकल मॉड्यूल 35% छूट के साथ। ₹2,495 → ₹1,299।',
            'bundle_keys' => '["mod_sensory_motor","mod_speech_language","mod_behaviour_emotion","mod_developmental","mod_learning_diff"]',
            'discount' => 35, 'sort' => 6,
        ],
        'pack_allround' => [
            'price' => 1099, 'label' => 'All-Round Academic Pack',
            'group' => 'pack', 'tier' => 'pack', 'icon' => '📚',
            'short' => '4 academic modules at 30% off. ₹1,596 → ₹1,099.',
            'short_hi' => '4 academic modules 30% छूट पर। ₹1,596 → ₹1,099।',
            'bundle_keys' => '["mod_math","mod_language","mod_general_awareness","mod_mind_power"]',
            'discount' => 30, 'sort' => 7,
        ],
        'pack_parent' => [
            'price' => 599, 'label' => 'Parent Track Pack',
            'group' => 'pack', 'tier' => 'pack', 'icon' => '👨‍👩‍👧',
            'short' => 'All 4 parent-facing modules at 30% off. ₹996 → ₹599.',
            'short_hi' => 'सभी 4 parent मॉड्यूल 30% छूट पर। ₹996 → ₹599।',
            'bundle_keys' => '["mod_parenting","mod_daily_practices","mod_parent_stress","mod_family_wellness"]',
            'discount' => 30, 'sort' => 8,
        ],
    ];

    // Consult packs (priced as services; admin can edit)
    $consult_packs = [
        'consult_pack_5'  => ['price' => 199, 'label' => '5 AI consult questions',  'sort' => 300],
        'consult_pack_15' => ['price' => 499, 'label' => '15 AI consult questions', 'sort' => 301],
    ];

    // Tracker top-up — already exists in paid_schema.php at ₹149.
    // We just add metadata for catalogue display.

    $svc_exists = (bool) db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='service_prices'")->fetchColumn();
    if (!$svc_exists) return;

    $sp_insert = db()->prepare("INSERT OR IGNORE INTO service_prices (service_key, label, price, audience, is_active) VALUES (?, ?, ?, 'parent', 1)");
    $sm_insert = db()->prepare("INSERT OR IGNORE INTO service_meta
        (service_key, catalogue_group, tier, icon, short_desc, short_desc_hi,
         sample_question, sample_question_hi, age_min, age_max, plan_weeks,
         free_consults_included, sort_order, is_catalogue, bundle_keys, bundle_discount_pct)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");

    foreach ($catalogue as $key => $m) {
        $sp_insert->execute([$key, $m['label'], (int)$m['price']]);
        $sm_insert->execute([
            $key, $m['group'], $m['tier'], $m['icon'],
            $m['short'], $m['short_hi'] ?? null,
            $m['sample'] ?? null, $m['sample_hi'] ?? null,
            (float)($m['age_min'] ?? 0), (float)($m['age_max'] ?? 18),
            (int)($m['plan_weeks'] ?? 0),
            (int)($m['consults'] ?? 0),
            (int)($m['sort'] ?? 100),
            null, 0,
        ]);
    }
    foreach ($packs as $key => $m) {
        $sp_insert->execute([$key, $m['label'], (int)$m['price']]);
        $sm_insert->execute([
            $key, $m['group'], $m['tier'], $m['icon'],
            $m['short'], $m['short_hi'] ?? null,
            null, null, 0, 18, 0, 0,
            (int)($m['sort'] ?? 5),
            $m['bundle_keys'], (int)$m['discount'],
        ]);
    }
    foreach ($consult_packs as $key => $m) {
        $sp_insert->execute([$key, $m['label'], (int)$m['price']]);
        $sm_insert->execute([
            $key, 'consult', 'consult', '💡',
            $m['label'], null, null, null, 0, 18, 0, 0,
            (int)$m['sort'], null, 0,
        ]);
    }

    // ── Mark legacy module entries (health, speech, behavior, etc.) as
    // hidden from the catalogue but DO NOT change their prices automatically.
    // Admin uses /admin/catalogue.php to toggle, or runs the grandfather migration.
    $legacy = ['health','mind_power','emotions','behavior','special_talent','parent_index',
               'general_awareness','math','language','speech','spontaneous','diet','pulse_check'];
    $legacy_placeholders = implode(',', array_fill(0, count($legacy), '?'));
    db()->prepare("INSERT OR IGNORE INTO service_meta (service_key, is_catalogue, sort_order) VALUES " .
                  implode(',', array_fill(0, count($legacy), '(?, 0, 999)')))
        ->execute($legacy);

    // ── Mark catalogue modules with no assessment file yet ──
    // These are valid purchases (parents get plan + AI consults) but the
    // dedicated assessment file isn't built. Hidden from /catalogue.php
    // until ready. Admin can flip them on per row in /admin/catalogue.php.
    $partial = ['mod_sensory_motor', 'mod_learning_diff', 'mod_career',
                'mod_daily_practices', 'mod_parent_stress'];
    $upd = db()->prepare("UPDATE service_meta SET assessment_ready = 0 WHERE service_key = ? AND assessment_ready IS NULL");
    foreach ($partial as $k) { $upd->execute([$k]); }
    // Defensive second pass — for fresh installs where assessment_ready defaults to 1
    // we still need to flip these specific keys to 0. Only on first seed (when no
    // wallet sales exist) so we don't override an admin who manually flipped one on.
    $sales_yet = (int) db()->query("SELECT COUNT(*) FROM wallet_ledger WHERE service_key LIKE 'mod_%' AND amount < 0")->fetchColumn();
    if ($sales_yet === 0) {
        $upd2 = db()->prepare("UPDATE service_meta SET assessment_ready = 0 WHERE service_key = ?");
        foreach ($partial as $k) { $upd2->execute([$k]); }
    }

    // ── Seed module log fields (idempotent on UNIQUE service_key+field_key) ──
    $log_fields = [
        // service_key, field_key, label_en, label_hi, type, sort
        ['mod_speech_language', 'speech_unprompted', 'Spoke unprompted today',  'आज ख़ुद से बोला',     'yesno',    10],
        ['mod_speech_language', 'speech_practice',   'Speech practice (mins)',  'speech अभ्यास (मिनट)', 'minutes',  20],
        ['mod_math',            'math_practice',     'Math practice (mins)',    'maths अभ्यास (मिनट)', 'minutes',  10],
        ['mod_language',        'reading_minutes',   'Reading time (mins)',     'पढ़ने का समय (मिनट)',  'minutes',  10],
        ['mod_behaviour_emotion','meltdowns',        'Meltdowns today',         'आज के meltdowns',     'likert05', 10],
        ['mod_behaviour_emotion','calm_minutes',     'Calm-down practice (mins)','शांत होने का अभ्यास (मिनट)','minutes', 20],
        ['mod_sensory_motor',   'motor_practice',    'Motor practice (mins)',   'motor अभ्यास (मिनट)', 'minutes',  10],
        ['mod_mind_power',      'focus_minutes',     'Focused work (mins)',     'एकाग्र काम (मिनट)',   'minutes',  10],
        ['mod_parent_stress',   'parent_calm',       'I felt calm today',       'मैं आज शांत महसूस कर रहा/रही था/थी', 'likert05', 10],
        ['mod_daily_practices', 'ritual_done',       '5-min ritual done',       '5-मिनट का ritual हुआ', 'yesno',    10],
        ['mod_family_wellness', 'family_meal',       'Family meal together',    'परिवार ने साथ खाना खाया','yesno', 10],
    ];
    $lf_insert = db()->prepare("INSERT OR IGNORE INTO module_log_fields (service_key, field_key, label_en, label_hi, field_type, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($log_fields as $f) { $lf_insert->execute($f); }

    // ── Seed skill curriculum for mod_math (~25 skills, ordered) ──
    // Each entry: [service_key, skill_id, skill_label, skill_label_hi, skill_brief, age_min, age_max, sort]
    $math_curriculum = [
        ['mod_math','count_to_10',          'Counting to 10',                   '10 तक गिनती',                      'Counting forward and backward up to 10, identifying numerals.', 5, 8, 10],
        ['mod_math','count_to_100',         'Counting to 100',                  '100 तक गिनती',                     'Counting up to 100, skip-counting by 2s, 5s, 10s.', 5, 9, 20],
        ['mod_math','number_compare',       'Comparing numbers',                'संख्या तुलना',                      'Greater than, less than, equal — up to 3-digit numbers.', 6, 10, 30],
        ['mod_math','addition_within_10',   'Addition within 10',               '10 तक जोड़',                        'Single-digit addition where the sum is ≤ 10.', 5, 9, 40],
        ['mod_math','addition_within_20',   'Addition within 20',               '20 तक जोड़',                        'Single and 2-digit addition with sums up to 20, including with regrouping.', 6, 10, 50],
        ['mod_math','subtraction_within_10','Subtraction within 10',            '10 तक घटाव',                        'Subtracting single digits where the result is ≥ 0.', 5, 9, 60],
        ['mod_math','subtraction_within_20','Subtraction within 20',            '20 तक घटाव',                        'Subtraction up to 20, including borrowing.', 6, 10, 70],
        ['mod_math','place_value',          'Place value (tens & ones)',        'स्थानीय मान',                        'Identifying tens and ones digits, expanded form.', 6, 10, 80],
        ['mod_math','multi_digit_add',      'Multi-digit addition',             'बहु-अंक जोड़',                       'Addition of 2-3 digit numbers with carrying.', 7, 11, 90],
        ['mod_math','multi_digit_sub',      'Multi-digit subtraction',          'बहु-अंक घटाव',                       'Subtraction of 2-3 digit numbers with borrowing.', 7, 11, 100],
        ['mod_math','mult_tables_2_5',      'Multiplication tables 2 & 5',      'पहाड़े 2 और 5',                       'Multiplication facts for 2 and 5 times tables.', 7, 11, 110],
        ['mod_math','mult_tables_3_4',      'Multiplication tables 3 & 4',      'पहाड़े 3 और 4',                       'Multiplication facts for 3 and 4 times tables.', 7, 11, 120],
        ['mod_math','mult_tables_6_9',      'Multiplication tables 6 to 9',     'पहाड़े 6 से 9',                       'Multiplication facts 6, 7, 8, 9 times tables.', 8, 12, 130],
        ['mod_math','division_basic',       'Basic division',                   'भाग की मूल बातें',                  'Division as inverse of multiplication, simple problems.', 8, 12, 140],
        ['mod_math','word_problems_basic',  'Basic word problems',              'शब्द-समस्या (आधार)',                 'One-step word problems involving +, −, ×, ÷.', 7, 12, 150],
        ['mod_math','fractions_intro',      'Introduction to fractions',        'भिन्न का परिचय',                     'Recognising halves, quarters, thirds; reading fraction notation.', 8, 12, 160],
        ['mod_math','fractions_compare',    'Comparing fractions',              'भिन्नों की तुलना',                   'Comparing fractions with same or different denominators.', 9, 13, 170],
        ['mod_math','fractions_add_sub',    'Adding/subtracting fractions',     'भिन्नों का जोड़/घटाव',               'Adding and subtracting fractions, finding common denominators.', 10, 14, 180],
        ['mod_math','decimals_intro',       'Decimals — basic',                 'दशमलव (आधार)',                       'Reading and writing decimals to hundredths, comparing decimals.', 9, 13, 190],
        ['mod_math','percentages_basic',    'Percentages — basic',              'प्रतिशत (आधार)',                     'Understanding %, finding 10%/25%/50% of a number.', 10, 14, 200],
        ['mod_math','word_problems_money',  'Money & shopping problems',        'पैसे और खरीदारी',                    'Word problems involving rupees, change, discounts.', 9, 14, 210],
        ['mod_math','time_and_clock',       'Time and clocks',                  'समय और घड़ी',                        'Reading analog clocks, calculating elapsed time.', 7, 12, 220],
        ['mod_math','geometry_shapes',      'Shapes and angles',                'आकार और कोण',                        'Identifying 2D/3D shapes, measuring angles, area/perimeter basics.', 8, 13, 230],
        ['mod_math','ratios_proportions',   'Ratios and proportions',           'अनुपात और समानुपात',                 'Setting up and solving ratio problems.', 11, 15, 240],
        ['mod_math','algebra_intro',        'Introduction to algebra',          'बीजगणित का परिचय',                   'Solving simple equations with one variable.', 11, 16, 250],
        ['mod_math','word_problems_advanced','Multi-step word problems',        'बहु-चरण शब्द-समस्या',                'Word problems requiring 2+ operations.', 11, 17, 260],
    ];
    $sc_insert = db()->prepare("INSERT OR IGNORE INTO skill_curriculum (service_key, skill_id, skill_label, skill_label_hi, skill_brief, age_min, age_max, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($math_curriculum as $row) { $sc_insert->execute($row); }
})();
