<?php
/**
 * setup_child_learn_price_v2.php?key=nci2026admin
 *
 * Schema-tolerant: detects actual columns of service_prices and inserts/updates
 * only those that exist.
 *
 * Visit once, see ✓ output, DELETE.
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

echo "=== Setup child_learn_program price (v2) ===\n\n";

// 1. Inspect actual schema
echo "1. Inspect service_prices schema\n";
$cols = db()->query("PRAGMA table_info(service_prices)")->fetchAll();
if (empty($cols)) {
    echo "   ❌ service_prices table doesn't exist. Run /admin/services.php once first.\n";
    exit(1);
}
$col_names = array_column($cols, 'name');
echo "   Columns: " . implode(', ', $col_names) . "\n\n";

// 2. Check if 'service_key' exists (the join column)
if (!in_array('service_key', $col_names, true)) {
    echo "   ❌ No service_key column. Cannot proceed.\n";
    exit(1);
}
if (!in_array('price', $col_names, true)) {
    echo "   ❌ No price column. Cannot proceed.\n";
    exit(1);
}

// 3. Check existing row
echo "2. Check existing child_learn_program row\n";
$st = db()->prepare("SELECT * FROM service_prices WHERE service_key = ?");
$st->execute(['child_learn_program']);
$row = $st->fetch();

if ($row) {
    echo "   Existing row found:\n";
    foreach (['service_key','price','is_active','label'] as $k) {
        if (in_array($k, $col_names, true) && isset($row[$k])) {
            echo "     $k = " . $row[$k] . "\n";
        }
    }
    if ((int)($row['price'] ?? 0) !== 999 || (in_array('is_active', $col_names, true) && (int)($row['is_active'] ?? 1) !== 1)) {
        $upd_parts = ['price = 999'];
        if (in_array('is_active', $col_names, true)) $upd_parts[] = 'is_active = 1';
        if (in_array('updated_at', $col_names, true)) $upd_parts[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = "UPDATE service_prices SET " . implode(', ', $upd_parts) . " WHERE service_key = ?";
        db()->prepare($sql)->execute(['child_learn_program']);
        echo "   ✓ Updated to ₹999 active\n";
    } else {
        echo "   ✓ Already at ₹999 active, no change\n";
    }
} else {
    // 4. Insert — only use columns that exist
    echo "   No existing row, inserting fresh\n";

    $data = ['service_key' => 'child_learn_program', 'price' => 999];
    if (in_array('label', $col_names, true)) {
        $data['label'] = '14-day daily learning program (all 4 child modules)';
    }
    if (in_array('is_active', $col_names, true)) {
        $data['is_active'] = 1;
    }
    if (in_array('notes', $col_names, true)) {
        $data['notes'] = 'One-time unlock for child Hub; covers Speech / Mind Power / Behavior / GK';
    }
    if (in_array('created_at', $col_names, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    $cols_list = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($cols_list), '?'));
    $sql = "INSERT INTO service_prices (" . implode(', ', $cols_list) . ") VALUES ($placeholders)";
    db()->prepare($sql)->execute(array_values($data));
    echo "   ✓ Created child_learn_program at ₹999\n";
}

// 5. Verify
echo "\n3. Verify\n";
$st = db()->prepare("SELECT * FROM service_prices WHERE service_key = ?");
$st->execute(['child_learn_program']);
$row = $st->fetch();
if ($row) {
    foreach ($row as $k => $v) {
        if (!is_int($k)) echo "   $k: $v\n";
    }
} else {
    echo "   ❌ Still not found — something went wrong.\n";
    exit(1);
}

echo "\n=== Now visit: ===\n";
$host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
echo "  $scheme://$host/child-learn.php\n\n";
echo "DELETE this file after success.\n";
