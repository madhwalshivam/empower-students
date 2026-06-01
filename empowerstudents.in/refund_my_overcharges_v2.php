<?php
/**
 * refund_my_overcharges_v2.php?key=nci2026admin&dry=1
 *
 * v2 — works regardless of wallet_ledger column names.
 * Selects only columns that actually exist.
 *
 * Pass &dry=1 to preview, omit it to actually refund.
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
echo $dry ? "[DRY RUN]\n\n" : "[LIVE — refunds will be posted]\n\n";

echo "Current wallet balance: ₹" . wallet_balance($parent_id) . "\n\n";

// Inspect wallet_ledger schema
$cols = db()->query("PRAGMA table_info(wallet_ledger)")->fetchAll();
$col_names = array_column($cols, 'name');
echo "wallet_ledger columns: " . implode(', ', $col_names) . "\n\n";

// Inspect parent_reflect_sessions schema (we need refunded_at; add if missing)
$rcols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
if (!in_array('refunded_at', array_column($rcols, 'name'), true)) {
    try {
        db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN refunded_at TEXT");
        echo "✓ Added refunded_at column to parent_reflect_sessions\n\n";
    } catch (Throwable $e) {
        echo "⚠ Could not add refunded_at column: " . $e->getMessage() . "\n\n";
    }
}

$st = db()->prepare("SELECT id, status, cost_paid, started_at, completed_at, report_pdf_path, refunded_at
                      FROM parent_reflect_sessions
                      WHERE parent_id = ?
                      ORDER BY id DESC");
$st->execute([$parent_id]);
$sessions = $st->fetchAll();

echo "Found " . count($sessions) . " reflection session(s):\n";
foreach ($sessions as $s) {
    echo "  • #{$s['id']} status={$s['status']} cost_paid=₹{$s['cost_paid']} pdf=" . ($s['report_pdf_path'] ? 'yes' : 'no') . ($s['refunded_at'] ? ' (already refunded)' : '') . "\n";
}
echo "\n";

$total_refunded = 0;
$count_refunded = 0;

foreach ($sessions as $s) {
    $sid = (int)$s['id'];
    $cost = (int)$s['cost_paid'];
    $status = $s['status'];
    $has_pdf = !empty($s['report_pdf_path']);
    $already_refunded = !empty($s['refunded_at']);

    // Skip sessions that delivered a PDF — those are legitimately charged
    if ($status === 'completed' && $has_pdf) {
        echo "  ✓ #$sid: completed + PDF delivered → keep charge (₹$cost legit)\n";
        continue;
    }
    if ($cost <= 0) {
        echo "  · #$sid: no charge → skip\n";
        continue;
    }
    if ($already_refunded) {
        echo "  · #$sid: already refunded earlier → skip\n";
        continue;
    }

    // Check whether wallet_ledger has a refund row for this session via ref_id
    $rc = db()->prepare("SELECT id FROM wallet_ledger
                          WHERE parent_id = ? AND service_key = 'refund_unfinished_eval' AND ref_id = ?
                          LIMIT 1");
    $rc->execute([$parent_id, $sid]);
    if ($rc->fetch()) {
        echo "  · #$sid: refund ledger entry already exists → skip\n";
        continue;
    }

    echo "  → #$sid: refunding ₹$cost ($status, no PDF)\n";

    if (!$dry) {
        $new_bal = wallet_post(
            $parent_id,
            $cost,
            'refund_unfinished_eval',
            $sid,
            "Refund: session #$sid did not deliver PDF ($status)",
            'auto'
        );
        echo "     ✓ refunded → new balance: ₹$new_bal\n";

        db()->prepare("UPDATE parent_reflect_sessions SET refunded_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$sid]);

        $total_refunded += $cost;
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
