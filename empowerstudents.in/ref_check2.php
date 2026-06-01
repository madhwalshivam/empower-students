<?php
/**
 * ref_check2.php?key=nci2026admin
 *
 * Step 2 diagnostic: focus on
 *   (a) what Isha (parent #7) actually paid via Cashfree (payment_orders)
 *   (b) all top-ups for any partner-attributed parents
 *   (c) commission_pct column status
 *
 * Read-only.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403); echo "forbidden"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();

echo "=== REFERRAL DIAGNOSTIC #2 ===\n\n";

// 1. Isha's payment_orders (any Cashfree top-up)
echo "--- payment_orders for parent #7 (Isha) ---\n";
try {
    $st = db()->prepare("SELECT id, order_id, amount, currency, status, cf_payment_id,
                                payment_method, credited, created_at, completed_at
                         FROM payment_orders WHERE parent_id = 7 ORDER BY id DESC");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  No payment_orders rows for parent #7.\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("  #%-4d order=%-30s amount=Rs.%-5d status=%-10s credited=%d cf=%-25s when=%s\n",
                $r['id'], $r['order_id'], $r['amount'], $r['status'], $r['credited'],
                $r['cf_payment_id'] ?: '-', $r['created_at']);
        }
    }
} catch (Throwable $e) { echo "  err: " . $e->getMessage() . "\n"; }

// 2. ALL payment_orders for partner-attributed parents
echo "\n--- All payment_orders for partner-attributed parents (last 30 days) ---\n";
try {
    $st = db()->query("SELECT po.id, po.parent_id, p.name, p.partner_id,
                              po.amount, po.status, po.credited, po.created_at
                       FROM payment_orders po
                       JOIN parents p ON p.id = po.parent_id
                       WHERE p.partner_id IS NOT NULL
                         AND datetime(po.created_at) >= datetime('now', '-30 days')
                       ORDER BY po.id DESC LIMIT 30");
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  No top-ups by partner-attributed parents in last 30 days.\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("  #%-4d parent=%-3d (%s) partner=%-3d amount=Rs.%-5d status=%-10s credited=%d when=%s\n",
                $r['id'], $r['parent_id'], $r['name'], $r['partner_id'],
                $r['amount'], $r['status'], $r['credited'], $r['created_at']);
        }
    }
} catch (Throwable $e) { echo "  err: " . $e->getMessage() . "\n"; }

// 3. ALL wallet_ledger entries for Isha (positive AND negative)
echo "\n--- ALL wallet_ledger for parent #7 (Isha) ---\n";
try {
    $st = db()->prepare("SELECT id, amount, balance_after, service_key, ref_id, reason, created_at, created_by
                         FROM wallet_ledger WHERE parent_id = 7 ORDER BY id DESC");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  No ledger entries for parent #7.\n";
    } else {
        foreach ($rows as $l) {
            $sign = (int)$l['amount'] >= 0 ? '+' : '';
            echo sprintf("  #%-5d %sRs.%-5d bal_after=Rs.%-5d service=%-25s ref=%-5s by=%-15s when=%s\n",
                $l['id'], $sign, $l['amount'], $l['balance_after'], $l['service_key'] ?: '-',
                $l['ref_id'] ?: '-', $l['created_by'] ?: '-', $l['created_at']);
            if (!empty($l['reason'])) echo "        reason: " . $l['reason'] . "\n";
        }
    }
} catch (Throwable $e) { echo "  err: " . $e->getMessage() . "\n"; }

// 4. service_prices current state — verify mod_parent_reflect actual price
echo "\n--- service_prices: parent reflect + home course ---\n";
try {
    $st = db()->prepare("SELECT service_key, label, price, audience, is_active, updated_at
                         FROM service_prices
                         WHERE service_key IN ('mod_parent_reflect','home_course_999','home_course_2min','home_course_5min','home_course_10min')
                         ORDER BY service_key");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  none of the expected service_prices rows exist.\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("  %-22s price=Rs.%-5d active=%d label=%s\n",
                $r['service_key'], $r['price'], $r['is_active'], $r['label']);
        }
    }
} catch (Throwable $e) { echo "  err: " . $e->getMessage() . "\n"; }

// 5. Check whether partners.commission_pct already exists (delivery 1 schema check)
echo "\n--- partners table columns ---\n";
try {
    $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
    $names = [];
    foreach ($cols as $c) $names[] = $c['name'];
    echo "  Columns: " . implode(', ', $names) . "\n";
    echo "  commission_pct exists: " . (in_array('commission_pct', $names, true) ? 'YES' : 'NO (will be added)') . "\n";
} catch (Throwable $e) { echo "  err: " . $e->getMessage() . "\n"; }

// 6. PARTNER_REVENUE_SHARE_PCT constant — what's it currently?
if (file_exists(__DIR__ . '/includes/partners.php')) {
    @include_once __DIR__ . '/includes/partners.php';
    if (defined('PARTNER_REVENUE_SHARE_PCT')) {
        echo "\n  Global constant PARTNER_REVENUE_SHARE_PCT = " . PARTNER_REVENUE_SHARE_PCT . "\n";
    }
}

echo "\nDone. Delete this file after use.\n";
