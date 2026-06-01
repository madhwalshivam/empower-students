<?php
/**
 * includes/eval_schema.php
 *
 * Schema for the adaptive evaluation system. Pilot scope: speech evaluation
 * (mod_speech_basic), but the tables are generic across all 13 cards.
 *
 * Idempotent — safe to require_once on every request.
 *
 * Pricing model:
 *   - Each parent gets ONE free evaluation across all cards (first-time).
 *   - Subsequent evaluations: ₹59 each (wallet charge).
 *   - Free flag tracked on parents.free_eval_used_at.
 */

(function () {

    // Add free-eval flag + timestamp to existing parents table (idempotent)
    try {
        $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('free_eval_used_at', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN free_eval_used_at TEXT");
        }
    } catch (Throwable $e) {
        error_log('[eval_schema parents ALTER] ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────
    // eval_sessions — one row per evaluation attempt
    // ─────────────────────────────────────────────────────────────
    db()->exec("CREATE TABLE IF NOT EXISTS eval_sessions (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id       INTEGER NOT NULL,
        child_id        INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        module          TEXT NOT NULL,                 -- mod_speech_basic etc.
        status          TEXT NOT NULL DEFAULT 'in_progress',  -- in_progress | completed | abandoned
        is_free         INTEGER NOT NULL DEFAULT 0,    -- 1 if used the free-eval slot
        cost_paid       INTEGER NOT NULL DEFAULT 0,    -- in rupees (0 for free, 59 for paid)
        started_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        completed_at    TEXT,
        current_level   INTEGER NOT NULL DEFAULT 3,    -- 1..5; starts mid
        questions_asked INTEGER NOT NULL DEFAULT 0,
        final_level     INTEGER,                       -- the level the engine settled on
        final_pct       INTEGER,                       -- percentile-ish summary (0-100)
        report_md       TEXT,                           -- AI-generated markdown report
        report_md_hi    TEXT,                           -- Hindi version (Phase 3 polish)
        sample_exercise_md TEXT                          -- one sample exercise for upsell
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_eval_sessions_parent ON eval_sessions(parent_id, status)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_eval_sessions_child ON eval_sessions(child_id, module)");

    // ─────────────────────────────────────────────────────────────
    // eval_questions — per-question record within a session
    // ─────────────────────────────────────────────────────────────
    db()->exec("CREATE TABLE IF NOT EXISTS eval_questions (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id      INTEGER NOT NULL REFERENCES eval_sessions(id) ON DELETE CASCADE,
        seq_no          INTEGER NOT NULL,              -- 1, 2, 3...
        level           INTEGER NOT NULL,              -- 1..5 the level this q was generated for
        question_type   TEXT NOT NULL,                 -- mcq | naming | fill_in | describe
        prompt          TEXT NOT NULL,                 -- the question text shown to user
        options_json    TEXT,                          -- JSON array of MCQ options if applicable
        expected        TEXT,                          -- expected/correct answer (free-text 'gold')
        image_concept   TEXT,                          -- short description if image is referenced
        asked_at        TEXT DEFAULT CURRENT_TIMESTAMP,
        answered_at     TEXT,                          -- when the user submitted
        time_seconds    INTEGER,                       -- how long they took
        user_answer     TEXT,                          -- what they entered/picked
        answer_mode     TEXT DEFAULT 'text',           -- text | voice
        acoustic_json   TEXT,                          -- JSON of acoustic features when answer_mode=voice
        audio_path      TEXT,                          -- relative path to stored audio file (voice mode)
        is_correct      INTEGER,                       -- 0/1 after AI scoring (NULL = not yet scored)
        ai_verdict      TEXT,                          -- 'correct_fast' | 'correct_slow' | 'wrong_fast' | 'wrong_slow'
        next_level      INTEGER                        -- the level the engine decided for the NEXT q
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_eval_questions_session ON eval_questions(session_id, seq_no)");

    // Idempotent ALTER for sites where the table already exists without these columns
    try {
        $cols = db()->query("PRAGMA table_info(eval_questions)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('answer_mode', $names, true))   db()->exec("ALTER TABLE eval_questions ADD COLUMN answer_mode TEXT DEFAULT 'text'");
        if (!in_array('acoustic_json', $names, true)) db()->exec("ALTER TABLE eval_questions ADD COLUMN acoustic_json TEXT");
        if (!in_array('audio_path', $names, true))    db()->exec("ALTER TABLE eval_questions ADD COLUMN audio_path TEXT");
    } catch (Throwable $e) {
        error_log('[eval_schema eval_questions ALTER] ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────
    // Seed prices for the eval pilot (idempotent INSERT OR REPLACE)
    // ─────────────────────────────────────────────────────────────
    try {
        // Speech evaluation (one-time): ₹59 (slashed from ₹199)
        db()->prepare("INSERT OR REPLACE INTO service_prices
                       (service_key, label, price, audience, is_active)
                       VALUES (?, ?, ?, ?, ?)")
           ->execute(['mod_speech_eval', 'Speech & Language Evaluation', 59, 'parent', 1]);

        // 1-week speech plan (the upsell after evaluation): ₹99 (slashed from ₹299)
        db()->prepare("INSERT OR REPLACE INTO service_prices
                       (service_key, label, price, audience, is_active)
                       VALUES (?, ?, ?, ?, ?)")
           ->execute(['plan_speech_week1', 'Speech 1-Week Plan', 99, 'parent', 1]);
    } catch (Throwable $e) {
        error_log('[eval_schema price seed] ' . $e->getMessage());
    }

})();
