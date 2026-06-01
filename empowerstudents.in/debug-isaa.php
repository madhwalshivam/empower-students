<?php
// debug-isaa.php — diagnose 500 errors by enabling display_errors and stepping through
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre style='font:14px monospace; padding:20px; max-width:900px;'>";
echo "ISAA debug — " . date('Y-m-d H:i:s') . "\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo str_repeat('-', 60) . "\n\n";

function step($n, $msg) {
    echo "STEP {$n}: {$msg}\n";
    @ob_flush(); @flush();
}

// Step 1: config
step(1, "Loading config.php...");
try {
    require_once __DIR__ . '/includes/config.php';
    echo "  ✓ config.php loaded\n";
} catch (Throwable $e) {
    echo "  ✗ FATAL in config.php: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
    exit;
}

// Step 2: db
step(2, "Loading db.php + db_init()...");
try {
    require_once __DIR__ . '/includes/db.php';
    db_init();
    echo "  ✓ db.php loaded, db_init() ran\n";
} catch (Throwable $e) {
    echo "  ✗ FATAL in db.php: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
    exit;
}

// Step 3: schema check BEFORE loading new schema
step(3, "Checking isaa_assessments columns BEFORE catalogue.php loads...");
try {
    $cols = db()->query("PRAGMA table_info(isaa_assessments)")->fetchAll();
    if ($cols) {
        echo "  Existing columns: ";
        foreach ($cols as $c) echo $c['name'] . " ";
        echo "\n";
    } else {
        echo "  Table doesn't exist yet (will be created by schema)\n";
    }
} catch (Throwable $e) {
    echo "  Could not check: " . $e->getMessage() . "\n";
}

// Step 4: catalogue (cascades to isaa_schema)
step(4, "Loading catalogue.php (cascades to isaa_schema.php)...");
try {
    require_once __DIR__ . '/includes/catalogue.php';
    echo "  ✓ catalogue.php loaded\n";
} catch (Throwable $e) {
    echo "  ✗ FATAL in catalogue/schema: " . $e->getMessage() . "\n";
    echo "  At: " . $e->getFile() . " line " . $e->getLine() . "\n";
    echo "  Stack:\n" . $e->getTraceAsString() . "\n";
    exit;
}

// Step 5: isaa_helpers
step(5, "Loading isaa_helpers.php...");
try {
    require_once __DIR__ . '/includes/isaa_helpers.php';
    echo "  ✓ isaa_helpers.php loaded\n";
} catch (Throwable $e) {
    echo "  ✗ FATAL in isaa_helpers: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
    exit;
}

// Step 6: schema check AFTER
step(6, "Checking columns AFTER schema migration...");
try {
    $cols = db()->query("PRAGMA table_info(isaa_assessments)")->fetchAll();
    $names = array_column($cols, 'name');
    echo "  All columns: " . implode(', ', $names) . "\n";
    foreach (['summary_md_hi', 'advice_md_hi', 'share_token', 'share_pin'] as $c) {
        echo "  $c: " . (in_array($c, $names, true) ? '✓ present' : '✗ MISSING') . "\n";
    }
} catch (Throwable $e) {
    echo "  Could not check: " . $e->getMessage() . "\n";
}

// Step 7: rendering try
step(7, "Trying to query assessment id=1...");
try {
    $st = db()->prepare("SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                                p.name AS parent_name, pn.name AS partner_name
                         FROM isaa_assessments a
                         JOIN children c ON c.id = a.child_id
                         LEFT JOIN parents p ON p.id = a.parent_id
                         LEFT JOIN partners pn ON pn.id = a.partner_id
                         WHERE a.id = ?");
    $st->execute([1]);
    $row = $st->fetch();
    if (!$row) {
        echo "  No assessment with id=1\n";
    } else {
        echo "  ✓ Row fetched. Status={$row['status']}, total={$row['total_score']}, child={$row['child_name']}\n";
        echo "  summary_md len: " . strlen((string)($row['summary_md'] ?? '')) . "\n";
        echo "  summary_md_hi len: " . strlen((string)($row['summary_md_hi'] ?? '')) . "\n";
        echo "  share_token: " . var_export($row['share_token'] ?? 'NULL', true) . "\n";
    }
} catch (Throwable $e) {
    echo "  ✗ FATAL on query: " . $e->getMessage() . "\n";
    exit;
}

// Step 8: file existence check for view files
step(8, "Checking critical files exist with sizes...");
foreach ([
    'partner-isaa-view.php',
    'partner-isaa.php',
    'isaa-report.php',
    'admin/isaa-test.php',
    'admin/isaa-partners.php',
    'includes/isaa_schema.php',
    'includes/isaa_helpers.php',
    'includes/markdown.php',
] as $f) {
    $p = __DIR__ . '/' . $f;
    echo "  $f: " . (file_exists($p) ? '✓ exists (' . filesize($p) . ' bytes)' : '✗ MISSING') . "\n";
}

// Step 9: try to load each new file in isolation
step(9, "Lint-loading partner-isaa-view.php (no execute)...");
$path = __DIR__ . '/partner-isaa-view.php';
if (file_exists($path)) {
    $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
    echo "  " . trim($output) . "\n";
}
$path = __DIR__ . '/admin/isaa-test.php';
if (file_exists($path)) {
    $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
    echo "  admin/isaa-test.php: " . trim($output) . "\n";
}

echo "\n";
echo str_repeat('-', 60) . "\n";
echo "If you see this line, bootstrap fully succeeded.\n";
echo "Now visit /partner-isaa-view.php?id=1 directly to see the actual rendering error.\n";
echo "</pre>";
