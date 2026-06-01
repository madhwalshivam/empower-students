<?php
/**
 * /whatswrong.php — temporary diagnostic. DELETE after use.
 *
 * Shows the actual PHP error so we can fix the 500.
 */

// Force display of every error
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre style='font:14px monospace;background:#f5f5f5;padding:20px;line-height:1.6'>";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP SAPI:    " . PHP_SAPI . "\n";
echo "Document root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script dir:    " . __DIR__ . "\n\n";

echo "═══ STEP 1: List files in /admin/ ═══\n";
$adminDir = __DIR__ . '/admin';
if (is_dir($adminDir)) {
    $files = scandir($adminDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $size = filesize("$adminDir/$f");
        echo "  $f  ({$size} bytes)\n";
    }
} else {
    echo "  /admin/ not found!\n";
}
echo "\n";

echo "═══ STEP 2: List files in /includes/ ═══\n";
$incDir = __DIR__ . '/includes';
if (is_dir($incDir)) {
    foreach (scandir($incDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $size = filesize("$incDir/$f");
        echo "  $f  ({$size} bytes)\n";
    }
} else {
    echo "  /includes/ not found!\n";
}
echo "\n";

echo "═══ STEP 3: Try to include /admin/_admin.php and see what blows up ═══\n";
echo "(any PHP error below points to the broken file)\n\n";

try {
    // Mimic what /admin/index.php does
    require __DIR__ . '/admin/_admin.php';
    echo "✓ _admin.php loaded successfully — issue is NOT in _admin.php\n";
    echo "  Likely the issue is inside /admin/index.php itself.\n";
} catch (Throwable $e) {
    echo "✗ FATAL ERROR loading _admin.php:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . " line " . $e->getLine() . "\n";
}

echo "</pre>";
