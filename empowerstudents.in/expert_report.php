<?php
/**
 * expert_report.php — helpers for the Detailed Expert Report.
 *
 * Pricing: 'expert_report' service in service_prices table (default 1000 cr).
 *
 * Two ordering paths (both leave order in `expert_report_orders` with status='pending'):
 *   1. order_expert_report_via_wallet()  — charges credits
 *   2. order_expert_report_via_referral()  — free via 2 completed referrals (in referral.php)
 *
 * Once ordered, the admin workshop at /admin/expert_report_edit.php?order_id=N lets
 * the team write/edit the report. Saving with "deliver" flips the status to 'delivered'
 * and the parent sees it on /report.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/referral.php';
require_once __DIR__ . '/partner_commission.php';
require_once __DIR__ . '/eval_round.php';

const EXPERT_REPORT_SERVICE_KEY = 'expert_report';
const EXPERT_REPORT_DEFAULT_CREDITS = 1000;

/**
 * Add report_text + report_delivered_at columns. Idempotent.
 */
function ensure_expert_report_text_columns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("PRAGMA table_info(expert_report_orders)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('report_text', $names, true)) {
            db()->exec("ALTER TABLE expert_report_orders ADD COLUMN report_text TEXT");
        }
        if (!in_array('report_delivered_at', $names, true)) {
            db()->exec("ALTER TABLE expert_report_orders ADD COLUMN report_delivered_at TEXT");
        }
    } catch (Throwable $e) { /* swallow */ }
}

function ensure_expert_report_service() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = db()->prepare("SELECT 1 FROM service_prices WHERE service_key = ?");
        $st->execute([EXPERT_REPORT_SERVICE_KEY]);
        if (!$st->fetchColumn()) {
            db()->prepare("INSERT INTO service_prices (service_key, label, price, audience, is_active)
                           VALUES (?, ?, ?, 'parent', 1)")
               ->execute([
                   EXPERT_REPORT_SERVICE_KEY,
                   'Detailed expert report (Dr. P. K. Jha team study + 24-hr callback)',
                   EXPERT_REPORT_DEFAULT_CREDITS,
               ]);
        }
    } catch (Throwable $e) { /* swallow */ }
}

function order_expert_report_via_wallet(int $parent_id, int $child_id): array {
    ensure_referral_schema();
    ensure_expert_report_service();
    ensure_expert_report_text_columns();

    // Get current evaluation round for this child (defaults to 1)
    $current_round = current_evaluation_round($child_id);

    // Look for existing order in THIS round only — older rounds don't block fresh orders
    $st = db()->prepare("SELECT id, status FROM expert_report_orders
                          WHERE parent_id = ? AND child_id = ?
                                AND COALESCE(evaluation_round, 1) = ?
                                AND status IN ('pending','delivered')
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$parent_id, $child_id, $current_round]);
    $existing = $st->fetch();
    if ($existing) {
        return [
            'status'   => 'already_ordered',
            'needed'   => 0,
            'balance'  => wallet_balance($parent_id),
            'order_id' => (int)$existing['id'],
        ];
    }

    $price = wallet_service_price(EXPERT_REPORT_SERVICE_KEY) ?? EXPERT_REPORT_DEFAULT_CREDITS;
    $balance = wallet_balance($parent_id);
    if ($balance < $price) {
        return ['status'  => 'insufficient', 'needed' => $price, 'balance' => $balance, 'order_id'=> null];
    }

    db()->prepare("INSERT INTO expert_report_orders
                   (parent_id, child_id, source, amount_paid, status)
                   VALUES (?, ?, 'paid', ?, 'pending')")
       ->execute([$parent_id, $child_id, $price]);
    $order_id = (int)db()->lastInsertId();

    $charge = wallet_charge_for_service($parent_id, EXPERT_REPORT_SERVICE_KEY, $order_id);
    if ($charge['status'] === 'insufficient') {
        db()->prepare("DELETE FROM expert_report_orders WHERE id = ?")->execute([$order_id]);
        return ['status' => 'insufficient', 'needed' => $price, 'balance' => $balance, 'order_id' => null];
    }

    // Partner commission: if this parent came via a partner link, log 20% commission.
    attribute_partner_commission($order_id, $parent_id, $child_id, $price, 'expert_report');

    return [
        'status'  => 'ordered', 'needed' => $price,
        'balance' => $charge['credits'] ?? wallet_balance($parent_id),
        'order_id'=> $order_id,
    ];
}

/**
 * Get the latest delivered report for the CURRENT round of a child.
 * After a reset, old round's report is still in DB but not surfaced here.
 */
function get_delivered_expert_report(int $parent_id, int $child_id): ?array {
    ensure_expert_report_text_columns();
    $current_round = current_evaluation_round($child_id);
    $st = db()->prepare("
        SELECT * FROM expert_report_orders
         WHERE parent_id = ? AND child_id = ? AND status = 'delivered'
              AND COALESCE(evaluation_round, 1) = ?
              AND report_text IS NOT NULL AND report_text != ''
         ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$parent_id, $child_id, $current_round]);
    $row = $st->fetch();
    return $row ?: null;
}
