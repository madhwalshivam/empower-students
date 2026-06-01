<?php
/**
 * diag_leda.php — diagnostic for Leda audio 403 issue.
 *
 * Visit this in a browser. Shows:
 *   • Whether service account exists + is readable
 *   • Whether /uploads/leda exists and is writable
 *   • Whether MP3 files exist there
 *   • Whether a fresh synthesis works
 *   • Whether the resulting URL is fetchable from same server
 *
 * DELETE after use.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/leda_tts.php';

echo "=== Leda Audio Diagnostic ===\n\n";

// 1. Service account
echo "1. Service account\n";
echo "   defined LEDA_SA_PATH: " . (defined('LEDA_SA_PATH') ? LEDA_SA_PATH : '(not defined)') . "\n";
echo "   is_readable: " . (defined('LEDA_SA_PATH') && is_readable(LEDA_SA_PATH) ? 'YES' : 'NO') . "\n";
echo "   leda_tts_is_configured(): " . (leda_tts_is_configured() ? 'YES' : 'NO') . "\n\n";

// 2. Upload directory
$dir_fs = defined('LEDA_CACHE_DIR_FS') ? LEDA_CACHE_DIR_FS : __DIR__ . '/uploads/leda';
echo "2. Upload directory: $dir_fs\n";
echo "   exists: " . (is_dir($dir_fs) ? 'YES' : 'NO') . "\n";
echo "   writable: " . (is_writable($dir_fs) ? 'YES' : (is_dir($dir_fs) ? 'NO — chmod 775 needed' : 'N/A — does not exist')) . "\n";
if (is_dir($dir_fs)) {
    $perms = substr(sprintf('%o', fileperms($dir_fs)), -4);
    echo "   permissions: $perms\n";
    $files = glob("$dir_fs/*.mp3");
    echo "   existing MP3 count: " . count($files) . "\n";
    if (count($files) > 0) {
        $first = $files[0];
        echo "   first file: " . basename($first) . " (" . filesize($first) . " bytes, perms " . substr(sprintf('%o', fileperms($first)), -4) . ")\n";
    }
}
echo "\n";

// 3. .htaccess presence walking up
echo "3. .htaccess in path\n";
$paths_to_check = [
    __DIR__ . '/.htaccess',
    __DIR__ . '/uploads/.htaccess',
    __DIR__ . '/uploads/leda/.htaccess',
];
foreach ($paths_to_check as $p) {
    if (file_exists($p)) {
        echo "   FOUND: $p\n";
        echo "   --- content ---\n";
        echo "   " . str_replace("\n", "\n   ", file_get_contents($p)) . "\n";
        echo "   --- end ---\n";
    } else {
        echo "   not present: $p\n";
    }
}
echo "\n";

// 4. Test synthesis if configured
if (leda_tts_is_configured()) {
    echo "4. Test synthesis (this may take 1-3s)\n";
    $url = leda_tts_synthesize('नमस्ते। यह एक test है।', 'hi');
    if ($url) {
        echo "   ✓ synthesized: $url\n";
        $fs_path = __DIR__ . $url;
        echo "   filesystem path: $fs_path\n";
        echo "   exists: " . (file_exists($fs_path) ? 'YES' : 'NO') . "\n";
        if (file_exists($fs_path)) {
            echo "   size: " . filesize($fs_path) . " bytes\n";
            echo "   file perms: " . substr(sprintf('%o', fileperms($fs_path)), -4) . "\n";
        }

        // 5. Try HTTP fetch of the URL from server itself
        echo "\n5. HTTP fetch of audio URL from server-side\n";
        $host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $full_url = $scheme . '://' . $host . $url;
        echo "   URL: $full_url\n";
        $ch = curl_init($full_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "   HTTP status: $code\n";
        if ($code === 403) {
            echo "   ⚠ 403 = .htaccess or directory permissions blocking access\n";
        } elseif ($code === 200) {
            echo "   ✓ public access works — problem is in browser (cache? CORS?)\n";
        } elseif ($code === 404) {
            echo "   ⚠ 404 = file not at this URL (path mismatch)\n";
        }
    } else {
        echo "   ✗ synthesis returned null — check error_log\n";
    }
} else {
    echo "4. Skipping synthesis test — TTS not configured\n";
}

echo "\nDone. DELETE this file after viewing.\n";
