<?php
/**
 * fix_specialist_photos.php
 *
 * One-time migration. Renames every specialist's photo filename in the
 * database from .jpg to .png. Idempotent — run it as many times as you
 * like, it only flips rows that still end in .jpg.
 *
 * USAGE:
 *   1. Upload this file to the site root (same folder as index.php).
 *   2. Open https://empowerstudents.in/fix_specialist_photos.php
 *   3. Read the report, then DELETE this file.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<html><body style='font-family:system-ui;max-width:780px;margin:30px auto;padding:0 16px'>";
echo "<h1>Specialist photo migration — .jpg → .png</h1>";

$rows = db()->query("SELECT id, name, photo FROM specialists ORDER BY order_no")->fetchAll();

if (!$rows) {
    echo "<p>No specialists in DB. Nothing to do.</p></body></html>";
    exit;
}

echo "<table style='border-collapse:collapse;width:100%;background:#fafafa;border:1px solid #ddd'>";
echo "<thead><tr style='background:#eee'><th style='padding:6px 10px;text-align:left'>Name</th><th style='padding:6px 10px;text-align:left'>Old</th><th style='padding:6px 10px;text-align:left'>New</th><th style='padding:6px 10px'>Status</th></tr></thead><tbody>";

$updated = 0;
$skipped = 0;
$upd = db()->prepare("UPDATE specialists SET photo = ? WHERE id = ?");

foreach ($rows as $r) {
    $old = $r['photo'] ?? '';
    if ($old === '') { $status = 'skip — empty'; $skipped++; $new = ''; }
    elseif (substr($old, -4) === '.jpg') {
        $new = substr($old, 0, -4) . '.png';
        $upd->execute([$new, $r['id']]);
        $status = '✓ updated';
        $updated++;
    } else {
        $new = $old;
        $status = 'already ' . pathinfo($old, PATHINFO_EXTENSION);
        $skipped++;
    }
    echo "<tr style='border-top:1px solid #eee'>"
       . "<td style='padding:6px 10px'>" . htmlspecialchars($r['name']) . "</td>"
       . "<td style='padding:6px 10px;font-family:monospace;color:#888'>" . htmlspecialchars($old) . "</td>"
       . "<td style='padding:6px 10px;font-family:monospace;color:#059669'>" . htmlspecialchars($new) . "</td>"
       . "<td style='padding:6px 10px'>$status</td>"
       . "</tr>";
}
echo "</tbody></table>";
echo "<p style='margin-top:18px'><strong>$updated</strong> updated · <strong>$skipped</strong> already correct or empty.</p>";
echo "<p style='color:#dc2626;font-weight:700'>⚠ Now DELETE this file (fix_specialist_photos.php) from the server.</p>";
echo "<p>Then upload your 7 PNG photos to <code>/assets/images/</code> using the filenames in the New column above.</p>";
echo "</body></html>";
