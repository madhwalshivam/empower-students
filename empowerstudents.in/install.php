<?php
/**
 * One-time installer. Run by visiting:
 *   https://empowerstudents.in/install.php
 *
 * Creates SQLite DB, all tables, seeds the default admin and the 7 specialist panel.
 * Safe to re-run — uses CREATE TABLE IF NOT EXISTS and INSERT OR IGNORE.
 *
 * IMPORTANT: After successful run, DELETE this file from the server.
 */

require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    db_init();
    $ok = true;
    $err = '';
} catch (Throwable $e) {
    $ok = false;
    $err = $e->getMessage();
}

$db_exists = file_exists(DB_PATH);
$db_writable = $db_exists ? is_writable(DB_PATH) : is_writable(dirname(DB_PATH));
$uploads_writable = is_writable(UPLOAD_DIR) || is_writable(dirname(UPLOAD_DIR));
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Install · Empower Students</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;color:#0f172a}
.ok{color:#059669}.bad{color:#dc2626}.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:12px 0}
code{background:#0f172a;color:#a7f3d0;padding:2px 6px;border-radius:4px;font-size:.9em}</style></head>
<body>
<h1>Empower Students — installer</h1>

<?php if ($ok): ?>
  <div class="box"><strong class="ok">✓ Database initialised successfully.</strong></div>
<?php else: ?>
  <div class="box"><strong class="bad">✗ Initialisation failed.</strong><br><code><?= htmlspecialchars($err) ?></code></div>
<?php endif; ?>

<div class="box">
  <h3>Environment check</h3>
  <ul>
    <li>PHP version: <?= PHP_VERSION ?> <?= version_compare(PHP_VERSION, '7.4', '>=') ? '<span class="ok">✓</span>' : '<span class="bad">need 7.4+</span>' ?></li>
    <li>PDO SQLite: <?= extension_loaded('pdo_sqlite') ? '<span class="ok">✓ loaded</span>' : '<span class="bad">✗ missing — enable in cPanel</span>' ?></li>
    <li>cURL: <?= extension_loaded('curl') ? '<span class="ok">✓ loaded</span>' : '<span class="bad">✗ missing</span>' ?></li>
    <li>DB path writable: <?= $db_writable ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>' ?> (<code><?= htmlspecialchars(DB_PATH) ?></code>)</li>
    <li>Uploads dir writable: <?= $uploads_writable ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>' ?> (<code><?= htmlspecialchars(UPLOAD_DIR) ?></code>)</li>
    <li>Anthropic key set: <?= (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY && strpos(ANTHROPIC_API_KEY, 'PASTE') === false) ? '<span class="ok">✓</span>' : '<span class="bad">✗ — edit includes/config.php</span>' ?></li>
    <li>Cashfree configured: <?= (defined('CASHFREE_APP_ID') && CASHFREE_APP_ID && defined('CASHFREE_SECRET_KEY') && CASHFREE_SECRET_KEY) ? '<span class="ok">✓ env=' . htmlspecialchars(CASHFREE_ENV) . '</span>' : '<span class="bad">✗ — set CASHFREE_APP_ID and CASHFREE_SECRET_KEY</span>' ?></li>
    <li>Twilio WhatsApp configured: <?= (defined('TWILIO_SID') && TWILIO_SID && defined('TWILIO_CONTENT_SID') && TWILIO_CONTENT_SID) ? '<span class="ok">✓ ContentSid set</span>' : '<span class="bad">✗ — set TWILIO_SID, TWILIO_TOKEN, TWILIO_CONTENT_SID (mandatory — plain WhatsApp body messages are silently dropped by Meta)</span>' ?></li>
  </ul>
</div>

<div class="box">
  <h3>Default credentials</h3>
  <p>Admin: <code>admin</code> / <code>empower@2026</code> — <strong>change immediately</strong> from <code>/admin/settings.php</code>.</p>
  <p>OTP mode: <code><?= defined('OTP_MODE') ? OTP_MODE : '?' ?></code> &nbsp;
     (<em>demo</em> shows the OTP on screen — fine for testing; switch to <em>twilio_wa</em>, <em>msg91</em> or <em>wati</em> for production)</p>
  <p>Signup bonus: <strong><?= (int)SIGNUP_FREE_CREDITS ?></strong> credits per new parent.<br>
     Comprehensive AI report cap: <strong><?= (int)COMPREHENSIVE_REPORT_MAX_PER_CHILD ?></strong> per child (lifetime).</p>
</div>

<div class="box">
  <h3>Webhook URLs to register in your providers</h3>
  <ul>
    <li><strong>Cashfree</strong> webhook → <code><?= htmlspecialchars(($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN) ?>/payment_webhook.php</code></li>
    <li><strong>Cashfree</strong> return URL whitelist → <code><?= htmlspecialchars(($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN) ?>/payment_return.php</code></li>
  </ul>
</div>

<div class="box">
  <h3>Next steps</h3>
  <ol>
    <li>Open <a href="/">the homepage</a> and try parent login.</li>
    <li>Upload specialist photos to <code>/assets/images/</code> — see <code>README.txt</code> there for filenames.</li>
    <li><strong>Delete this <code>install.php</code> file</strong> from the server.</li>
  </ol>
</div>
</body></html>
