<?php
/**
 * /whatswrong2.php — diagnostic v2.
 *
 * Reads /admin/error_log (which has the actual fatal error)
 * and tries to syntax-check /admin/index.php.
 */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<pre style='font:13px monospace;background:#f5f5f5;padding:20px;line-height:1.6;white-space:pre-wrap'>";

echo "═══ /admin/error_log (LAST 50 LINES) ═══\n";
$logPath = __DIR__ . '/admin/error_log';
if (is_file($logPath)) {
    $lines = file($logPath);
    $tail = array_slice($lines, max(0, count($lines) - 50));
    foreach ($tail as $line) {
        echo htmlspecialchars($line);
    }
} else {
    echo "  (no error_log file at $logPath)\n";
}
echo "\n";

echo "═══ Lint /admin/index.php ═══\n";
$indexPath = __DIR__ . '/admin/index.php';
if (is_file($indexPath)) {
    // Use php -l via shell to check syntax
    $out = [];
    @exec('php -l ' . escapeshellarg($indexPath) . ' 2>&1', $out, $rc);
    foreach ($out as $line) echo htmlspecialchars($line) . "\n";
    if ($rc !== 0) {
        echo "\n  ⚠ syntax error confirmed\n";
    } else {
        echo "  ✓ no syntax error — issue is at runtime\n";
    }
} else {
    echo "  (file not found)\n";
}
echo "\n";

echo "═══ FIRST 60 LINES of /admin/index.php ═══\n";
if (is_file($indexPath)) {
    $lines = file($indexPath);
    $head = array_slice($lines, 0, 60);
    foreach ($head as $i => $line) {
        printf("%3d │ %s", $i + 1, htmlspecialchars($line));
    }
}
echo "\n";

echo "═══ Try to actually run /admin/index.php here ═══\n";
echo "(if it dies, the error message will appear right after this)\n\n";

// Fake an admin session so the file doesn't redirect
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_user'] = 'debug';

ob_start();
try {
    include __DIR__ . '/admin/index.php';
    $captured = ob_get_clean();
    echo "✓ /admin/index.php ran without fatal error.\n";
    echo "  Output size: " . strlen($captured) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "✗ FATAL: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "  in " . htmlspecialchars($e->getFile()) . " line " . $e->getLine() . "\n";
    echo "  Stack trace:\n" . htmlspecialchars($e->getTraceAsString()) . "\n";
}

echo "</pre>";
