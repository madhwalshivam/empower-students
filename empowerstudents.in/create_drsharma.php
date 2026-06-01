<?php
/**
 * create_drsharma.php?key=nci2026admin
 *
 * One-shot script: creates (or updates) DRSHARMA partner with branding.
 * Bypasses any function-redeclare conflicts by using raw PDO only —
 * no requires beyond db.php.
 *
 * Visit once, see output, DELETE.
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

echo "=== Create DRSHARMA partner ===\n\n";

// 1. Ensure partners table exists (idempotent — partner_schema.php IIFE creates it)
echo "1. Ensure partners table\n";
try {
    db()->exec("CREATE TABLE IF NOT EXISTS partners (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT NOT NULL,
        contact_name    TEXT,
        phone           TEXT,
        whatsapp        TEXT,
        email           TEXT,
        city            TEXT,
        referral_code   TEXT UNIQUE NOT NULL,
        revenue_share   REAL DEFAULT 0.30,
        bank_name       TEXT,
        bank_account    TEXT,
        bank_ifsc       TEXT,
        upi_id          TEXT,
        status          TEXT DEFAULT 'active',
        notes           TEXT,
        created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
        last_referral_at TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_partners_code ON partners(referral_code)");
    echo "   ✓ table exists\n";
} catch (Throwable $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

// 2. Ensure branding columns
echo "\n2. Ensure branding columns\n";
$cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
$names = array_column($cols, 'name');
foreach (['clinic_image_path','doctor_image_path','clinic_address','doctor_credentials','custom_message'] as $col) {
    if (!in_array($col, $names, true)) {
        try {
            db()->exec("ALTER TABLE partners ADD COLUMN $col TEXT");
            echo "   ✓ added $col\n";
        } catch (Throwable $e) {
            echo "   ❌ $col: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✓ $col already exists\n";
    }
}

// 3. Insert / update DRSHARMA
echo "\n3. Create / update DRSHARMA\n";
$st = db()->prepare("SELECT id FROM partners WHERE referral_code = 'DRSHARMA'");
$st->execute();
$existing = $st->fetch();

$data = [
    'name'               => 'Sunrise Children\'s Clinic',
    'contact_name'       => 'Dr Anita Sharma',
    'phone'              => '+919876543210',
    'whatsapp'           => '+919876543210',
    'email'              => 'dr.anita@example.com',
    'city'               => 'Gurgaon',
    'revenue_share'      => 0.50,
    'status'             => 'active',
    'doctor_credentials' => 'MBBS, MD (Pediatrics) · 15 yrs experience',
    'clinic_address'     => 'Sector 14, Gurgaon, Haryana',
    'custom_message'     => 'I recommend this evaluation to every parent who asks me about their child\'s behaviour or emotional state. It surfaces what I cannot in a 10-minute consultation.',
];

if ($existing) {
    $set = [];
    $vals = [];
    foreach ($data as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
    $vals[] = (int)$existing['id'];
    db()->prepare("UPDATE partners SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    echo "   ✓ Updated existing DRSHARMA (id={$existing['id']})\n";
} else {
    $data['referral_code'] = 'DRSHARMA';
    $cols_list = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($cols_list), '?'));
    db()->prepare("INSERT INTO partners (" . implode(', ', $cols_list) . ") VALUES ($placeholders)")
        ->execute(array_values($data));
    echo "   ✓ Created DRSHARMA (id=" . db()->lastInsertId() . ")\n";
}

// 4. Verify
echo "\n4. Verify\n";
$st = db()->prepare("SELECT id, name, contact_name, referral_code, status, revenue_share FROM partners WHERE referral_code = 'DRSHARMA'");
$st->execute();
$p = $st->fetch();
if ($p) {
    echo "   id: {$p['id']}\n";
    echo "   name: {$p['name']}\n";
    echo "   doctor: {$p['contact_name']}\n";
    echo "   code: {$p['referral_code']}\n";
    echo "   status: {$p['status']}\n";
    echo "   share: " . (int)round($p['revenue_share'] * 100) . "%\n";
}

echo "\n=== Now visit: ===\n";
$host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
echo "  $scheme://$host/p.php?code=DRSHARMA\n\n";
echo "DELETE this file after seeing the landing page.\n";
