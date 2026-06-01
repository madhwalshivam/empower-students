<?php
/**
 * del_partners.php — ONE-TIME cleanup script
 * Run once: empowerstudents.in/del_partners.php
 * DELETE IMMEDIATELY after running.
 */

// Try to find the DB — adjust path if needed
$possible = [
    __DIR__ . '/db/empowerstudents.db',
    __DIR__ . '/database/empowerstudents.db',
    __DIR__ . '/empowerstudents.db',
];
$db_path = null;
foreach ($possible as $p) {
    if (file_exists($p)) { $db_path = $p; break; }
}
if (!$db_path) {
    // Try config.php to get the real path
    @require_once __DIR__ . '/includes/config.php';
    if (defined('DB_PATH') && file_exists(DB_PATH)) $db_path = DB_PATH;
}
if (!$db_path) {
    die('❌ Database file not found. Edit this file and set $db_path manually.');
}

$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Show what will be kept
$keep = ['DRPJAIN', 'DRJHA', 'DPKJ4877'];
$keep_list = implode("','", $keep);

$before = (int)$db->query("SELECT COUNT(*) FROM partners")->fetchColumn();

// Show what will be deleted first
$del_rows = $db->query("SELECT id, name, referral_code, status FROM partners
    WHERE referral_code NOT IN ('$keep_list')")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_GET['confirm'])) {
    echo "<h2>Preview — will DELETE " . count($del_rows) . " partners:</h2><ul>";
    foreach ($del_rows as $r) {
        echo "<li>{$r['id']} · <b>{$r['name']}</b> · {$r['referral_code']} · {$r['status']}</li>";
    }
    echo "</ul>";
    $keep_rows = $db->query("SELECT id, name, referral_code, status FROM partners
        WHERE referral_code IN ('$keep_list')")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h2>Will KEEP " . count($keep_rows) . " partners:</h2><ul>";
    foreach ($keep_rows as $r) {
        echo "<li>{$r['id']} · <b>{$r['name']}</b> · {$r['referral_code']} · {$r['status']}</li>";
    }
    echo "</ul>";
    echo '<p><a href="?confirm=yes" style="background:red;color:white;padding:10px 20px;font-size:18px;text-decoration:none;border-radius:6px">
        ✓ YES — Delete them now</a></p>';
    echo '<p style="color:#666;font-size:12px">Add ?confirm=yes to URL or click button above to execute.</p>';
    exit;
}

// Execute
$db->exec("DELETE FROM partners WHERE referral_code NOT IN ('$keep_list')");
$after = (int)$db->query("SELECT COUNT(*) FROM partners")->fetchColumn();
$deleted = $before - $after;

echo "<h2 style='color:green'>✅ Done!</h2>";
echo "<p>Deleted: <b>$deleted</b> partners</p>";
echo "<p>Remaining: <b>$after</b> partners</p>";
$remaining = $db->query("SELECT name, referral_code, status FROM partners")->fetchAll(PDO::FETCH_ASSOC);
echo "<ul>";
foreach ($remaining as $r) {
    echo "<li><b>{$r['name']}</b> · {$r['referral_code']} · {$r['status']}</li>";
}
echo "</ul>";
echo "<p style='color:red;font-weight:bold'>⚠️ DELETE this file now via cPanel File Manager!</p>";
