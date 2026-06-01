<?php
/**
 * restore_and_fix_pause.php?key=nci2026admin
 *
 * 1. Restores parent-reflect.php from backup_before_pause_fix (un-breaks the page)
 * 2. Applies a MINIMAL pause-button fix that doesn't touch the JS section
 *
 * Visit ONCE, see ✓ output, DELETE.
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

$path = __DIR__ . '/parent-reflect.php';
$backup = $path . '.backup_before_pause_fix';

echo "=== Restore + safe pause fix ===\n\n";

// 1. Restore from backup
echo "1. Restoring parent-reflect.php from backup\n";
if (!file_exists($backup)) {
    echo "   ❌ Backup not found: $backup\n";
    echo "   Looking for alternates...\n";
    foreach (glob($path . '.backup_*') as $alt) {
        echo "   Found: $alt\n";
    }
    exit(1);
}
copy($backup, $path);
echo "   ✓ Restored from " . basename($backup) . "\n";

// Lint the restored file
exec("php -l " . escapeshellarg($path) . " 2>&1", $out, $rc);
if ($rc !== 0) {
    echo "   ❌ Restored file STILL has lint errors:\n" . implode("\n", $out) . "\n";
    exit(1);
}
echo "   ✓ Restored file lints clean\n";

$src = file_get_contents($path);

// 2. Strip ALL existing pause buttons safely (only the button HTML, never JS)
echo "\n2. Removing any existing pause buttons\n";
$pattern = '/\s*<button\s+type="button"\s+id="iPauseBtn"[^>]*onclick="[^"]*"\s*>[^<]*<\/button>\s*<!--\s*v3-engine: Pause button\s*-->/';
$src = preg_replace($pattern, '', $src, -1, $removed);
echo "   ✓ Removed $removed pause button(s)\n";

// 3. Insert ONE clean pause button right after End early
echo "\n3. Inserting one fresh pause button\n";
$new_pause = ' <button type="button" id="iPauseBtn"'
           . ' class="ml-2 text-xs text-amber-700 hover:text-amber-900 underline"'
           . ' onclick="(async()=>{'
           . 'if(typeof sessionId===\'undefined\'||!sessionId){alert(\'Session not started yet.\');return;}'
           . 'const fd=new FormData();'
           . 'fd.append(\'csrf\',(window.PR_CSRF||\'\'));'
           . 'fd.append(\'session_id\',sessionId);'
           . 'try{const r=await fetch(\'/parent-reflect-pause.php\',{method:\'POST\',body:fd,credentials:\'same-origin\'});const j=await r.json();'
           . 'if(j&&j.ok){window.location.href=\'/dashboard.php?paused=1\';}'
           . 'else{alert(j&&j.error?j.error:\'Could not pause\');}}'
           . 'catch(e){alert(\'Network error\');}'
           . '})()"'
           . '>⏸ Pause & resume later</button> <!-- v3-engine: Pause button -->';

$anchor = '<button id="iEndBtn" type="button" class="text-xs text-slate-400 hover:text-rose-600 underline">End early</button>';
if (strpos($src, $anchor) === false) {
    echo "   ❌ End early button not found.\n"; exit(1);
}
$src = str_replace($anchor, $anchor . $new_pause, $src);
echo "   ✓ Inserted pause button\n";

// Lint final
$tmp = tempnam(sys_get_temp_dir(), 'pbf2');
file_put_contents($tmp, $src);
exec("php -l " . escapeshellarg($tmp) . " 2>&1", $o2, $rc2);
unlink($tmp);
if ($rc2 !== 0) {
    echo "❌ Final file has lint errors:\n" . implode("\n", $o2) . "\n"; exit(1);
}

file_put_contents($path, $src);
echo "\n4. Lint check\n   ✓ Final file lints clean\n";

// Count
$count = substr_count($src, 'id="iPauseBtn"');
echo "\n5. Pause buttons in final file: $count (should be 1)\n";
if ($count !== 1) { echo "   ❌ expected 1\n"; exit(1); }

echo "\n✅ Done. Test by:\n";
echo "   1. Hard refresh /parent-reflect.php?fresh=1 in browser (Ctrl+Shift+R)\n";
echo "   2. Page should load fully (no syntax error in console)\n";
echo "   3. Start a session, wait for turn 1 to render\n";
echo "   4. Click ⏸ Pause & resume later → should redirect to dashboard\n";
echo "\nDELETE this file after testing.\n";
