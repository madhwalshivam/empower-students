<?php
// debug-eval.php — diagnose the children-not-found bug
ini_set('display_errors', '1'); error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';

echo "<pre style='font:14px monospace; padding:20px; background:#f8fafc;'>";

$parent = current_parent();
if (!$parent) {
    echo "✗ Not logged in as parent. Sign in first at /login.php\n</pre>";
    exit;
}

echo "Logged-in parent\n";
echo "  id: " . (int)$parent['id'] . "\n";
echo "  name: " . ($parent['name'] ?? '?') . "\n";
echo "  whatsapp: " . ($parent['whatsapp'] ?? '?') . "\n";
echo "\n";

// Method A: same query as dashboard
echo "Method A — dashboard's exact query:\n";
echo "  Query: SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC\n";
echo "  parent_id passed: " . (int)$parent['id'] . "\n";
$cs = db()->prepare('SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC');
$cs->execute([$parent['id']]);
$rows_a = $cs->fetchAll();
echo "  Rows returned: " . count($rows_a) . "\n";
foreach ($rows_a as $r) {
    echo "    - id={$r['id']} name=" . ($r['name'] ?? '?') . " parent_id={$r['parent_id']} created_at=" . ($r['created_at'] ?? 'NULL') . "\n";
}
echo "\n";

// Method B: same query as eval-speech with explicit (int) cast
echo "Method B — eval-speech's query with (int) cast:\n";
$pid = (int)$parent['id'];
echo "  parent_id passed: $pid\n";
$cs = db()->prepare('SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC');
$cs->execute([$pid]);
$rows_b = $cs->fetchAll();
echo "  Rows returned: " . count($rows_b) . "\n";
foreach ($rows_b as $r) {
    echo "    - id={$r['id']} name=" . ($r['name'] ?? '?') . "\n";
}
echo "\n";

// Method C: Look up by name
echo "Method C — look up Aarav by name (regardless of parent):\n";
$cs = db()->prepare('SELECT * FROM children WHERE name LIKE ?');
$cs->execute(['%Aarav%']);
foreach ($cs->fetchAll() as $r) {
    echo "    - id={$r['id']} name={$r['name']} parent_id={$r['parent_id']} created_at=" . ($r['created_at'] ?? 'NULL') . "\n";
}
echo "\n";

// Method D: ALL children rows
echo "Method D — ALL children in database (first 20):\n";
$cs = db()->query('SELECT id, name, parent_id, created_at FROM children ORDER BY id DESC LIMIT 20');
foreach ($cs->fetchAll() as $r) {
    echo "    - id={$r['id']} name={$r['name']} parent_id={$r['parent_id']} created_at=" . ($r['created_at'] ?? 'NULL') . "\n";
}
echo "\n";

// Method E: schema of children
echo "Method E — children table columns:\n";
$cols = db()->query("PRAGMA table_info(children)")->fetchAll();
foreach ($cols as $c) {
    echo "  {$c['name']}: {$c['type']}\n";
}

echo "</pre>";
