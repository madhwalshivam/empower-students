<?php
/**
 * create_leda_htaccess.php
 *
 * One-shot to write the .htaccess file inside /uploads/leda/ that allows
 * public read access to Leda MP3 files (overrides the parent /uploads/
 * "Deny from all").
 *
 * Visit ONCE in browser, then DELETE.
 */

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/uploads/leda';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
if (!is_dir($dir)) {
    echo "❌ Could not create $dir\n";
    exit(1);
}

$htaccess = $dir . '/.htaccess';
$content = "# Allow public access to Leda voice MP3 files.\n"
         . "# Overrides the parent /uploads/ Deny-all.\n"
         . "<RequireAll>\n"
         . "  Require all granted\n"
         . "</RequireAll>\n"
         . "<IfModule !mod_authz_core.c>\n"
         . "  Order Allow,Deny\n"
         . "  Allow from all\n"
         . "</IfModule>\n"
         . "<IfModule mod_mime.c>\n"
         . "  AddType audio/mpeg .mp3\n"
         . "</IfModule>\n"
         . "<IfModule mod_headers.c>\n"
         . "  Header set Cache-Control \"public, max-age=2592000, immutable\"\n"
         . "</IfModule>\n";

$ok = @file_put_contents($htaccess, $content);
if ($ok === false) {
    echo "❌ Could not write $htaccess\n";
    exit(1);
}

@chmod($htaccess, 0644);

echo "✓ Wrote $htaccess (" . filesize($htaccess) . " bytes, perms " . substr(sprintf('%o', fileperms($htaccess)), -4) . ")\n\n";
echo "Now test:\n";
echo "  Open https://empowerstudents.in/uploads/leda/.htaccess (should be 403 — that's fine, .htaccess shouldn't be readable)\n";
echo "  Open one of your MP3 URLs — should now load instead of 403\n\n";
echo "DELETE this file from server now.\n";
