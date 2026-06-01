<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "<pre>";

echo "Step 1: config... ";
require_once __DIR__ . '/includes/config.php'; echo "OK\n";

echo "Step 2: db... ";
require_once __DIR__ . '/includes/db.php'; db_init(); echo "OK\n";

echo "Step 3: wallet... ";
require_once __DIR__ . '/includes/wallet.php'; echo "OK\n";

echo "Step 4: cashfree... ";
require_once __DIR__ . '/includes/cashfree.php'; echo "OK\n";

// cashfree loads partners.php which may load partner_schema
echo "After cashfree - partner_by_code: " . (function_exists('partner_by_code') ? 'exists' : 'missing') . "\n";
echo "After cashfree - partner_record_charge: " . (function_exists('partner_record_charge') ? 'exists' : 'missing') . "\n";

echo "Step 5: catalogue... ";
require_once __DIR__ . '/includes/catalogue.php'; echo "OK\n";

echo "\nNow simulating admin/index.php loads:\n";

echo "Step 6: partner_schema.php... ";
if (file_exists(__DIR__ . '/includes/partner_schema.php')) {
    require_once __DIR__ . '/includes/partner_schema.php'; echo "OK\n";
} else { echo "NOT FOUND\n"; }

echo "Step 7: partner_auth.php... ";
require_once __DIR__ . '/includes/partner_auth.php'; echo "OK\n";

echo "\nNow loading admin/index.php requires:\n";
echo "partner_capture.php... ";
if (file_exists(__DIR__ . '/includes/partner_capture.php')) {
    require_once __DIR__ . '/includes/partner_capture.php'; echo "OK\n";
} else { echo "not found (OK)\n"; }

// Check what admin/index.php actually requires
echo "\nadmin/index.php first 10 require lines:\n";
foreach (file(__DIR__ . '/admin/index.php') as $i => $line) {
    if (preg_match('/require/i', $line)) {
        echo "  line " . ($i+1) . ": " . trim($line) . "\n";
    }
    if ($i > 30) break;
}

echo "\nChecking error log (last 5 lines):\n";
$log = '/home/pbsxsp7mle8b/logs/empowerstudents.in.error.log';
if (file_exists($log)) {
    $lines = file($log);
    foreach (array_slice($lines, -5) as $l) echo htmlspecialchars($l);
} else {
    // Try common GoDaddy paths
    foreach ([
        '/home/pbsxsp7mle8b/public_html/empowerstudents.in/error_log',
        '/home/pbsxsp7mle8b/logs/error_log',
        __DIR__ . '/error_log',
    ] as $path) {
        if (file_exists($path)) {
            $lines = file($path);
            echo "Found log at $path:\n";
            foreach (array_slice($lines, -5) as $l) echo htmlspecialchars($l);
            break;
        }
    }
}

echo "\nDone.\n</pre>";
