<?php
/**
 * parent_reflect_schema.php
 *
 * Schema for "Parent Reflection" — voice-driven adaptive reflection module
 * for parents of special-needs children.
 *
 * Concept (mirrors docspeak architecture):
 *   - 10-15 turn voice interview, AI adapts each next question
 *   - Captures parent's spoken answer + acoustic features + voice emotions
 *   - On close: produces TWO reports:
 *       parent_summary_md  — warm, supportive, "here's what to try"
 *       admin_clinical_md  — clinical signals, risk markers, follow-up notes
 *
 * Pricing: ₹499 per session — includes one follow-up call from EmpowerStudents
 * psychologist within 48 hours (high-touch, not just an AI report).
 *
 * Idempotent — safe to run on every page that needs it.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('parent_reflect_schema_init')) {
    function parent_reflect_schema_init(): void {

        // ─────────────────────────────────────────────────────────────
        // parent_reflect_sessions — one row per started reflection
        // ─────────────────────────────────────────────────────────────
        db()->exec("CREATE TABLE IF NOT EXISTS parent_reflect_sessions (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id           INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
            child_id            INTEGER REFERENCES children(id) ON DELETE SET NULL,  -- which child this is about
            status              TEXT NOT NULL DEFAULT 'in_progress',  -- in_progress | completed | abandoned
            cost_paid           INTEGER NOT NULL DEFAULT 0,           -- always 499 (no free tier)
            started_at          TEXT DEFAULT CURRENT_TIMESTAMP,
            last_activity_at    TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at        TEXT,
            current_phase       INTEGER NOT NULL DEFAULT 1,            -- 1..10
            turn_count          INTEGER NOT NULL DEFAULT 0,            -- how many AI questions have been asked
            -- Final outputs (filled on close, both via Sonnet)
            parent_summary_md   TEXT,                                  -- the warm, supportive report parent sees
            parent_action_md    TEXT,                                  -- 'one thing to try this week'
            admin_clinical_md   TEXT,                                  -- clinical view (NCI staff only)
            admin_risk_level    TEXT,                                  -- 'green' | 'amber' | 'red'
            admin_follow_up_by  TEXT,                                  -- ISO datetime — when NCI must call back
            generated_at        TEXT,
            -- Aggregated risk signals (0..1 each, computed on close)
            sig_marital_stress  REAL DEFAULT 0,
            sig_in_law_stress   REAL DEFAULT 0,
            sig_parent_burnout  REAL DEFAULT 0,
            sig_child_distress  REAL DEFAULT 0,
            sig_isolation       REAL DEFAULT 0,
            sig_safety_red_flag INTEGER DEFAULT 0                      -- 0 or 1
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pr_sessions_parent ON parent_reflect_sessions(parent_id, status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pr_sessions_admin  ON parent_reflect_sessions(status, admin_risk_level, completed_at)");

        // ─────────────────────────────────────────────────────────────
        // parent_reflect_turns — one row per question/answer turn
        // ─────────────────────────────────────────────────────────────
        db()->exec("CREATE TABLE IF NOT EXISTS parent_reflect_turns (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id          INTEGER NOT NULL REFERENCES parent_reflect_sessions(id) ON DELETE CASCADE,
            turn_no             INTEGER NOT NULL,                      -- 1, 2, 3...
            phase               INTEGER NOT NULL,                      -- 1..10 phase the AI was operating in
            question            TEXT NOT NULL,                         -- AI's question this turn
            question_intent     TEXT,                                  -- probe | reframe | forward | slow | challenge | close
            asked_at            TEXT DEFAULT CURRENT_TIMESTAMP,
            -- Parent's response (set when answer comes in)
            transcript          TEXT,
            answered_at         TEXT,
            time_seconds        INTEGER,
            acoustic_json       TEXT,                                  -- WPM, duration, pause_count, silence_ratio, volume_variance, time_to_first_speech_sec, transcript_confidence
            emotions_json       TEXT,                                  -- 11 intensities + felt_sense (from Haiku emotion call)
            -- AI's interpretation
            ai_reflection       TEXT,                                  -- 1-2 sentences mirroring what parent said
            ai_tone_insight     TEXT,                                  -- 1 sentence on voice + words combined
            signals_json        TEXT                                   -- per-turn signals snapshot
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pr_turns_session ON parent_reflect_turns(session_id, turn_no)");

        // ─────────────────────────────────────────────────────────────
        // Service price + label registration in service_prices
        // ─────────────────────────────────────────────────────────────
        $svc_exists = (bool) db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='service_prices'")->fetchColumn();
        if ($svc_exists) {
            $st = db()->prepare("INSERT OR REPLACE INTO service_prices (service_key, label, price, audience, is_active)
                                 VALUES (?, ?, ?, 'parent', 1)");
            $st->execute(['mod_parent_reflect', 'Parent Reflection (with psychologist follow-up)', 499]);
        }

        // ─────────────────────────────────────────────────────────────
        // Forward-compat ALTER block (idempotent)
        // ─────────────────────────────────────────────────────────────
        try {
            $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
            $names = array_column($cols, 'name');

            // followup_count — how many extra follow-up turns the parent has
            // re-opened on this session after first completion. Capped at 3.
            if (!in_array('followup_count', $names, true)) {
                db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN followup_count INTEGER NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {
            error_log('[parent_reflect_schema ALTER] ' . $e->getMessage());
        }
    }
}

parent_reflect_schema_init();
