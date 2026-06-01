<?php
/**
 * includes/paid_schema.php — paid features schema (v2 spec)
 *
 * SPEC:
 *   • Pure credit wallet (1 credit = ₹1)
 *   • Single bundled "Care Pack" purchase: 499 cr unlocks all three features
 *   • Course is AI-generated PER CHILD from their assessment data
 *   • Tracker uses 30-day credit packs, no subscriptions
 *
 * Idempotent: safe to require on every request.
 */
require_once __DIR__ . '/db.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("
    -- ── 1. Care Pack: the single bundled purchase ──────────────────
    -- One row per (parent, child) when they buy. Activates everything.
    CREATE TABLE IF NOT EXISTS care_packs (
        id                       INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id                INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        child_id                 INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        purchased_at             TEXT DEFAULT CURRENT_TIMESTAMP,
        tracker_days_remaining   INTEGER DEFAULT 30,
        UNIQUE (parent_id, child_id)
    );

    -- ── 2. Growth plan (AI-generated, one per child) ───────────────
    CREATE TABLE IF NOT EXISTS growth_plans (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        plan_text     TEXT,
        plan_json     TEXT,
        focus_areas   TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id)
    );

    -- ── 3. Personalised course (AI-generated, one per child) ───────
    CREATE TABLE IF NOT EXISTS personal_courses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        title         TEXT,
        intro_md      TEXT,
        focus_areas   TEXT,
        progress_pct  INTEGER DEFAULT 0,
        completed_at  TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id)
    );

    CREATE TABLE IF NOT EXISTS personal_lessons (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id     INTEGER NOT NULL REFERENCES personal_courses(id) ON DELETE CASCADE,
        title         TEXT NOT NULL,
        body_md       TEXT,
        order_no      INTEGER DEFAULT 100,
        duration_min  INTEGER DEFAULT 5,
        completed_at  TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_pl_course ON personal_lessons(course_id);

    -- ── 4. Daily tracker logs ──────────────────────────────────────
    CREATE TABLE IF NOT EXISTS daily_logs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        log_date      TEXT NOT NULL,
        mood          INTEGER,
        sleep_hours   REAL,
        focus         INTEGER,
        behaviour     INTEGER,
        appetite      INTEGER,
        wins          TEXT,
        concerns      TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id, log_date)
    );
    CREATE INDEX IF NOT EXISTS idx_dl_child_date ON daily_logs(child_id, log_date);

    -- ── 5. Tracker top-ups (each one adds 30 days) ─────────────────
    CREATE TABLE IF NOT EXISTS tracker_topups (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id     INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        days_added    INTEGER DEFAULT 30,
        purchased_at  TEXT DEFAULT CURRENT_TIMESTAMP
    );

    -- ── 6. Weekly AI summaries ─────────────────────────────────────
    CREATE TABLE IF NOT EXISTS weekly_summaries (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id      INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        week_start    TEXT NOT NULL,
        ai_summary    TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (child_id, week_start)
    );
    ");

    // Seed paid service prices (idempotent on service_key)
    $svc_exists = (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='service_prices'")->fetchColumn();
    if (!$svc_exists) return;

    $paid_services = [
        'care_pack'      => [499, 'parent', 'Care Pack — Growth Plan + AI Course + 30-day Tracker'],
        'tracker_topup'  => [149, 'parent', 'Tracker top-up (30 more days)'],
        // Standalone fallback purchases for parents who skip the bundle
        'growth_plan'    => [199, 'parent', 'AI Growth Plan only'],
        'personal_course'=> [299, 'parent', 'Personalised AI Course only'],
    ];
    $st = db()->prepare("INSERT OR REPLACE INTO service_prices (service_key, label, price, audience, is_active) VALUES (?, ?, ?, ?, 1)");
    foreach ($paid_services as $key => $row) {
        [$price, $audience, $label] = $row;
        $st->execute([$key, $label, (int)$price, $audience]);
    }

    // Remove legacy v1 service rows so admin pricing page is clean
    db()->exec("DELETE FROM service_prices WHERE service_key IN (
        'course_speech', 'course_behaviour', 'course_math',
        'course_language', 'course_parenting',
        'tracker_monthly', 'tracker_quarterly', 'tracker_yearly',
        'empower_plus_monthly'
    )");
})();

/**
 * Helper: does this parent+child have an active Care Pack?
 * Care Pack never expires (it's a one-time unlock for plan + course);
 * the tracker_days_remaining counter is a separate concern.
 */
function care_pack_for(int $parent_id, int $child_id): ?array {
    $st = db()->prepare("SELECT * FROM care_packs WHERE parent_id=? AND child_id=?");
    $st->execute([$parent_id, $child_id]);
    return $st->fetch() ?: null;
}

/**
 * Helper: tracker days available for a child.
 * Returns 0 if no Care Pack purchased OR all days consumed.
 */
function tracker_days_remaining(int $child_id): int {
    $st = db()->prepare("SELECT tracker_days_remaining FROM care_packs WHERE child_id=?");
    $st->execute([$child_id]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Decrement tracker days when a NEW log is created (not on edits).
 * Returns the new remaining value. No-op if no Care Pack exists.
 */
function tracker_consume_day(int $child_id): int {
    $cur = tracker_days_remaining($child_id);
    if ($cur <= 0) return 0;
    db()->prepare("UPDATE care_packs SET tracker_days_remaining = tracker_days_remaining - 1 WHERE child_id=? AND tracker_days_remaining > 0")
        ->execute([$child_id]);
    return $cur - 1;
}
