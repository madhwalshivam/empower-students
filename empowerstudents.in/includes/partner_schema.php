<?php
/**
 * includes/partner_schema.php — partner referral & revenue-share system
 *
 * Strategy phase 1: friendly prices + 30% partner share (we eat the cost as CAC).
 * Strategy phase 2 (later): raise prices to recover margin.
 * Strategy phase 3 (later): drop partner share as brand demand grows.
 *
 * Per-partner share is stored on the partner row, not hardcoded — so each
 * partner can be on a different rate (early adopter at 30%, late at 20%).
 *
 * Idempotent: safe to require on every request.
 */
require_once __DIR__ . '/db.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("
    -- ── 1. Partners (tutors / centres / individuals) ────────────────
    CREATE TABLE IF NOT EXISTS partners (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT NOT NULL,
        contact_name    TEXT,
        phone           TEXT,
        whatsapp        TEXT,
        email           TEXT,
        city            TEXT,
        referral_code   TEXT UNIQUE NOT NULL,         -- 'SUNRISE' (used in URLs ?ref=SUNRISE)
        revenue_share   REAL DEFAULT 0.30,            -- 0.30 = 30%; per-partner so phase 3 cuts apply individually
        bank_name       TEXT,
        bank_account    TEXT,
        bank_ifsc       TEXT,
        upi_id          TEXT,
        status          TEXT DEFAULT 'active',        -- active | paused | terminated
        notes           TEXT,
        created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        last_referral_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_partners_code ON partners(referral_code);

    -- ── 2. Per-charge payout ledger ──────────────────────────────────
    -- Created automatically by partner_record_charge() whenever a
    -- referred parent is charged for any service.
    CREATE TABLE IF NOT EXISTS partner_payouts (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        partner_id          INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
        parent_id           INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
        wallet_ledger_id    INTEGER REFERENCES wallet_ledger(id) ON DELETE SET NULL,
        service_key         TEXT NOT NULL,
        gross_amount        INTEGER NOT NULL,         -- credits charged to parent (positive)
        partner_amount      REAL NOT NULL,            -- credits owed to partner (gross × revenue_share)
        share_rate_used     REAL NOT NULL,            -- snapshot of share rate at time of charge
        status              TEXT DEFAULT 'pending',   -- pending | paid | reversed
        batch_id            INTEGER REFERENCES partner_payout_batches(id) ON DELETE SET NULL,
        created_at          TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_payouts_partner_status ON partner_payouts(partner_id, status);
    CREATE INDEX IF NOT EXISTS idx_payouts_ledger ON partner_payouts(wallet_ledger_id);

    -- ── 3. Payout batches (a batch = one bulk payout to a partner) ───
    CREATE TABLE IF NOT EXISTS partner_payout_batches (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        partner_id      INTEGER NOT NULL REFERENCES partners(id),
        period_start    TEXT,
        period_end      TEXT,
        total_credits   REAL NOT NULL,
        total_inr       REAL NOT NULL,                -- 1 cr = ₹1
        item_count      INTEGER NOT NULL,
        method          TEXT,                         -- upi | bank_transfer | cash | other
        reference       TEXT,                         -- UTR / UPI ref / cheque no
        notes           TEXT,
        paid_by         TEXT,                         -- admin user
        paid_at         TEXT DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Add partner_id column to parents if not present
    // (Skipped if parents table doesn't exist yet — runs on next request after db_init)
    $parents_exists = (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='parents'")->fetchColumn();
    if (!$parents_exists) return;

    $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
    $has = false;
    foreach ($cols as $c) if ($c['name'] === 'partner_id') { $has = true; break; }
    if (!$has) {
        db()->exec("ALTER TABLE parents ADD COLUMN partner_id INTEGER REFERENCES partners(id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_parents_partner ON parents(partner_id)");
    }
})();

/**
 * Look up a partner by their public referral code (case-insensitive).
 * Returns null if not found / inactive.
 */
function partner_by_code(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $st = db()->prepare("SELECT * FROM partners WHERE UPPER(referral_code) = ? AND status = 'active'");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

/**
 * Set the partner_id on a parent row. No-op if already set (first-touch
 * attribution wins — we don't let a later partner steal credit).
 */
function partner_attribute_parent(int $parent_id, int $partner_id): bool {
    $st = db()->prepare("SELECT partner_id FROM parents WHERE id = ?");
    $st->execute([$parent_id]);
    $cur_pid = $st->fetchColumn();
    if ($cur_pid) return false;        // already attributed — first touch wins
    db()->prepare("UPDATE parents SET partner_id = ? WHERE id = ?")->execute([$partner_id, $parent_id]);
    db()->prepare("UPDATE partners SET last_referral_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$partner_id]);
    return true;
}

/**
 * Record a partner payout for a wallet charge.
 * Idempotent on wallet_ledger_id — same ledger row can't double-pay.
 *
 * Call this from anywhere a parent gets charged (typically wrapped around
 * wallet_charge_for_service). Silent no-op if parent has no partner.
 */
function partner_record_charge(int $parent_id, int $wallet_ledger_id, string $service_key, int $gross_credits): void {
    if ($gross_credits <= 0) return;   // refunds and grants don't generate payouts

    // Find partner
    $st = db()->prepare("SELECT p.id, p.revenue_share FROM parents pa JOIN partners p ON p.id = pa.partner_id WHERE pa.id = ? AND p.status = 'active'");
    $st->execute([$parent_id]);
    $row = $st->fetch();
    if (!$row) return;                 // parent has no active partner

    $partner_id = (int)$row['id'];
    $share = (float)$row['revenue_share'];
    $partner_amount = round($gross_credits * $share, 2);
    if ($partner_amount <= 0) return;

    // Idempotent on wallet_ledger_id
    $exists = db()->prepare("SELECT 1 FROM partner_payouts WHERE wallet_ledger_id = ? LIMIT 1");
    $exists->execute([$wallet_ledger_id]);
    if ($exists->fetchColumn()) return;

    db()->prepare("INSERT INTO partner_payouts
        (partner_id, parent_id, wallet_ledger_id, service_key, gross_amount, partner_amount, share_rate_used)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$partner_id, $parent_id, $wallet_ledger_id, $service_key, $gross_credits, $partner_amount, $share]);
}

/**
 * Backfill: scan wallet_ledger for charges from referred parents that don't
 * yet have a partner_payouts row. Useful when partner attribution is added
 * AFTER the parent has already been charged (e.g. retroactive attribution).
 *
 * Returns number of payout rows created.
 */
function partner_backfill_payouts(?int $partner_id = null): int {
    $where = "WHERE pa.partner_id IS NOT NULL AND wl.amount < 0
              AND NOT EXISTS (SELECT 1 FROM partner_payouts pp WHERE pp.wallet_ledger_id = wl.id)";
    $params = [];
    if ($partner_id !== null) {
        $where .= " AND pa.partner_id = ?";
        $params[] = $partner_id;
    }
    $st = db()->prepare("SELECT wl.id, wl.parent_id, wl.amount, wl.service_key
                          FROM wallet_ledger wl
                          JOIN parents pa ON pa.id = wl.parent_id
                          $where");
    $st->execute($params);
    $count = 0;
    foreach ($st->fetchAll() as $r) {
        partner_record_charge((int)$r['parent_id'], (int)$r['id'], (string)$r['service_key'], abs((int)$r['amount']));
        $count++;
    }
    return $count;
}

/**
 * Owed-credits summary for a partner (pending payouts only).
 */
function partner_owed(int $partner_id): array {
    $st = db()->prepare("SELECT COALESCE(SUM(partner_amount), 0) AS owed,
                                 COUNT(*) AS items,
                                 MIN(created_at) AS oldest
                          FROM partner_payouts
                          WHERE partner_id = ? AND status = 'pending'");
    $st->execute([$partner_id]);
    return $st->fetch();
}
