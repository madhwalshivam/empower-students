<?php
/**
 * migrate_reports_to_webroot.php?key=nci2026admin
 *
 * Fixes the 403 problem for PDF/HTML reports by moving them out of /uploads/
 * (which has Deny-from-all) into /reports/ at web root.
 *
 *   1. Creates /reports/ folder + permissive .htaccess
 *   2. Moves existing /uploads/reports/* files to /reports/*
 *   3. Updates parent_reflect_sessions.report_pdf_path to new locations
 *   4. Updates comprehensive_report.php and comprehensive_report_v3.php to
 *      write future reports to /reports/ (these need manual upload — see below)
 *
 * Visit once, see output, DELETE.
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

echo "=== Migrate reports to /reports/ (out of /uploads/) ===\n\n";

$old_dir = __DIR__ . '/uploads/reports';
$new_dir = __DIR__ . '/reports';

// 1. Create /reports/ + .htaccess
echo "1. Create /reports/ folder + .htaccess\n";
if (!is_dir($new_dir)) {
    if (!@mkdir($new_dir, 0775, true)) {
        echo "   ❌ Could not create $new_dir — check parent permissions\n"; exit(1);
    }
    echo "   ✓ Created $new_dir\n";
} else {
    echo "   ✓ $new_dir already exists\n";
}

$htaccess = $new_dir . '/.htaccess';
$content = "# Allow public access to report files (HTML + PDF).\n"
         . "# Each filename contains a 60-char hash so URLs are unguessable.\n"
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
         . "  AddType application/pdf .pdf\n"
         . "  AddType text/html .html\n"
         . "</IfModule>\n"
         . "<IfModule mod_headers.c>\n"
         . "  Header set Cache-Control \"public, max-age=86400\"\n"
         . "</IfModule>\n";
@file_put_contents($htaccess, $content);
@chmod($htaccess, 0644);
echo "   ✓ Wrote .htaccess (" . filesize($htaccess) . " bytes)\n";

// 2. Move files
echo "\n2. Move existing report files\n";
$moved = 0; $skipped = 0;
if (is_dir($old_dir)) {
    $files = array_merge(glob($old_dir . '/*.html'), glob($old_dir . '/*.pdf'));
    foreach ($files as $src) {
        $bn = basename($src);
        $dst = $new_dir . '/' . $bn;
        if (file_exists($dst)) { $skipped++; continue; }
        if (@rename($src, $dst)) {
            @chmod($dst, 0644);
            $moved++;
        }
    }
    echo "   ✓ Moved $moved file(s), skipped $skipped already-present\n";
} else {
    echo "   · $old_dir does not exist — nothing to move\n";
}

// 3. Update DB references
echo "\n3. Update DB references\n";
try {
    $st = db()->prepare("UPDATE parent_reflect_sessions
                          SET report_pdf_path = REPLACE(report_pdf_path, '/uploads/reports/', '/reports/')
                          WHERE report_pdf_path LIKE '/uploads/reports/%'");
    $st->execute();
    echo "   ✓ Updated " . $st->rowCount() . " parent_reflect_sessions row(s)\n";
} catch (Throwable $e) {
    echo "   ⚠ " . $e->getMessage() . "\n";
}

// 4. Test access
echo "\n4. Test HTTP access to one report\n";
$pst = db()->prepare("SELECT report_pdf_path FROM parent_reflect_sessions
                       WHERE report_pdf_path LIKE '/reports/%' LIMIT 1");
$pst->execute();
$sample = $pst->fetchColumn();
if ($sample) {
    $host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $url = $scheme . '://' . $host . $sample;
    echo "   Sample: $url\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    echo "   HTTP $code · $ct\n";
    if ($code === 200) {
        echo "   ✓ Public access works\n";
    } else {
        echo "   ❌ still " . $code . " — may need to also adjust /uploads/.htaccess (but unlikely if /reports/ is at root)\n";
    }
} else {
    echo "   · No reports with /reports/ path yet to test\n";
}

echo "\n=== Migration done ===\n";
echo "\nIMPORTANT — to make FUTURE reports also write to /reports/:\n";
echo "  1. Edit includes/comprehensive_report.php — find lines:\n";
echo "       \$base_dir_fs  = __DIR__ . '/../uploads/reports';\n";
echo "       \$base_dir_web = '/uploads/reports';\n";
echo "     Change to:\n";
echo "       \$base_dir_fs  = __DIR__ . '/../reports';\n";
echo "       \$base_dir_web = '/reports';\n";
echo "  2. Same in includes/comprehensive_report_v3.php (if installed)\n";
echo "\nOR upload the updated versions of those files that I'll send next.\n";
echo "\nDELETE this file after success.\n";
