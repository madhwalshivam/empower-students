<?php
/**
 * fix_partner_images.php?key=nci2026admin&code=DRSHARMA&clinic=pjain.webp&doctor=teampjain.webp
 *
 * Two-in-one:
 *   1. Writes /uploads/partners/.htaccess to allow public access (overrides /uploads/ Deny-all)
 *   2. Updates partners.clinic_image_path and partners.doctor_image_path for the given code
 *
 * Default values used if not passed in URL:
 *   code   = DRSHARMA
 *   clinic = pjain.webp
 *   doctor = teampjain.webp
 *
 * DELETE after use.
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

$code   = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $_GET['code'] ?? 'DRSHARMA'));
$clinic = trim($_GET['clinic'] ?? 'pjain.webp');
$doctor = trim($_GET['doctor'] ?? 'teampjain.webp');

echo "=== Fix partner images ===\n\n";
echo "code:   $code\n";
echo "clinic: $clinic\n";
echo "doctor: $doctor\n\n";

// 1. Create /uploads/partners/.htaccess to allow public image access
echo "1. Ensure /uploads/partners/.htaccess permits public access\n";
$partners_dir = __DIR__ . '/uploads/partners';
if (!is_dir($partners_dir)) {
    @mkdir($partners_dir, 0775, true);
}
$htaccess = $partners_dir . '/.htaccess';
$content = "# Allow public access to partner photos (clinic + doctor images).\n"
         . "# Overrides the parent /uploads/ Deny-all .htaccess.\n"
         . "\n"
         . "<RequireAll>\n"
         . "  Require all granted\n"
         . "</RequireAll>\n"
         . "<IfModule !mod_authz_core.c>\n"
         . "  Order Allow,Deny\n"
         . "  Allow from all\n"
         . "  Satisfy Any\n"
         . "</IfModule>\n"
         . "Satisfy Any\n"
         . "\n"
         . "<IfModule mod_mime.c>\n"
         . "  AddType image/webp .webp\n"
         . "  AddType image/jpeg .jpg .jpeg\n"
         . "  AddType image/png .png\n"
         . "</IfModule>\n"
         . "<IfModule mod_headers.c>\n"
         . "  Header set Cache-Control \"public, max-age=2592000\"\n"
         . "</IfModule>\n";
$ok = @file_put_contents($htaccess, $content);
if ($ok === false) {
    echo "   ❌ Could not write $htaccess (permissions?)\n";
    exit(1);
}
@chmod($htaccess, 0644);
echo "   ✓ Wrote $htaccess (" . filesize($htaccess) . " bytes)\n";

// 2. Verify the image files actually exist
echo "\n2. Verify image files exist\n";
$clinic_fs = $partners_dir . '/' . $clinic;
$doctor_fs = $partners_dir . '/' . $doctor;
echo "   clinic: $clinic_fs " . (file_exists($clinic_fs) ? "✓ exists (" . filesize($clinic_fs) . " bytes)" : "❌ NOT FOUND") . "\n";
echo "   doctor: $doctor_fs " . (file_exists($doctor_fs) ? "✓ exists (" . filesize($doctor_fs) . " bytes)" : "❌ NOT FOUND") . "\n";

// 3. Update partners row
echo "\n3. Update partners.{$code} image paths\n";
$st = db()->prepare("SELECT id FROM partners WHERE referral_code = ?");
$st->execute([$code]);
$pid = (int)$st->fetchColumn();
if (!$pid) {
    echo "   ❌ Partner with code $code not found.\n";
    echo "   → Run create_drsharma_v2.php first to create the partner.\n";
    exit(1);
}

$clinic_path = '/uploads/partners/' . $clinic;
$doctor_path = '/uploads/partners/' . $doctor;

db()->prepare("UPDATE partners SET clinic_image_path = ?, doctor_image_path = ? WHERE id = ?")
   ->execute([$clinic_path, $doctor_path, $pid]);

echo "   ✓ Updated partner #$pid:\n";
echo "     clinic_image_path = $clinic_path\n";
echo "     doctor_image_path = $doctor_path\n";

// 4. Test HTTP fetch of the images
echo "\n4. Verify images are publicly accessible\n";
$host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
foreach ([$clinic_path, $doctor_path] as $img) {
    $url = $scheme . '://' . $host . $img;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $ok = $http_code === 200 && strpos((string)$ct, 'image') !== false;
    echo "   " . ($ok ? "✓" : "❌") . " $url (HTTP $http_code, $ct)\n";
}

echo "\n=== Now refresh: ===\n";
echo "  $scheme://$host/p.php?code=$code\n\n";
echo "DELETE this file after confirming images appear.\n";
