<?php
/**
 * pr_status.php?key=nci2026admin
 *
 * One-shot deployment status checker. Shows what's currently deployed
 * on production for parent-reflect, so we don't guess.
 *
 * Shows:
 *  - Frontend build stamp in parent-reflect.php
 *  - Which patcher markers are present in each file
 *  - Current service_price for mod_parent_reflect
 *  - Recent sessions for parent_id=1 (Isha) with turn counts
 *  - Wallet balance for parent_id=1
 *
 * Read-only. Safe.
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

echo "=== PARENT-REFLECT DEPLOYMENT STATUS ===\n\n";

// ─── 1. Frontend build stamp ──────────────────────────────────
echo "--- FRONTEND (parent-reflect.php) ---\n";
$pr_file = __DIR__ . '/parent-reflect.php';
if (!file_exists($pr_file)) {
    echo "❌ parent-reflect.php missing\n\n";
} else {
    $pr_src = file_get_contents($pr_file);
    if (preg_match("/parent-reflect\\.php build: ([^']+)'/", $pr_src, $m)) {
        echo "  Build stamp: " . $m[1] . "\n";
    } else {
        echo "  Build stamp: NOT FOUND (very old file)\n";
    }
    echo "  File size:   " . number_format(filesize($pr_file)) . " bytes\n";
    echo "  Modified:    " . date('Y-m-d H:i:s', filemtime($pr_file)) . "\n";

    // Patcher markers
    $markers = [
        'pr-text-only-v1'        => 'text-only engine',
        'pr-resume-history-v1'   => 'history-on-resume',
        'pr-pause-finish-v1'     => 'pause-stays + finish-now',
        'pr-charge-on-finish-v1' => 'charge ₹1000 at finalize',
    ];
    echo "  Patches applied:\n";
    foreach ($markers as $marker => $label) {
        $present = strpos($pr_src, $marker) !== false;
        echo "    " . ($present ? '✓' : '·') . " $marker  ($label)\n";
    }

    // Look for old fingerprints that should NOT be there
    $obsolete = [
        'iSafetyRow' => 'old safety button (should be removed)',
        'iListenBtn' => 'old listen button',
        'iSpeakBtn'  => 'old speak button',
        'speakText'  => 'old TTS function',
        'apply_pr_no_autoplay_v1' => 'old patcher marker',
    ];
    $found_obsolete = [];
    foreach ($obsolete as $needle => $label) {
        if (strpos($pr_src, $needle) !== false) $found_obsolete[] = "$needle ($label)";
    }
    if ($found_obsolete) {
        echo "  ⚠ Obsolete fingerprints present:\n";
        foreach ($found_obsolete as $f) echo "      $f\n";
    } else {
        echo "  ✓ No obsolete fingerprints\n";
    }
}
echo "\n";

// ─── 2. API file status ───────────────────────────────────────
echo "--- API (parent-reflect-api.php) ---\n";
$api_file = __DIR__ . '/parent-reflect-api.php';
if (!file_exists($api_file)) {
    echo "❌ parent-reflect-api.php missing\n\n";
} else {
    $api_src = file_get_contents($api_file);
    echo "  File size: " . number_format(filesize($api_file)) . " bytes\n";
    echo "  Modified:  " . date('Y-m-d H:i:s', filemtime($api_file)) . "\n";
    echo "  Patches applied:\n";
    foreach ($markers as $marker => $label) {
        $present = strpos($api_src, $marker) !== false;
        echo "    " . ($present ? '✓' : '·') . " $marker  ($label)\n";
    }
    // Specific behaviors
    echo "  Behaviors:\n";
    echo "    " . (strpos($api_src, "_pr_charge_on_finalize") !== false ? '✓' : '·')
       . " charge_on_finalize helper present\n";
    echo "    " . (strpos($api_src, "'finish_early'") !== false ? '✓' : '·')
       . " finish_early action present\n";
    echo "    " . (strpos($api_src, "prior_turns") !== false ? '✓' : '·')
       . " prior_turns in resume response\n";
    echo "    " . (strpos($api_src, "'paused' => true") !== false ? '✓' : '·')
       . " cancel-becomes-pause behavior\n";
}
echo "\n";

// ─── 3. Engine file ───────────────────────────────────────────
echo "--- ENGINE (includes/parent_reflect_engine.php) ---\n";
$eng_file = __DIR__ . '/includes/parent_reflect_engine.php';
if (!file_exists($eng_file)) {
    echo "❌ engine missing\n\n";
} else {
    $eng_src = file_get_contents($eng_file);
    echo "  Patches applied:\n";
    foreach ($markers as $marker => $label) {
        $present = strpos($eng_src, $marker) !== false;
        echo "    " . ($present ? '✓' : '·') . " $marker  ($label)\n";
    }
    echo "  Behaviors:\n";
    echo "    " . (strpos($eng_src, 'next_options') !== false ? '✓' : '·')
       . " next_options in prompt\n";
    echo "    " . (strpos($eng_src, 'CRITICAL NO-REPEAT RULE') !== false ? '✓' : '·')
       . " no-repeat instruction in prompt\n";
    echo "    " . (strpos($eng_src, 'ROTATE landmarks') !== false ? '✓' : '·')
       . " landmark rotation guidance\n";
    echo "    " . (strpos($eng_src, "'options'  =>") !== false ? '✓' : '·')
       . " engine passes options through next_turn\n";
}
echo "\n";

// ─── 4. Service price ─────────────────────────────────────────
echo "--- SERVICE PRICE ---\n";
try {
    $st = db()->prepare("SELECT price, label, is_active FROM service_prices WHERE service_key = ?");
    $st->execute(['mod_parent_reflect']);
    $row = $st->fetch();
    if ($row) {
        echo "  Price:    ₹" . (int)$row['price'] . "\n";
        echo "  Label:    " . $row['label'] . "\n";
        echo "  Active:   " . ((int)$row['is_active'] ? 'yes' : 'NO') . "\n";
    } else {
        echo "  ❌ No service_prices row for mod_parent_reflect\n";
    }
} catch (Throwable $e) {
    echo "  ⚠ " . $e->getMessage() . "\n";
}
echo "\n";

// ─── 5. Parent #1 status ──────────────────────────────────────
echo "--- PARENT #1 (Isha) ---\n";
$pp = db()->prepare("SELECT id, name, credits FROM parents WHERE id = 1");
$pp->execute();
$par = $pp->fetch();
if ($par) {
    echo "  Name:    " . $par['name'] . "\n";
    echo "  Wallet:  ₹" . (int)$par['credits'] . "\n";
} else {
    echo "  ❌ no parent #1\n";
}

// ─── 6. Recent sessions for parent #1 ─────────────────────────
echo "\n--- RECENT SESSIONS (parent #1) ---\n";
try {
    $st = db()->prepare("SELECT s.id, s.status, s.turn_count, s.current_phase,
                                s.started_at, s.completed_at, s.cost_paid,
                                (SELECT COUNT(*) FROM parent_reflect_turns t
                                 WHERE t.session_id = s.id AND t.transcript IS NOT NULL AND t.transcript != '') as answered
                         FROM parent_reflect_sessions s
                         WHERE s.parent_id = 1
                         ORDER BY s.id DESC LIMIT 5");
    $st->execute();
    $sessions = $st->fetchAll();
    if (!$sessions) {
        echo "  (no sessions)\n";
    } else {
        foreach ($sessions as $s) {
            echo sprintf("  #%-4d status=%-12s turn_count=%-3d answered=%-3d phase=%-2d  paid=₹%-4d  started=%s  completed=%s\n",
                $s['id'], $s['status'], $s['turn_count'], $s['answered'], $s['current_phase'],
                $s['cost_paid'], $s['started_at'], $s['completed_at'] ?: '-');
        }
    }
} catch (Throwable $e) {
    echo "  ⚠ " . $e->getMessage() . "\n";
}

// ─── 7. Recent wallet ledger for parent #1 ────────────────────
echo "\n--- RECENT WALLET LEDGER (parent #1) ---\n";
try {
    $st = db()->prepare("SELECT id, amount, balance_after, service_key, ref_id, reason, created_at
                         FROM wallet_ledger WHERE parent_id = 1
                         ORDER BY id DESC LIMIT 8");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "  (no entries)\n";
    } else {
        foreach ($rows as $r) {
            $sign = $r['amount'] >= 0 ? '+' : '';
            echo sprintf("  #%-5d %s₹%-5d bal_after=₹%-5d  %-30s ref=%-5s  %s\n",
                $r['id'], $sign, $r['amount'], $r['balance_after'],
                $r['service_key'], $r['ref_id'] ?: '-', $r['created_at']);
        }
    }
} catch (Throwable $e) {
    echo "  ⚠ " . $e->getMessage() . "\n";
}

echo "\nDone. Share this output to know exactly what's deployed.\n";
echo "DELETE this file after use.\n";
