<?php
require __DIR__ . '/_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'change_pwd') {
        $new = $_POST['new'] ?? '';
        if (strlen($new) < 8) { flash('Password must be at least 8 characters.', 'rose'); }
        else {
            db()->prepare("UPDATE admins SET pass_hash = ? WHERE id = ?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
            flash('Password updated.');
        }
        header('Location: /admin/settings.php'); exit;
    }
}

$cf_ok    = cf_is_configured();
$twilio_ok = TWILIO_SID && TWILIO_TOKEN && (defined('TWILIO_CONTENT_SID') && TWILIO_CONTENT_SID);
$ant_ok   = ANTHROPIC_API_KEY && strpos(ANTHROPIC_API_KEY, 'PASTE') === false;

admin_layout_open('Settings');
admin_render_flash();
?>
<div class="grid lg:grid-cols-2 gap-4">
  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">Change admin password</h2>
    <form method="post" class="flex gap-2">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_pwd">
      <input type="password" name="new" required minlength="8" placeholder="New password (min 8 chars)" class="flex-1 border border-slate-200 rounded-lg p-2 text-sm">
      <button class="bg-slate-800 text-white px-4 rounded-lg text-sm">Update</button>
    </form>
  </section>

  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">Integration status</h2>
    <ul class="text-sm space-y-2">
      <li><?= $ant_ok ? '✅' : '❌' ?> <strong>Anthropic Claude</strong> — model <code class="text-xs"><?= e(ANTHROPIC_MODEL) ?></code><?= $ant_ok ? '' : ' — set ANTHROPIC_API_KEY in includes/config.php' ?></li>
      <li><?= $cf_ok ? '✅' : '❌' ?> <strong>Cashfree</strong> — env <code class="text-xs"><?= e(CASHFREE_ENV) ?></code><?= $cf_ok ? '' : ' — set CASHFREE_APP_ID and CASHFREE_SECRET_KEY' ?></li>
      <li><?= $twilio_ok ? '✅' : '❌' ?> <strong>Twilio WhatsApp</strong> — <?= $twilio_ok ? 'ContentSid configured' : 'set TWILIO_SID, TWILIO_TOKEN and TWILIO_CONTENT_SID' ?></li>
      <li>OTP mode: <code class="text-xs"><?= e(OTP_MODE) ?></code></li>
    </ul>
    <p class="text-xs text-slate-400 mt-3">Edit <code>includes/config.php</code> or set as Apache environment variables to flip modes.</p>
  </section>

  <section class="bg-white rounded-2xl border border-slate-200 p-5 lg:col-span-2">
    <h2 class="font-semibold mb-3">Webhook URLs to configure</h2>
    <p class="text-sm text-slate-600 mb-2">In Cashfree dashboard → Developer → Webhooks, set:</p>
    <code class="block bg-slate-50 border border-slate-200 rounded-lg p-2 text-xs">
      <?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN)) ?>/payment_webhook.php
    </code>
    <p class="text-sm text-slate-600 mb-2 mt-3">Allowed return URL (also in Cashfree dashboard):</p>
    <code class="block bg-slate-50 border border-slate-200 rounded-lg p-2 text-xs">
      <?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN)) ?>/payment_return.php
    </code>
  </section>
</div>
<?php admin_layout_close(); ?>
