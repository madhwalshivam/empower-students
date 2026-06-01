<?php
/**
 * partner_setup_diag.php?key=nci2026admin&id=37[&commit=1][&activate=1]
 *
 * Diagnoses why "Generate password setup link" fails.
 * - Loads partner #37
 * - Tries to call partner_generate_setup_token()
 * - Shows the exact error if any
 *
 * Default mode is DRY (no DB write). Add &commit=1 to actually issue token.
 *
 * Delete this file after debugging.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403); echo "forbidden"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();

$id = (int)($_GET['id'] ?? 37);
$commit = (($_GET['commit'] ?? '') === '1');
$activate = (($_GET['activate'] ?? '') === '1');

echo "=== PARTNER SETUP DIAGNOSTIC ===\n\n";
echo "Mode: " . ($commit ? "COMMIT" : "DRY") . "\n";
echo "Partner id: $id\n";
echo "Activate too: " . ($activate ? "yes" : "no") . "\n\n";

// 1. Load partner
$st = db()->prepare("SELECT * FROM partners WHERE id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { echo "✗ Partner $id not found\n"; exit; }

echo "--- Partner row ---\n";
echo "  name: " . $p['name'] . "\n";
echo "  whatsapp: " . ($p['whatsapp'] ?: '(empty)') . "\n";
echo "  status: " . $p['status'] . "\n";
echo "  password_hash: " . (!empty($p['password_hash']) ? "(set)" : "(empty)") . "\n";
echo "  password_setup_token: " . (!empty($p['password_setup_token']) ? "(set)" : "(empty)") . "\n";

// 2. Confirm required columns exist on `partners`
echo "\n--- Schema check ---\n";
$cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
$names = array_column($cols, 'name');
foreach (['password_hash','password_setup_token','password_setup_token_at','last_login_at'] as $c) {
    echo "  $c: " . (in_array($c, $names, true) ? "✓ exists" : "✗ MISSING") . "\n";
}

// 3. Load partner_auth.php and check function
echo "\n--- partner_auth.php load ---\n";
try {
    require_once __DIR__ . '/includes/partner_auth.php';
    echo "  ✓ partner_auth.php loaded\n";
    if (function_exists('partner_generate_setup_token')) {
        echo "  ✓ partner_generate_setup_token exists\n";
    } else {
        echo "  ✗ partner_generate_setup_token MISSING\n";
        exit;
    }
} catch (Throwable $e) {
    echo "  ✗ load failed: " . $e->getMessage() . "\n";
    exit;
}

// 4. Try issuing a token
echo "\n--- Token generation ---\n";
if (!$commit) {
    echo "  (DRY MODE — no write performed)\n";
    echo "  Would attempt: UPDATE partners SET password_setup_token=?, password_setup_token_at=CURRENT_TIMESTAMP WHERE id=$id\n";
    if ($activate) {
        echo "  Would attempt: UPDATE partners SET status='active' WHERE id=$id\n";
    }
} else {
    try {
        $token = partner_generate_setup_token($id);
        echo "  ✓ Token issued: $token\n";
        echo "  URL: https://empowerstudents.in/partner-set-password.php?token=$token\n";

        if ($activate) {
            $upd = db()->prepare("UPDATE partners SET status = 'active', updated_at = CURRENT_TIMESTAMP
                                  WHERE id = ? AND status IN ('pending','cold','messaged','interested')");
            $upd->execute([$id]);
            $r = $upd->rowCount();
            echo "  ✓ Activate UPDATE ran (rows affected: $r)\n";
        }

        // re-read
        $st->execute([$id]);
        $p2 = $st->fetch();
        echo "\n--- After ---\n";
        echo "  status: " . $p2['status'] . "\n";
        echo "  password_setup_token: " . substr($p2['password_setup_token'] ?? '', 0, 16) . "...\n";
        echo "  password_setup_token_at: " . ($p2['password_setup_token_at'] ?? '(null)') . "\n";
    } catch (Throwable $e) {
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
        echo "  Trace:\n" . $e->getTraceAsString() . "\n";
    }
}

echo "\nDone. Delete this file after debugging.\n";
