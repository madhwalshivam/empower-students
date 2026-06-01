<?php
/**
 * refund_my_overcharges.php?key=nci2026admin&dry=1
 *
 * Finds the currently logged-in parent's charged-but-incomplete reflection
 * sessions and refunds the wallet for the over-charges.
 *
 * Logic:
 *   - For each parent_reflect_sessions row with status='in_progress' (or 'abandoned')
 *     that has a matching debit in wallet_ledger but no completed PDF...
 *   - ...refund the cost_paid back to wallet (with a clear 'refund_unfinished_eval' entry)
 *   - Mark those sessions as 'refunded' so they can't double-refund
 *
 * Pass &dry=1 to see what WOULD refund without doing it.
 *
 * DELETE after use.
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];
$dry = !empty($_GET['dry']);

echo "=== Refund overcharges for parent #$parent_id ({$parent['name']}) ===\n";
echo $dry ? "[DRY RUN — nothing will be refunded]\n\n" : "[LIVE — refunds will be posted]\n\n";

echo "Current wallet balance: ₹" . wallet_balance($parent_id) . "\n\n";

// Find all reflection sessions for this parent
$st = db()->prepare("SELECT s.id, s.status, s.cost_paid, s.started_at, s.completed_at, s.report_pdf_path
                      FROM parent_reflect_sessions s
                      WHERE s.parent_id = ?
                      ORDER BY s.id DESC");
$st->execute([$parent_id]);
$sessions = $st->fetchAll();

echo "Found " . count($sessions) . " reflection session(s):\n";
foreach ($sessions as $s) {
    echo "  • session #{$s['id']} status={$s['status']} cost_paid={$s['cost_paid']} pdf=" . ($s['report_pdf_path'] ? 'yes' : 'no') . "\n";
}
echo "\n";

// For each charged session, find the matching wallet debit
$total_refunded = 0;
$count_refunded = 0;

foreach ($sessions as $s) {
    $sid = (int)$s['id'];
    $cost = (int)$s['cost_paid'];
    $status = $s['status'];
    $has_pdf = !empty($s['report_pdf_path']);

    // SKIP completed sessions WITH a PDF — those are legitimately charged
    if ($status === 'completed' && $has_pdf) {
        echo "  ✓ session #$sid: completed + PDF delivered — keeping charge (₹$cost legit)\n";
        continue;
    }

    // SKIP free / no-cost sessions
    if ($cost <= 0) {
        echo "  · session #$sid: no charge — skip\n";
        continue;
    }

    // Find the wallet debit
    $ld = db()->prepare("SELECT id, amount, description, created_at FROM wallet_ledger
                          WHERE parent_id = ? AND amount < 0
                          AND (service_key = 'mod_parent_reflect' OR service_key = 'parent_reflection')
                          AND created_at >= ? AND created_at <= COALESCE(?, datetime('now'))
                          ORDER BY id ASC LIMIT 1");
    $ld->execute([$parent_id,
                  $s['started_at'] ?: '1970-01-01',
                  $s['completed_at'] ?: null]);
    $debit = $ld->fetch();

    if (!$debit) {
        echo "  ? session #$sid: no matching wallet debit found — skip\n";
        continue;
    }

    // Check we haven't already refunded for this session
    $rc = db()->prepare("SELECT id FROM wallet_ledger
                          WHERE parent_id = ? AND service_key = 'refund_unfinished_eval' AND ref_id = ?
                          LIMIT 1");
    $rc->execute([$parent_id, $sid]);
    if ($rc->fetch()) {
        echo "  · session #$sid: already refunded earlier — skip\n";
        continue;
    }

    $refund_amount = abs((int)$debit['amount']);
    $reason = $has_pdf ? "session has PDF (unusual)" : "no PDF delivered";

    echo "  → session #$sid: would refund ₹$refund_amount ({$status}, $reason)\n";

    if (!$dry) {
        $new_bal = wallet_post(
            $parent_id,
            $refund_amount,
            'refund_unfinished_eval',
            $sid,
            "Refund: session #$sid did not deliver PDF ($status)",
            'auto'
        );
        echo "     ✓ refunded — new balance: ₹$new_bal\n";

        // Also mark the session row so it's clear in audit
        try {
            // Add 'refunded_at' column if missing
            $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
            if (!in_array('refunded_at', array_column($cols, 'name'), true)) {
                @db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN refunded_at TEXT");
            }
            db()->prepare("UPDATE parent_reflect_sessions SET refunded_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$sid]);
        } catch (Throwable $_) {}

        $total_refunded += $refund_amount;
        $count_refunded++;
    }
}

echo "\n";
if ($dry) {
    echo "Dry run complete. Re-run without &dry=1 to actually refund.\n";
} else {
    echo "✓ Refunded ₹$total_refunded across $count_refunded session(s).\n";
    echo "  New wallet balance: ₹" . wallet_balance($parent_id) . "\n";
}
echo "\nDELETE this file after use.\n";
