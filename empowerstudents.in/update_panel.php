<?php
/**
 * update_panel.php
 *
 * One-time migration to:
 *   1. Update specialist names with the real names of the panel.
 *   2. Add Dr. P. K. Jha (Director) if not already present.
 *
 * Idempotent — safe to re-run.
 *
 * USAGE:
 *   1. Upload to site root.
 *   2. Open https://empowerstudents.in/update_panel.php
 *   3. Read the report, then DELETE this file.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<html><body style='font-family:system-ui;max-width:840px;margin:30px auto;padding:0 16px'>";
echo "<h1>Panel update — names + Director</h1>";

// Map photo filename -> new name. Photos may already be either .jpg or .png;
// we match without extension.
$updates = [
    'ot'           => 'Dr. Charu Arora',
    'speech'       => 'Dr. Murli Singh',
    'psychologist' => 'Dr. A. Srivastava',
    'neurologist'  => 'Dr. M. Gupta',
    'paeds'        => 'Dr. L. Sharma',
    'counsellor'   => 'Mrs. Preeti Singh',
    'special_ed'   => 'Mrs. Prerna Gupta',
];

echo "<h2>Renaming existing 7</h2>";
echo "<table style='border-collapse:collapse;width:100%;background:#fafafa;border:1px solid #ddd'>";
echo "<thead><tr style='background:#eee'>"
   . "<th style='padding:6px 10px;text-align:left'>Photo</th>"
   . "<th style='padding:6px 10px;text-align:left'>Old name</th>"
   . "<th style='padding:6px 10px;text-align:left'>New name</th>"
   . "<th style='padding:6px 10px'>Status</th>"
   . "</tr></thead><tbody>";

$updated = 0;
$skipped = 0;
$upd = db()->prepare("UPDATE specialists SET name = ? WHERE photo LIKE ?");
$find = db()->prepare("SELECT name, photo FROM specialists WHERE photo LIKE ? LIMIT 1");

foreach ($updates as $stem => $newName) {
    $pattern = $stem . '.%';                   // matches ot.jpg / ot.png / ot.webp
    $find->execute([$pattern]);
    $row = $find->fetch();
    if (!$row) {
        echo "<tr style='border-top:1px solid #eee'>"
           . "<td style='padding:6px 10px;font-family:monospace'>" . htmlspecialchars($stem) . ".*</td>"
           . "<td style='padding:6px 10px;color:#888' colspan='2'>— no row found —</td>"
           . "<td style='padding:6px 10px'>skip</td></tr>";
        $skipped++;
        continue;
    }
    if ($row['name'] === $newName) {
        echo "<tr style='border-top:1px solid #eee'>"
           . "<td style='padding:6px 10px;font-family:monospace'>" . htmlspecialchars($row['photo']) . "</td>"
           . "<td style='padding:6px 10px'>" . htmlspecialchars($row['name']) . "</td>"
           . "<td style='padding:6px 10px;color:#888'>" . htmlspecialchars($newName) . "</td>"
           . "<td style='padding:6px 10px'>already correct</td></tr>";
        $skipped++;
        continue;
    }
    $upd->execute([$newName, $pattern]);
    echo "<tr style='border-top:1px solid #eee'>"
       . "<td style='padding:6px 10px;font-family:monospace'>" . htmlspecialchars($row['photo']) . "</td>"
       . "<td style='padding:6px 10px;color:#888'>" . htmlspecialchars($row['name']) . "</td>"
       . "<td style='padding:6px 10px;color:#059669;font-weight:600'>" . htmlspecialchars($newName) . "</td>"
       . "<td style='padding:6px 10px;color:#059669'>✓ updated</td></tr>";
    $updated++;
}
echo "</tbody></table>";

// Add Dr. P. K. Jha as 8th specialist (Director)
echo "<h2>Director — Dr. P. K. Jha</h2>";
$check = db()->prepare("SELECT id, name FROM specialists WHERE photo = 'director.png' OR role LIKE '%Director%' LIMIT 1");
$check->execute();
$director = $check->fetch();

if ($director) {
    // Update in case fields drifted
    db()->prepare(
        "UPDATE specialists
         SET name = ?, role = ?, qualifications = ?, bio = ?, photo = ?, order_no = ?, active = 1
         WHERE id = ?"
    )->execute([
        'Dr. P. K. Jha',
        'Director & Life Coach',
        'M.Ch (AIIMS) · Neurosurgeon · 30+ yrs',
        'Founder of Empowerstudents.in. Senior neurosurgeon and life coach with three decades of experience in paediatric neuro-development.',
        'director.png',
        80,
        $director['id'],
    ]);
    echo "<p style='color:#059669'>✓ Director row already existed (id #{$director['id']}) — fields refreshed.</p>";
} else {
    db()->prepare(
        "INSERT INTO specialists (name, role, qualifications, bio, photo, order_no, active)
         VALUES (?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        'Dr. P. K. Jha',
        'Director & Life Coach',
        'M.Ch (AIIMS) · Neurosurgeon · 30+ yrs',
        'Founder of Empowerstudents.in. Senior neurosurgeon and life coach with three decades of experience in paediatric neuro-development.',
        'director.png',
        80,
    ]);
    echo "<p style='color:#059669;font-weight:700'>✓ Inserted Dr. P. K. Jha as the 8th specialist (Director).</p>";
}

// Final state — show current panel
echo "<h2>Final panel state</h2>";
$rows = db()->query("SELECT order_no, name, role, photo, active FROM specialists ORDER BY order_no, id")->fetchAll();
echo "<table style='border-collapse:collapse;width:100%;background:#fafafa;border:1px solid #ddd'>";
echo "<thead><tr style='background:#eee'>"
   . "<th style='padding:6px 10px'>#</th>"
   . "<th style='padding:6px 10px;text-align:left'>Name</th>"
   . "<th style='padding:6px 10px;text-align:left'>Role</th>"
   . "<th style='padding:6px 10px;text-align:left'>Photo file</th>"
   . "<th style='padding:6px 10px'>Active</th>"
   . "</tr></thead><tbody>";
foreach ($rows as $r) {
    echo "<tr style='border-top:1px solid #eee'>"
       . "<td style='padding:6px 10px;text-align:center'>" . (int)$r['order_no'] . "</td>"
       . "<td style='padding:6px 10px;font-weight:600'>" . htmlspecialchars($r['name']) . "</td>"
       . "<td style='padding:6px 10px'>" . htmlspecialchars($r['role']) . "</td>"
       . "<td style='padding:6px 10px;font-family:monospace;color:#888'>" . htmlspecialchars($r['photo']) . "</td>"
       . "<td style='padding:6px 10px;text-align:center'>" . ((int)$r['active'] ? '✓' : '—') . "</td>"
       . "</tr>";
}
echo "</tbody></table>";

echo "<p style='margin-top:18px'><strong>$updated</strong> renamed · <strong>$skipped</strong> already correct.</p>";
echo "<p style='color:#dc2626;font-weight:700'>⚠ DELETE this file (update_panel.php) from the server now.</p>";
echo "<p>Don't forget to upload <code>director.png</code> (your photo) to <code>/assets/images/</code>.</p>";
echo "</body></html>";
