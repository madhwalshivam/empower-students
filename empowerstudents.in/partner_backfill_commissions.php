<?php
/**
 * partner_backfill_commissions.php?key=nci2026admin
 *
 * One-shot back-fill. Scans wallet_ledger for all successful Cashfree top-ups
 * by partner-attributed parents, and creates partner_commissions rows for them.
 *
 * Idempotent — same row won't be created twice (uses source_id check inside
 * partner_record_topup_commission()).
 *
 * Mode:
 *   ?key=nci2026admin          → PREVIEW (no writes)
 *   ?key=nci2026admin&commit=1 → APPLY
 *   ?key=nci2026admin&commit=1&reset_rate=1 → also reset partner #35's revenue_share to NULL (so default 20% applies)
 *
 * Self-destruct after success. Delete this file after the back-fill is done.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403); echo "forbidden"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/partners.php';

$commit = (($_GET['commit'] ?? '') === '1');
$reset_rate = (($_GET['reset_rate'] ?? '') === '1');

echo $commit
    ? "=== COMMIT MODE — applying changes ===\n\n"
    : "=== PREVIEW MODE — no changes. Add &commit=1 to apply. ===\n\n";

// Optional: reset partner #35 revenue_share to NULL (default 20%)
if ($reset_rate) {
    if ($commit) {
        try {
            db()->prepare("UPDATE partners SET revenue_share = NULL WHERE id = 35")->execute();
            echo "✓ Reset partner #35 revenue_share to NULL (will use default 20%)\n\n";
        } catch (Throwable $e) {
            echo "✗ Reset failed: " . $e->getMessage() . "\n\n";
        }
    } else {
        echo "[would reset partner #35 revenue_share to NULL]\n\n";
    }
}

// 1. Find all successful Cashfree top-ups by partner-attributed parents
echo "--- Scanning past Cashfree top-ups for attributed parents ---\n";
$st = db()->query("SELECT wl.id AS ledger_id, wl.parent_id, wl.amount, wl.ref_id, wl.created_at,
                          p.name AS parent_name, p.partner_id
                   FROM wallet_ledger wl
                   JOIN parents p ON p.id = wl.parent_id
                   WHERE wl.service_key = 'wallet_topup'
                     AND wl.created_by  = 'cashfree'
                     AND wl.amount > 0
                     AND p.partner_id IS NOT NULL
                   ORDER BY wl.id ASC");

$topups = $st->fetchAll();
if (!$topups) {
    echo "  No commissionable top-ups found.\n";
    echo "\nDone.\n";
    exit;
}

echo "  Found " . count($topups) . " top-up(s).\n\n";

$created = 0;
$already = 0;
$nopartner = 0;
$total_commission = 0;
$by_partner = [];

foreach ($topups as $t) {
    if ($commit) {
        $r = partner_record_topup_commission(
            (int)$t['parent_id'],
            (int)$t['amount'],
            (int)$t['ledger_id']
        );
        $status = $r['status'] ?? '';

        if ($status === 'created') {
            $created++;
            $total_commission += (int)$r['commission'];
            $pid = (int)$r['partner_id'];
            if (!isset($by_partner[$pid])) $by_partner[$pid] = ['count'=>0,'total'=>0];
            $by_partner[$pid]['count']++;
            $by_partner[$pid]['total'] += (int)$r['commission'];
            $msg = sprintf("  ✓ CREATED: parent #%-3d (%s) topup Rs.%d → partner #%-3d commission Rs.%d (%d%%)",
                $t['parent_id'], $t['parent_name'], $t['amount'],
                $r['partner_id'], $r['commission'], $r['pct']);
        } elseif ($status === 'already') {
            $already++;
            $msg = sprintf("  · already exists: parent #%-3d (%s) topup Rs.%d ledger #%d",
                $t['parent_id'], $t['parent_name'], $t['amount'], $t['ledger_id']);
        } elseif ($status === 'no_partner') {
            $nopartner++;
            $msg = sprintf("  ⚠ no_partner: parent #%d (%s) — partner_id was set but partner record missing",
                $t['parent_id'], $t['parent_name']);
        } else {
            $msg = sprintf("  ? %s: parent #%d", $status, $t['parent_id']);
        }
    } else {
        // Preview: compute what WOULD happen
        $ps = db()->prepare("SELECT revenue_share FROM partners WHERE id = ?");
        $ps->execute([(int)$t['partner_id']]);
        $row = $ps->fetch();
        $pct = partner_effective_commission_pct($row ?: []);
        $comm = (int) round((int)$t['amount'] * $pct / 100);

        // Check if already exists
        $chk = db()->prepare("SELECT id FROM partner_commissions
                              WHERE partner_id = ? AND source_type = 'topup' AND source_id = ? LIMIT 1");
        $chk->execute([(int)$t['partner_id'], (int)$t['ledger_id']]);
        $exists = (bool)$chk->fetchColumn();

        if ($exists) {
            $already++;
            $msg = sprintf("  · already exists: parent #%-3d (%s) topup Rs.%d ledger #%d",
                $t['parent_id'], $t['parent_name'], $t['amount'], $t['ledger_id']);
        } else {
            $created++;
            $total_commission += $comm;
            $pid = (int)$t['partner_id'];
            if (!isset($by_partner[$pid])) $by_partner[$pid] = ['count'=>0,'total'=>0];
            $by_partner[$pid]['count']++;
            $by_partner[$pid]['total'] += $comm;
            $msg = sprintf("  [WOULD CREATE] parent #%-3d (%s) topup Rs.%d → partner #%-3d commission Rs.%d (%d%%)",
                $t['parent_id'], $t['parent_name'], $t['amount'], $pid, $comm, $pct);
        }
    }
    echo $msg . "\n";
}

echo "\n=== Summary ===\n";
echo ($commit ? "Created"      : "Would create") . ": $created\n";
echo "Already existed: $already\n";
if ($nopartner) echo "No-partner skips: $nopartner\n";
echo "Total " . ($commit ? "" : "expected ") . "commission: Rs.$total_commission\n";

if ($by_partner) {
    echo "\nBy partner:\n";
    foreach ($by_partner as $pid => $stats) {
        $pst = db()->prepare("SELECT name FROM partners WHERE id = ?");
        $pst->execute([$pid]);
        $pname = $pst->fetchColumn() ?: "?";
        echo sprintf("  partner #%-3d (%s): %d top-up(s), Rs.%d commission\n",
            $pid, $pname, $stats['count'], $stats['total']);
    }
}

echo "\n" . ($commit ? "✅ Back-fill complete. DELETE this file." : "Run with &commit=1 to apply.") . "\n";
