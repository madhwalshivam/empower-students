<?php
/**
 * diag_leda2.php — re-tests audio fetch after .htaccess is in place.
 * Shows status code + first 200 bytes of response.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== Leda Audio Fetch Test ===\n\n";

$urls = [
    '/uploads/leda/40a07eb7055f2e8d438144886b547b3a1642f9b1d864a53c9ede80af696d885b.mp3',
    '/uploads/leda/fe8cd360176a83f3d092302d8464dff29c908ca714095ddd00cb669a5a4ce85c.mp3',
];

$host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';

foreach ($urls as $url) {
    $full = $scheme . '://' . $host . $url;
    echo "URL: $full\n";

    $ch = curl_init($full);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,  // do NOT follow — we want to see redirects
        CURLOPT_USERAGENT => 'diag_leda2',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    echo "  HTTP status: $code\n";
    echo "  Content-Type: $ct\n";
    echo "  Body size: $size bytes\n";
    echo "  Effective URL: $eff\n";

    if ($code >= 300 && $code < 400) {
        echo "  ⚠ Redirect detected — something is rewriting this URL.\n";
        if (preg_match('/^Location:\s*(.+)$/mi', (string)$resp, $m)) {
            echo "  Location header: " . trim($m[1]) . "\n";
        }
    } elseif ($code === 200) {
        echo "  ✓ public access works\n";
    } else {
        echo "  ⚠ unexpected status\n";
    }

    // First 200 bytes of response headers
    $headers_end = strpos((string)$resp, "\r\n\r\n");
    if ($headers_end !== false) {
        echo "  --- Response headers ---\n";
        echo "  " . str_replace("\n", "\n  ", trim(substr($resp, 0, $headers_end))) . "\n";
    }
    echo "\n";
}

// Check root .htaccess for rewrite rules
$root_htaccess = __DIR__ . '/.htaccess';
echo "\nRoot .htaccess RewriteRule scan:\n";
if (file_exists($root_htaccess)) {
    $content = file_get_contents($root_htaccess);
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'rewrite') !== false || stripos($line, 'errordocument') !== false) {
            echo "  Line " . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}

// Check if there's something in includes/header.php or index.php redirecting
echo "\n\nDELETE this file after viewing.\n";
