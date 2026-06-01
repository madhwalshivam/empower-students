<?php
/**
 * migrate_leda_to_webroot.php
 *
 * Moves Leda voice MP3 cache from /uploads/leda/ to /leda/ to bypass
 * the /uploads/ "Deny from all" .htaccess. Safe to run multiple times.
 *
 * Visit ONCE in browser, then DELETE this file.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();

$old_dir = __DIR__ . '/uploads/leda';
$new_dir = __DIR__ . '/leda';

echo "=== Leda cache migration ===\n";
echo "  old: $old_dir\n";
echo "  new: $new_dir\n\n";

// 1. Create new dir
if (!is_dir($new_dir)) {
    if (!@mkdir($new_dir, 0775, true)) {
        echo "❌ Could not create $new_dir\n"; exit(1);
    }
    echo "✓ Created /leda/\n";
} else {
    echo "✓ /leda/ already exists\n";
}

// 2. Write .htaccess
$htaccess = $new_dir . '/.htaccess';
$content = "# Leda voice MP3 cache.\n"
         . "# These are TTS-synthesized audio (hashed by content; no PII).\n"
         . "# Safe to serve directly.\n"
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
         . "  AddType audio/mpeg .mp3\n"
         . "</IfModule>\n"
         . "<IfModule mod_headers.c>\n"
         . "  Header set Cache-Control \"public, max-age=2592000, immutable\"\n"
         . "</IfModule>\n";
@file_put_contents($htaccess, $content);
@chmod($htaccess, 0644);
echo "✓ Wrote .htaccess (" . filesize($htaccess) . " bytes)\n";

// 3. Move existing MP3s
$moved = 0; $skipped = 0;
if (is_dir($old_dir)) {
    $files = glob($old_dir . '/*.mp3');
    foreach ($files as $src) {
        $bn = basename($src);
        $dst = $new_dir . '/' . $bn;
        if (file_exists($dst)) { $skipped++; continue; }
        if (@rename($src, $dst)) {
            @chmod($dst, 0644);
            $moved++;
        }
    }
    echo "✓ Moved $moved MP3s (skipped $skipped already-present)\n";
}

// 4. Update DB rows pointing at old paths
echo "\nUpdating DB references…\n";
$tables_cols = [
    ['parent_reflect_sessions', 'summary_audio_hi'],
    ['parent_reflect_sessions', 'summary_audio_en'],
    ['home_course_days', 'meditation_audio'],
    ['home_course_days', 'affirmation_audio'],
    ['home_course_days', 'motivation_audio'],
];
foreach ($tables_cols as $tc) {
    list($table, $col) = $tc;
    try {
        $cols = db()->query("PRAGMA table_info($table)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array($col, $names, true)) continue;
        $st = db()->prepare("UPDATE $table SET $col = REPLACE($col, '/uploads/leda/', '/leda/') WHERE $col LIKE '/uploads/leda/%'");
        $st->execute();
        echo "  $table.$col: updated " . $st->rowCount() . " row(s)\n";
    } catch (Throwable $e) {
        echo "  $table.$col: skip — " . $e->getMessage() . "\n";
    }
}

echo "\nNow test:\n";
echo "  Visit https://empowerstudents.in/leda/40a07eb7055f2e8d438144886b547b3a1642f9b1d864a53c9ede80af696d885b.mp3\n";
echo "  (should play / download — not 403)\n\n";
echo "After upload of updated leda_tts.php and test success:\n";
echo "  • DELETE this file from server\n";
echo "  • DELETE /uploads/leda/ directory (empty now)\n";
echo "  • DELETE /uploads/leda/.htaccess (no longer needed)\n";
