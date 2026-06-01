<?php
/**
 * partner-set-password.php
 *
 * Magic-link landing for partners to set or reset their login password.
 * Token is single-use and expires 24h after generation.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/partner_auth.php';

$page_title = 'Set your partner password — EmpowerStudents';
$page_description = 'Set your password to access your EmpowerStudents partner account.';

$token   = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$partner = $token ? partner_by_setup_token($token) : null;
$flash_error = '';
$flash_ok    = '';

if (!$partner) {
    $flash_error = 'This setup link is invalid or has expired (24-hour validity). Please ask admin to send a fresh link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $partner && csrf_check($_POST['csrf'] ?? '')) {
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if (strlen($password) < 6) {
        $flash_error = 'Password should be at least 6 characters.';
    } elseif ($password !== $password2) {
        $flash_error = 'The two passwords don\'t match.';
    } else {
        if (partner_set_password((int)$partner['id'], $password)) {
            $_SESSION['flash_ok'] = 'Password set! Please sign in.';
            header('Location: /partner-login.php?just_set=1');
            exit;
        } else {
            $flash_error = 'Could not save your password. Please try again.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-md mx-auto px-4 py-10">
  <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8">
    <h1 class="text-2xl font-bold text-slate-900 mb-1">Set your password</h1>
    <p class="text-slate-600 text-sm mb-6">
      <?php if ($partner): ?>
        Welcome <?= e($partner['name']) ?>! Choose a password to access your partner dashboard.
      <?php else: ?>
        We need a valid setup link to continue.
      <?php endif; ?>
    </p>

    <?php if ($flash_error): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4">
        <?= e($flash_error) ?>
      </div>
    <?php endif; ?>

    <?php if ($partner): ?>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">New password</label>
          <input type="password" name="password" required minlength="6" autocomplete="new-password"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                 placeholder="At least 6 characters">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Confirm password</label>
          <input type="password" name="password2" required minlength="6" autocomplete="new-password"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full">
          Set password & sign in
        </button>

        <p class="text-xs text-slate-500 mt-2 text-center">
          Your WhatsApp number on file: <strong><?= e($partner['whatsapp']) ?></strong>
        </p>
      </form>
    <?php else: ?>
      <div class="text-center pt-2">
        <a href="/partner-login.php" class="text-sm text-indigo-600 hover:underline">Already have a password? Sign in</a>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
