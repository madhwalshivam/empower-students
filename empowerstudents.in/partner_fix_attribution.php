<?php
/**
 * partner_fix_attribution.php?key=nci2026admin
 *
 * One-shot correction:
 *   1. Set parents.partner_id = NULL for parent IDs 1 (Dr P K Jha), 4 (Sanjana), 5 (Jyoti)
 *      — they were incorrectly attributed to partner #35 (Genesis NeuroGen).
 *   2. Delete partner_commissions rows where parent_id IN (1, 4, 5) AND partner_id = 35.
 *
 * After this, partner #35 will show only Isha (#7) and PIYUSH (#6) on the detail page.
 *
 * Modes:
 *   ?key=nci2026admin            → PREVIEW (no writes)
 *   ?key=nci2026admin&commit=1   → APPLY
 *
 * Idempotent. Delete this file after running.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403); echo "forbidden"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();

$commit = (($_GET['commit'] ?? '') === '1');

echo $commit
    ? "=== COMMIT MODE — applying changes ===\n\n"
    : "=== PREVIEW MODE — no changes. Add &commit=1 to apply. ===\n\n";

$parents_to_fix = [1, 4, 5];   // Dr P K Jha, Sanjana, Jyoti
$incorrect_partner = 35;       // Genesis NeuroGen

// ─── 1. Show current state ─────────────────────────────────────
echo "--- Current parents.partner_id state ---\n";
$ph = implode(',', array_fill(0, count($parents_to_fix), '?'));
$st = db()->prepare("SELECT id, name, whatsapp, partner_id FROM parents WHERE id IN ($ph) ORDER BY id");
$st->execute($parents_to_fix);
$parents = $st->fetchAll();
foreach ($parents as $p) {
    echo sprintf("  parent #%-3d %-20s phone=%-15s currently partner_id=%s\n",
        $p['id'], $p['name'], $p['whatsapp'], $p['partner_id'] ?: 'NULL');
}

// ─── 2. Show commission rows that would be removed ────────────
echo "\n--- Commission rows to delete (parent in [1,4,5] AND partner=35) ---\n";
$cs = db()->prepare("SELECT id, partner_id, parent_id, source_type, gross_amount, commission, status, created_at
                     FROM partner_commissions
                     WHERE parent_id IN ($ph) AND partner_id = ?");
$cs->execute(array_merge($parents_to_fix, [$incorrect_partner]));
$comms = $cs->fetchAll();
if (!$comms) {
    echo "  (none)\n";
} else {
    foreach ($comms as $c) {
        echo sprintf("  comm #%-3d partner=%-3d parent=%-3d source=%-10s gross=Rs.%-5d commission=Rs.%-5d status=%s\n",
            $c['id'], $c['partner_id'], $c['parent_id'], $c['source_type'],
            $c['gross_amount'], $c['commission'], $c['status']);
    }
}

// ─── 3. APPLY (if commit) ─────────────────────────────────────
if ($commit) {
    echo "\n--- Applying ---\n";

    db()->beginTransaction();
    try {
        // Step A: NULL out partner_id
        $upd = db()->prepare("UPDATE parents SET partner_id = NULL
                              WHERE id IN ($ph) AND partner_id = ?");
        $upd->execute(array_merge($parents_to_fix, [$incorrect_partner]));
        $nulled = $upd->rowCount();
        echo "  ✓ Nullified partner_id for $nulled parent(s)\n";

        // Step B: Delete commission rows
        $del = db()->prepare("DELETE FROM partner_commissions
                              WHERE parent_id IN ($ph) AND partner_id = ?");
        $del->execute(array_merge($parents_to_fix, [$incorrect_partner]));
        $deleted = $del->rowCount();
        echo "  ✓ Deleted $deleted commission row(s)\n";

        db()->commit();
        echo "\n✅ Committed.\n";
    } catch (Throwable $e) {
        db()->rollBack();
        echo "  ✗ Rolled back: " . $e->getMessage() . "\n";
        exit;
    }

    // ─── 4. Verify post-state ─────────────────────────────────
    echo "\n--- Verification ---\n";
    $st = db()->prepare("SELECT id, name, partner_id FROM parents WHERE id IN ($ph) ORDER BY id");
    $st->execute($parents_to_fix);
    foreach ($st->fetchAll() as $p) {
        $ok = ($p['partner_id'] === null || $p['partner_id'] === '') ? '✓' : '✗';
        echo sprintf("  $ok parent #%-3d %-20s partner_id=%s\n",
            $p['id'], $p['name'], $p['partner_id'] === null ? 'NULL' : $p['partner_id']);
    }

    // Partner #35 — who's left?
    echo "\n--- Partner #35 referrals after fix ---\n";
    $rs = db()->prepare("SELECT id, name, whatsapp FROM parents WHERE partner_id = ? ORDER BY id");
    $rs->execute([$incorrect_partner]);
    $remaining = $rs->fetchAll();
    if (!$remaining) {
        echo "  (none — partner #35 has no attributed parents)\n";
    } else {
        foreach ($remaining as $r) {
            echo sprintf("  parent #%-3d %-25s %s\n", $r['id'], $r['name'], $r['whatsapp']);
        }
    }

    // Partner #35 — commissions left?
    echo "\n--- Partner #35 commissions after fix ---\n";
    $cs = db()->prepare("SELECT id, parent_id, gross_amount, commission, status
                         FROM partner_commissions WHERE partner_id = ?");
    $cs->execute([$incorrect_partner]);
    $rest = $cs->fetchAll();
    if (!$rest) {
        echo "  (none)\n";
    } else {
        $total_pending = 0;
        foreach ($rest as $c) {
            echo sprintf("  comm #%-3d parent=%-3d gross=Rs.%-5d commission=Rs.%-5d status=%s\n",
                $c['id'], $c['parent_id'], $c['gross_amount'], $c['commission'], $c['status']);
            if ($c['status'] === 'pending') $total_pending += (int)$c['commission'];
        }
        echo "  Total pending: Rs.$total_pending\n";
    }

    echo "\nDELETE this file after success.\n";
} else {
    echo "\nRun again with &commit=1 to apply.\n";
}
