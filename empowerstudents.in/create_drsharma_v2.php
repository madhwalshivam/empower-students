<?php
/**
 * create_drsharma_v2.php?key=nci2026admin
 *
 * Adds every column the partners table needs, then inserts DRSHARMA.
 * Robust against production having a stripped-down partners table.
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();

echo "=== Create DRSHARMA — v2 (column-tolerant) ===\n\n";

// 1. Show current partners table schema
echo "1. Current partners columns:\n";
$cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
$existing_names = array_column($cols, 'name');
foreach ($existing_names as $n) echo "   • $n\n";

// 2. Add every column we might want, idempotently
echo "\n2. Adding any missing columns:\n";
$required_cols = [
    'name'              => 'TEXT',
    'contact_name'      => 'TEXT',
    'phone'             => 'TEXT',
    'whatsapp'          => 'TEXT',
    'email'             => 'TEXT',
    'city'              => 'TEXT',
    'referral_code'     => 'TEXT',
    'revenue_share'     => 'REAL DEFAULT 0.30',
    'bank_name'         => 'TEXT',
    'bank_account'      => 'TEXT',
    'bank_ifsc'         => 'TEXT',
    'upi_id'            => 'TEXT',
    'status'            => "TEXT DEFAULT 'active'",
    'notes'             => 'TEXT',
    'created_at'        => "TEXT DEFAULT CURRENT_TIMESTAMP",
    'last_referral_at'  => 'TEXT',
    'clinic_image_path' => 'TEXT',
    'doctor_image_path' => 'TEXT',
    'clinic_address'    => 'TEXT',
    'doctor_credentials'=> 'TEXT',
    'custom_message'    => 'TEXT',
];
foreach ($required_cols as $col => $type) {
    if (!in_array($col, $existing_names, true)) {
        try {
            db()->exec("ALTER TABLE partners ADD COLUMN $col $type");
            echo "   ✓ added $col $type\n";
        } catch (Throwable $e) {
            echo "   ❌ $col: " . $e->getMessage() . "\n";
        }
    }
}

// Refresh column list
$cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
$existing_names = array_column($cols, 'name');

// 3. Build INSERT/UPDATE using ONLY columns that actually exist
echo "\n3. Creating / updating DRSHARMA\n";

$desired = [
    'name'               => "Sunrise Children's Clinic",
    'contact_name'       => 'Dr Anita Sharma',
    'phone'              => '+919876543210',
    'whatsapp'           => '+919876543210',
    'email'              => 'dr.anita@example.com',
    'city'               => 'Gurgaon',
    'revenue_share'      => 0.50,
    'status'             => 'active',
    'doctor_credentials' => 'MBBS, MD (Pediatrics) · 15 yrs experience',
    'clinic_address'     => 'Sector 14, Gurgaon, Haryana',
    'custom_message'     => "I recommend this evaluation to every parent who asks me about their child's behaviour or emotional state. It surfaces what I cannot in a 10-minute consultation.",
];

// Drop fields not actually in the table
$safe = [];
foreach ($desired as $k => $v) {
    if (in_array($k, $existing_names, true)) $safe[$k] = $v;
}

// Check if DRSHARMA exists
$exists = null;
if (in_array('referral_code', $existing_names, true)) {
    $st = db()->prepare("SELECT id FROM partners WHERE referral_code = ?");
    $st->execute(['DRSHARMA']);
    $exists = $st->fetch();
}

if ($exists) {
    $set = [];
    $vals = [];
    foreach ($safe as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
    $vals[] = (int)$exists['id'];
    db()->prepare("UPDATE partners SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    echo "   ✓ Updated DRSHARMA (id={$exists['id']})\n";
} else {
    $safe['referral_code'] = 'DRSHARMA';
    $col_names = array_keys($safe);
    $placeholders = implode(', ', array_fill(0, count($col_names), '?'));
    db()->prepare("INSERT INTO partners (" . implode(', ', $col_names) . ") VALUES ($placeholders)")
        ->execute(array_values($safe));
    echo "   ✓ Created DRSHARMA (id=" . db()->lastInsertId() . ")\n";
}

// 4. Verify
echo "\n4. Verify DRSHARMA in DB\n";
$st = db()->prepare("SELECT * FROM partners WHERE referral_code = 'DRSHARMA' LIMIT 1");
$st->execute();
$p = $st->fetch();
if ($p) {
    foreach ($p as $k => $v) {
        if (is_int($k)) continue;  // skip numeric duplicates from fetch
        $v = is_string($v) && mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v;
        echo "   $k: $v\n";
    }
} else {
    echo "   ❌ Not found after insert — something's very wrong.\n";
}

echo "\n=== Now visit: ===\n";
$host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
echo "  $scheme://$host/p.php?code=DRSHARMA\n\n";
echo "DELETE this file after seeing the landing page.\n";
