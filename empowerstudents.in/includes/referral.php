<?php
/**
 * Referral system.
 *
 * Each parent has a unique 8-character referral_code. When they share
 * https://empowerstudents.in/r.php?c=ABCD1234 — the recipient's session is
 * tagged with that referrer; on signup, we record the relationship in the
 * referrals table.
 *
 * A referrer becomes eligible for a FREE Detailed Expert Report when:
 *   - 2 of their referred parents each complete at least 1 child evaluation
 *
 * Schema columns / tables are auto-migrated on first call.
 */
require_once __DIR__ . '/db.php';

const REFERRALS_NEEDED_FOR_FREE_REPORT = 2;

function ensure_referral_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // 1. Add referral_code + referred_by columns to parents
        $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('referral_code', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN referral_code TEXT");
        }
        if (!in_array('referred_by_parent_id', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN referred_by_parent_id INTEGER");
        }
        if (!in_array('expert_report_status', $names, true)) {
            // 'none' | 'pending_paid' | 'pending_referral' | 'delivered'
            db()->exec("ALTER TABLE parents ADD COLUMN expert_report_status TEXT DEFAULT 'none'");
        }
        if (!in_array('expert_report_ordered_at', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN expert_report_ordered_at TEXT");
        }

        // 2. referrals tracking table
        db()->exec("
            CREATE TABLE IF NOT EXISTS referrals (
                id                       INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_parent_id       INTEGER NOT NULL,
                referred_parent_id       INTEGER NOT NULL,
                child_eval_completed     INTEGER DEFAULT 0,
                created_at               TEXT DEFAULT CURRENT_TIMESTAMP,
                completed_at             TEXT,
                UNIQUE(referrer_parent_id, referred_parent_id)
            )
        ");

        // 3. Expert report orders table — for admin to see pending orders
        db()->exec("
            CREATE TABLE IF NOT EXISTS expert_report_orders (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id         INTEGER NOT NULL,
                child_id          INTEGER NOT NULL,
                source            TEXT NOT NULL,        -- 'paid' | 'referral'
                amount_paid       INTEGER DEFAULT 0,    -- in INR (rupees)
                cashfree_order_id TEXT,
                status            TEXT DEFAULT 'pending',  -- pending | delivered | refunded
                ordered_at        TEXT DEFAULT CURRENT_TIMESTAMP,
                delivered_at      TEXT,
                admin_notes       TEXT
            )
        ");

        // 4. Backfill referral codes for existing parents
        $missing = db()->query("SELECT id FROM parents WHERE referral_code IS NULL OR referral_code = ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($missing as $pid) {
            db()->prepare("UPDATE parents SET referral_code = ? WHERE id = ?")
                ->execute([generate_unique_referral_code(), (int)$pid]);
        }
    } catch (Throwable $e) {
        // Don't crash the site if migration hits a hiccup
        error_log('referral schema migration: ' . $e->getMessage());
    }
}

function generate_unique_referral_code(): string {
    // 8 uppercase alphanumeric chars excluding ambiguous (0, O, 1, I, L)
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $tries = 0;
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $exists = db()->prepare("SELECT 1 FROM parents WHERE referral_code = ?");
        $exists->execute([$code]);
        $tries++;
    } while ($exists->fetchColumn() && $tries < 20);
    return $code;
}

/**
 * Get (or lazily generate) the current parent's referral code.
 */
function parent_referral_code(int $parent_id): string {
    ensure_referral_schema();
    $st = db()->prepare("SELECT referral_code FROM parents WHERE id = ?");
    $st->execute([$parent_id]);
    $code = (string)($st->fetchColumn() ?: '');
    if ($code === '') {
        $code = generate_unique_referral_code();
        db()->prepare("UPDATE parents SET referral_code = ? WHERE id = ?")->execute([$code, $parent_id]);
    }
    return $code;
}

/**
 * Look up referrer's parent_id by referral_code (case-insensitive).
 * Returns null if not found.
 */
function lookup_referrer_by_code(string $code): ?int {
    ensure_referral_schema();
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $st = db()->prepare("SELECT id FROM parents WHERE UPPER(referral_code) = ?");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Record a successful signup as a referral. Idempotent.
 * Called from login.php just after creating a brand-new parent record.
 */
function record_referral_signup(int $referrer_id, int $new_parent_id): void {
    ensure_referral_schema();
    if ($referrer_id === $new_parent_id) return;  // can't refer yourself
    try {
        db()->prepare("UPDATE parents SET referred_by_parent_id = ? WHERE id = ? AND referred_by_parent_id IS NULL")
            ->execute([$referrer_id, $new_parent_id]);
        db()->prepare("INSERT OR IGNORE INTO referrals (referrer_parent_id, referred_parent_id) VALUES (?, ?)")
            ->execute([$referrer_id, $new_parent_id]);
    } catch (Throwable $e) {
        error_log('record_referral_signup: ' . $e->getMessage());
    }
}

/**
 * Mark that a referred parent has completed at least one child evaluation.
 * Call this from the assessment finalize path.
 */
function maybe_mark_referral_complete(int $parent_id): void {
    ensure_referral_schema();
    try {
        $st = db()->prepare("SELECT referred_by_parent_id FROM parents WHERE id = ?");
        $st->execute([$parent_id]);
        $referrer_id = $st->fetchColumn();
        if (!$referrer_id) return;

        // Count completed assessments for this referred parent's children
        $cst = db()->prepare("
            SELECT COUNT(*) FROM assessments a
            JOIN children c ON c.id = a.child_id
            WHERE c.parent_id = ? AND a.status = 'done'
        ");
        $cst->execute([$parent_id]);
        $count = (int)$cst->fetchColumn();
        if ($count < 1) return;

        // Flip the referral row to completed (idempotent)
        db()->prepare("
            UPDATE referrals
               SET child_eval_completed = 1, completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)
             WHERE referrer_parent_id = ? AND referred_parent_id = ? AND child_eval_completed = 0
        ")->execute([(int)$referrer_id, $parent_id]);
    } catch (Throwable $e) {
        error_log('maybe_mark_referral_complete: ' . $e->getMessage());
    }
}

/**
 * Returns referral stats for a parent: how many friends signed up,
 * how many have completed an evaluation, and whether they qualify for a free expert report.
 */
function referral_stats(int $parent_id): array {
    ensure_referral_schema();
    $st = db()->prepare("
        SELECT
            COUNT(*) AS total_signups,
            SUM(child_eval_completed) AS completed_evals
          FROM referrals
         WHERE referrer_parent_id = ?
    ");
    $st->execute([$parent_id]);
    $row = $st->fetch() ?: ['total_signups' => 0, 'completed_evals' => 0];
    $signups   = (int)($row['total_signups']  ?? 0);
    $completed = (int)($row['completed_evals'] ?? 0);
    return [
        'signups'           => $signups,
        'completed_evals'   => $completed,
        'needed'            => REFERRALS_NEEDED_FOR_FREE_REPORT,
        'qualifies_free'    => $completed >= REFERRALS_NEEDED_FOR_FREE_REPORT,
    ];
}

/**
 * Has this parent already ordered an expert report (paid OR free)?
 * Used to gate the upgrade card on child.php.
 */
function expert_report_status(int $parent_id, int $child_id): array {
    ensure_referral_schema();
    $st = db()->prepare("
        SELECT id, source, status, ordered_at
          FROM expert_report_orders
         WHERE parent_id = ? AND child_id = ?
         ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$parent_id, $child_id]);
    $row = $st->fetch();
    return $row ?: ['id' => null, 'source' => null, 'status' => null, 'ordered_at' => null];
}

/**
 * Place a free expert report order using the parent's referral credit.
 * Marks one referral as "redeemed".
 */
function order_expert_report_via_referral(int $parent_id, int $child_id): bool {
    ensure_referral_schema();
    $stats = referral_stats($parent_id);
    if (!$stats['qualifies_free']) return false;
    db()->prepare("
        INSERT INTO expert_report_orders (parent_id, child_id, source, amount_paid, status)
        VALUES (?, ?, 'referral', 0, 'pending')
    ")->execute([$parent_id, $child_id]);
    return true;
}
