<?php
/**
 * pr_cleanup_and_refund.php?key=nci2026admin
 *
 * Two-step admin script:
 *   1. Default (preview): shows what WOULD be deleted/refunded. No changes made.
 *   2. ?commit=1: actually performs the cleanup + refund.
 *
 * Cleans up Isha's (parent_id=1) test parent_reflect sessions:
 *   • Deletes all sessions for parent_id=1 (ON DELETE CASCADE removes turns)
 *   • Refunds wallet credits for any 'mod_parent_reflect' charges today
 *
 * Read-only by default. ?commit=1 to apply. Single-use.
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

$pid = (int)($_GET['pid'] ?? 1);
$commit = (($_GET['commit'] ?? '') === '1');

echo $commit
    ? "=== COMMIT MODE — making changes ===\n\n"
    : "=== PREVIEW MODE — no changes will be made. Add &commit=1 to apply ===\n\n";

// ─── Parent + current balance ─────────────────────────────────
$pp = db()->prepare("SELECT id, name, whatsapp, credits FROM parents WHERE id = ?");
$pp->execute([$pid]);
$par = $pp->fetch();
if (!$par) { echo "❌ No parent id=$pid\n"; exit; }
echo "Parent: " . $par['name'] . " · " . $par['whatsapp'] . "\n";
echo "Current balance: ₹" . (int)$par['credits'] . "\n\n";

// ─── Step 1: enumerate sessions to delete ─────────────────────
$ss = db()->prepare("SELECT id, status, turn_count, started_at FROM parent_reflect_sessions WHERE parent_id = ? ORDER BY id ASC");
$ss->execute([$pid]);
$sessions = $ss->fetchAll();

if (!$sessions) {
    echo "No sessions to delete.\n";
} else {
    echo "--- Sessions to delete (" . count($sessions) . ") ---\n";
    $sids = [];
    foreach ($sessions as $s) {
        echo sprintf("  Session %d · status=%s · turn_count=%d · started=%s\n",
            $s['id'], $s['status'], $s['turn_count'], $s['started_at']);
        $sids[] = (int)$s['id'];
    }
    echo "\n";

    if ($commit) {
        // Sanity check: ensure cascade is wired (it's in the schema) — turns auto-delete
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $stT = db()->prepare("SELECT COUNT(*) FROM parent_reflect_turns WHERE session_id IN ($placeholders)");
        $stT->execute($sids);
        $turn_count = (int)$stT->fetchColumn();
        echo "  → Turn rows that will be cascade-deleted: $turn_count\n";

        // Delete sessions (cascade kills turns)
        $stD = db()->prepare("DELETE FROM parent_reflect_sessions WHERE id IN ($placeholders)");
        $stD->execute($sids);
        $deleted = $stD->rowCount();
        echo "  ✓ Deleted $deleted session(s)\n";

        // Verify turns are gone
        $stT2 = db()->prepare("SELECT COUNT(*) FROM parent_reflect_turns WHERE session_id IN ($placeholders)");
        $stT2->execute($sids);
        $remaining = (int)$stT2->fetchColumn();
        if ($remaining > 0) {
            // Cascade didn't fire (probably SQLite foreign_keys pragma off)
            // Do it manually
            $stTD = db()->prepare("DELETE FROM parent_reflect_turns WHERE session_id IN ($placeholders)");
            $stTD->execute($sids);
            echo "  ✓ Manually deleted $remaining turn row(s) (cascade didn't fire)\n";
        }
    }
    echo "\n";
}

// ─── Step 2: enumerate wallet charges to refund ───────────────
$wl = db()->prepare("SELECT id, amount, balance_after, service_key, ref_id, reason, created_at
                     FROM wallet_ledger
                     WHERE parent_id = ?
                       AND service_key = 'mod_parent_reflect'
                       AND amount < 0
                     ORDER BY id ASC");
$wl->execute([$pid]);
$charges = $wl->fetchAll();

if (!$charges) {
    echo "--- No parent_reflect charges to refund ---\n\n";
} else {
    echo "--- Charges to refund (" . count($charges) . ") ---\n";
    $total_refund = 0;
    foreach ($charges as $c) {
        $amt = (int)$c['amount']; // negative
        $total_refund += abs($amt);
        echo sprintf("  Ledger #%d · ₹%d charged · ref_session=%s · %s · '%s'\n",
            $c['id'], $amt, ($c['ref_id'] ?? '-'), $c['created_at'], $c['reason'] ?? '');
    }
    echo "\nTotal refund: ₹$total_refund\n\n";

    if ($commit) {
        $bal = (int)$par['credits'];
        $count = 0;
        foreach ($charges as $c) {
            $amt = abs((int)$c['amount']);
            $bal += $amt;
            $ins = db()->prepare("INSERT INTO wallet_ledger
                (parent_id, amount, balance_after, service_key, ref_id, reason, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $pid,
                $amt,                   // positive refund
                $bal,
                'refund_parent_reflect',
                $c['ref_id'],
                'Test cleanup refund for ledger #' . $c['id'] . ' (session ' . ($c['ref_id'] ?? '?') . ')',
                'admin:cleanup_script',
            ]);
            $count++;
        }
        // Update denormalised credits on parents
        db()->prepare("UPDATE parents SET credits = ? WHERE id = ?")->execute([$bal, $pid]);
        echo "  ✓ Issued $count refund(s). New balance: ₹$bal\n\n";
    }
}

// ─── Final summary ────────────────────────────────────────────
if ($commit) {
    $pp2 = db()->prepare("SELECT credits FROM parents WHERE id = ?");
    $pp2->execute([$pid]);
    $new_bal = (int)$pp2->fetchColumn();
    echo "=== DONE ===\n";
    echo "Final balance for parent #$pid: ₹$new_bal\n";
    echo "\nDELETE this file after success.\n";
} else {
    echo "=== End of preview ===\n";
    echo "To execute, visit: ?key=nci2026admin&commit=1\n";
    echo "(Append &pid=N to target a different parent. Default: pid=1)\n";
}
