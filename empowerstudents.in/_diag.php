<?php
/**
 * _diag.php — drop at the site root (same folder as index.php).
 * Open https://empowerstudents.in/_diag.php in your browser.
 * Read the report, fix what's red, then DELETE this file.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

function row($label, $ok, $detail = '') {
    $color = $ok ? '#059669' : '#dc2626';
    $icon  = $ok ? '✓' : '✗';
    echo "<tr><td style='padding:6px 10px;border-bottom:1px solid #eee'>$label</td>"
       . "<td style='padding:6px 10px;border-bottom:1px solid #eee;color:$color;font-weight:700'>$icon</td>"
       . "<td style='padding:6px 10px;border-bottom:1px solid #eee;font-family:monospace;font-size:12px'>"
       . htmlspecialchars($detail) . "</td></tr>";
}

echo "<html><body style='font-family:system-ui;max-width:900px;margin:30px auto;padding:0 16px'>";
echo "<h1>Empower Students — server diagnostic</h1>";
echo "<table style='border-collapse:collapse;width:100%;background:#fafafa;border:1px solid #ddd'>";

// 1. PHP version
$php_ok = version_compare(PHP_VERSION, '7.4', '>=');
row('PHP version', $php_ok, PHP_VERSION);

// 2. Extensions
foreach (['pdo', 'pdo_sqlite', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
    row("Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'NOT LOADED — enable in cPanel → Select PHP Version → Extensions');
}

// 3. Document root + script paths
row('Document root', true, $_SERVER['DOCUMENT_ROOT'] ?? '?');
row('Script dir',    true, __DIR__);

// 4. data + uploads dirs
$data_dir    = __DIR__ . '/data';
$uploads_dir = __DIR__ . '/uploads';
foreach ([$data_dir, $uploads_dir] as $d) {
    $exists  = is_dir($d);
    $writable = $exists && is_writable($d);
    row("Dir exists: " . basename($d), $exists, $d);
    row("Dir writable: " . basename($d), $writable, $writable ? 'yes' : 'NO — chmod 775 ' . basename($d));
}

// 5. session save path
$sp = session_save_path() ?: sys_get_temp_dir();
row('Session save path', is_writable($sp), $sp);

// 6. Try to require config + db
echo "</table><h2>Loading framework…</h2><pre style='background:#111;color:#a7f3d0;padding:14px;border-radius:8px;overflow:auto'>";
try {
    require_once __DIR__ . '/includes/config.php';
    echo "config.php loaded — SITE_NAME=" . SITE_NAME . "\n";
    require_once __DIR__ . '/includes/db.php';
    echo "db.php loaded\n";
    $pdo = db();
    echo "db() opened SQLite at " . DB_PATH . "\n";
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables present: " . (count($tables) ? implode(', ', $tables) : '(none — install.php has not been run yet)') . "\n";
    require_once __DIR__ . '/includes/wallet.php';
    echo "wallet.php loaded\n";
    require_once __DIR__ . '/includes/cashfree.php';
    echo "cashfree.php loaded\n";
    require_once __DIR__ . '/includes/sms.php';
    echo "sms.php loaded\n";
    require_once __DIR__ . '/includes/auth.php';
    echo "auth.php loaded\n";
    require_once __DIR__ . '/includes/claude.php';
    echo "claude.php loaded\n";
    echo "\nAll framework files parse OK.";
} catch (Throwable $e) {
    echo "❌ FATAL: " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . " line " . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
}
echo "</pre>";

// 7. Try to render the actual homepage in a sandbox to capture its error
echo "<h2>Trying to render homepage…</h2><pre style='background:#111;color:#fde68a;padding:14px;border-radius:8px;overflow:auto;max-height:400px'>";
try {
    ob_start();
    // Don't actually output the HTML — just see if it throws
    include __DIR__ . '/index.php';
    $html = ob_get_clean();
    echo "✓ index.php rendered " . strlen($html) . " bytes without throwing.";
} catch (Throwable $e) {
    @ob_end_clean();
    echo "❌ FATAL in index.php: " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . " line " . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
}
echo "</pre>";

echo "<p style='color:#dc2626;font-weight:700;margin-top:20px'>⚠ DELETE this file (_diag.php) once you've read the report.</p>";
echo "</body></html>";
