<?php
/**
 * ref_check.php?key=nci2026admin
 *
 * Diagnose why "Isha" doesn't show in admin/partners.php referrals.
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

echo "=== REFERRAL DIAGNOSTIC ===\n\n";

// 1. Find Isha — search parents table by name (case-insensitive)
echo "--- Parents matching 'isha' ---\n";
$st = db()->prepare("SELECT id, name, whatsapp, credits, partner_id, created_at
                     FROM parents
                     WHERE LOWER(name) LIKE ? OR whatsapp LIKE ?
                     ORDER BY id DESC LIMIT 10");
$st->execute(['%isha%', '%isha%']);
$matches = $st->fetchAll();
if (!$matches) {
    echo "  No parent with 'isha' in name or whatsapp.\n";
    echo "  Trying ALL parents created in last 36 hours:\n\n";
    $st2 = db()->query("SELECT id, name, whatsapp, credits, partner_id, created_at
                       FROM parents
                       WHERE datetime(created_at) >= datetime('now', '-36 hours')
                       ORDER BY id DESC LIMIT 20");
    $matches = $st2->fetchAll();
}
foreach ($matches as $p) {
    echo sprintf("  parent #%-3d name=%-20s phone=%-15s partner_id=%-5s wallet=%-5d created=%s\n",
        $p['id'], $p['name'], $p['whatsapp'], $p['partner_id'] ?: 'NULL', $p['credits'], $p['created_at']);
}

// 2. Recent wallet activity (₹1000 = -1000 credits)
echo "\n--- Wallet ledger entries of ₹-1000 in last 36h ---\n";
$st = db()->prepare("SELECT id, parent_id, amount, service_key, ref_id, created_at
                     FROM wallet_ledger
                     WHERE amount = -1000
                       AND datetime(created_at) >= datetime('now', '-36 hours')
                     ORDER BY id DESC LIMIT 10");
$st->execute();
$ledger = $st->fetchAll();
if (!$ledger) {
    echo "  None.\n";
} else {
    foreach ($ledger as $l) {
        echo sprintf("  #%-5d parent=%-3d amount=%-5d service=%-25s ref=%-5s when=%s\n",
            $l['id'], $l['parent_id'], $l['amount'], $l['service_key'], $l['ref_id'] ?: '-', $l['created_at']);
    }
}

// 3. ALL recent parent registrations
echo "\n--- All new parents in last 36h ---\n";
$st = db()->query("SELECT id, name, whatsapp, partner_id, credits, created_at
                   FROM parents
                   WHERE datetime(created_at) >= datetime('now', '-36 hours')
                   ORDER BY id DESC");
foreach ($st->fetchAll() as $p) {
    echo sprintf("  parent #%-3d name=%-25s phone=%-15s partner_id=%-5s credits=%-5d created=%s\n",
        $p['id'], $p['name'], $p['whatsapp'], $p['partner_id'] ?: 'NULL', $p['credits'], $p['created_at']);
}

// 4. partner_commissions in last 36h
echo "\n--- Recent partner_commissions ---\n";
try {
    $st = db()->query("SELECT id, partner_id, parent_id, source_type, gross_amount, commission, status, created_at
                       FROM partner_commissions
                       WHERE datetime(created_at) >= datetime('now', '-7 days')
                       ORDER BY id DESC LIMIT 15");
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  No commissions in last 7 days.\n";
    } else {
        foreach ($rows as $c) {
            echo sprintf("  #%-3d partner=%-3d parent=%-3d source=%-20s gross=%-5d commission=%-5d status=%-8s when=%s\n",
                $c['id'], $c['partner_id'], $c['parent_id'], $c['source_type'],
                $c['gross_amount'], $c['commission'], $c['status'], $c['created_at']);
        }
    }
} catch (Throwable $e) {
    echo "  partner_commissions query: " . $e->getMessage() . "\n";
}

// 5. List all partners with their referral codes
echo "\n--- All partners + counts ---\n";
$st = db()->query("SELECT p.id, p.name, p.referral_code, p.status,
                   (SELECT COUNT(*) FROM parents WHERE partner_id = p.id) AS referred_parents,
                   (SELECT COUNT(*) FROM partner_commissions WHERE partner_id = p.id) AS commission_rows
                   FROM partners p ORDER BY referred_parents DESC, p.id ASC LIMIT 25");
foreach ($st->fetchAll() as $p) {
    echo sprintf("  partner #%-3d name=%-30s code=%-12s status=%-12s referred=%-3d commissions=%d\n",
        $p['id'], $p['name'], $p['referral_code'] ?: '-', $p['status'],
        $p['referred_parents'], $p['commission_rows']);
}

// 6. Specifically check if there's session_id 25 ledger entry (the recent ₹1000 reflection charge)
echo "\n--- Service: mod_parent_reflect charges (last 36h) ---\n";
$st = db()->query("SELECT id, parent_id, amount, ref_id, created_at
                   FROM wallet_ledger
                   WHERE service_key = 'mod_parent_reflect'
                     AND amount < 0
                     AND datetime(created_at) >= datetime('now', '-36 hours')
                   ORDER BY id DESC LIMIT 10");
foreach ($st->fetchAll() as $l) {
    echo sprintf("  #%-5d parent=%-3d amount=%-5d session=%-3s when=%s\n",
        $l['id'], $l['parent_id'], $l['amount'], $l['ref_id'] ?: '-', $l['created_at']);
}

// 7. Service home_course_999 charges
echo "\n--- Service: home_course_999 charges (last 36h) ---\n";
$st = db()->query("SELECT id, parent_id, amount, ref_id, created_at
                   FROM wallet_ledger
                   WHERE service_key = 'home_course_999'
                     AND amount < 0
                     AND datetime(created_at) >= datetime('now', '-36 hours')
                   ORDER BY id DESC LIMIT 10");
foreach ($st->fetchAll() as $l) {
    echo sprintf("  #%-5d parent=%-3d amount=%-5d reflect_sid=%-3s when=%s\n",
        $l['id'], $l['parent_id'], $l['amount'], $l['ref_id'] ?: '-', $l['created_at']);
}

echo "\nDone. Delete this file after use.\n";
