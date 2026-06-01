<?php
/**
 * partner-forgot.php  — fresh-v12
 *
 * 3-step OTP password reset for partners:
 *   Step 1 — Enter WhatsApp number → send OTP
 *   Step 2 — Enter OTP
 *   Step 3 — Set new password → redirect to /partner-login.php?just_set=1
 *
 * PHP 7.4 / GoDaddy / SQLite
 * Uses partner_send_reset_otp() and partner_verify_otp_and_reset()
 * from includes/partner_auth.php (fresh-v12 additions).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/partner_auth.php';

// Already logged in → go to dashboard
if (current_partner()) {
    header('Location: /partner-dashboard.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title  = 'Reset Password — EmpowerStudents Partner';
$step        = 'phone';   // phone | otp | password | done
$flash_error = '';
$flash_ok    = '';

/* ── POST: send OTP ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    $wa = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
    $result = partner_send_reset_otp($wa);
    if ($result['ok']) {
        $_SESSION['pf_whatsapp'] = $wa;
        // Demo: expose OTP in flash for testing
        if (!empty($result['demo_otp'])) {
            $_SESSION['pf_demo_otp'] = $result['demo_otp'];
        }
        $step = 'otp';
    } else {
        $flash_error = $result['message'];
        $step = 'phone';
    }
}

/* ── POST: verify OTP ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $wa  = $_SESSION['pf_whatsapp'] ?? '';
    $otp = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
    if (!$wa) {
        $flash_error = 'Session expired. Please start again.';
        $step = 'phone';
    } elseif (strlen($otp) < 4) {
        $flash_error = 'Enter the 6-digit OTP.';
        $step = 'otp';
    } else {
        // Quick verify without resetting password yet — reuse same check
        $phone = '+91' . substr($wa, -10);
        $st    = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$phone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $otp_ok = false;
        if ($row && empty($row['used_at']) && strtotime($row['expires_at']) >= time()
            && password_verify($otp, (string)$row['code_hash'])) {
            $otp_ok = true;
            $_SESSION['pf_otp_verified'] = $otp;   // store raw code for final step
        }
        if ($otp_ok) {
            $step = 'password';
        } else {
            $flash_error = $row
                ? (empty($row['used_at']) ? 'Incorrect or expired OTP. Try again.' : 'OTP already used. Request a new one.')
                : 'No OTP found. Please request a new one.';
            $step = 'otp';
        }
    }
}

/* ── POST: set new password ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_password') {
    $wa    = $_SESSION['pf_whatsapp']     ?? '';
    $otp   = $_SESSION['pf_otp_verified'] ?? '';
    $pass  = (string)($_POST['password']  ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if (!$wa || !$otp) {
        $flash_error = 'Session expired. Please start again.';
        $step = 'phone';
    } elseif (strlen($pass) < 6) {
        $flash_error = 'Password must be at least 6 characters.';
        $step = 'password';
    } elseif ($pass !== $pass2) {
        $flash_error = 'Passwords do not match.';
        $step = 'password';
    } else {
        $result = partner_verify_otp_and_reset($wa, $otp, $pass);
        if ($result['ok']) {
            // Clean up session
            unset($_SESSION['pf_whatsapp'], $_SESSION['pf_otp_verified'], $_SESSION['pf_demo_otp']);
            header('Location: /partner-login.php?just_set=1');
            exit;
        } else {
            $flash_error = $result['message'];
            $step = 'password';
        }
    }
}

/* ── Resend OTP (GET ?resend=1) ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['resend'])) {
    $wa = $_SESSION['pf_whatsapp'] ?? '';
    if ($wa) {
        $result = partner_send_reset_otp($wa);
        $step = 'otp';
        if (!$result['ok']) {
            $flash_error = $result['message'];
        } else {
            $flash_ok = 'OTP resent.';
            if (!empty($result['demo_otp'])) $_SESSION['pf_demo_otp'] = $result['demo_otp'];
        }
    } else {
        $step = 'phone';
    }
}

/* ── If session has whatsapp but we're at phone, jump to otp/password ────── */
if ($step === 'phone' && !empty($_SESSION['pf_whatsapp'])) {
    $step = empty($_SESSION['pf_otp_verified']) ? 'otp' : 'password';
}

$demo_otp = $_SESSION['pf_demo_otp'] ?? '';
$wa_display = !empty($_SESSION['pf_whatsapp'])
    ? '+91 ' . substr($_SESSION['pf_whatsapp'], -10) : '';

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-md mx-auto px-4 py-10">
  <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8">

    <div class="mb-5">
      <a href="/partner-login.php" class="text-xs text-indigo-600 hover:underline">← Back to sign-in</a>
    </div>

    <h1 class="text-2xl font-bold text-slate-900 mb-1">Reset your password</h1>
    <p class="text-slate-500 text-sm mb-6">We'll send a one-time code to your registered WhatsApp number.</p>

    <?php if ($flash_error): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4">
        <?= e($flash_error) ?>
      </div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm mb-4">
        <?= e($flash_ok) ?>
      </div>
    <?php endif; ?>
    <?php if ($demo_otp && $step === 'otp'): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-sm mb-4 font-mono">
        🛠 DEMO MODE — OTP: <strong><?= e($demo_otp) ?></strong>
      </div>
    <?php endif; ?>

    <!-- Progress pills -->
    <div class="flex items-center gap-2 mb-6 text-xs font-semibold">
      <span class="<?= $step === 'phone'    ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500' ?> px-3 py-1 rounded-full">1 WhatsApp</span>
      <span class="text-slate-300">→</span>
      <span class="<?= $step === 'otp'      ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500' ?> px-3 py-1 rounded-full">2 OTP</span>
      <span class="text-slate-300">→</span>
      <span class="<?= $step === 'password' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500' ?> px-3 py-1 rounded-full">3 New Password</span>
    </div>

    <?php if ($step === 'phone'): /* ═══ STEP 1: WhatsApp number ═══ */ ?>

      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="send_otp">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Your registered WhatsApp number</label>
          <input type="tel" name="whatsapp" required autofocus
                 placeholder="9311696923"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm">
          <p class="text-xs text-slate-400 mt-1">Enter the number linked to your partner account.</p>
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full text-sm">
          Send OTP on WhatsApp →
        </button>
      </form>

    <?php elseif ($step === 'otp'): /* ═══ STEP 2: OTP ═══ */ ?>

      <p class="text-sm text-slate-600 mb-4">
        OTP sent to <strong><?= e($wa_display) ?></strong>. Valid for 10 minutes.
      </p>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="verify_otp">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">6-digit OTP</label>
          <input type="text" name="otp" required autofocus inputmode="numeric"
                 pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                 placeholder="123456"
                 class="w-full border-2 border-slate-300 rounded-lg px-3 py-3 text-center text-2xl font-bold tracking-widest focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full text-sm">
          Verify OTP →
        </button>
      </form>

      <div class="flex items-center justify-between mt-4 text-xs">
        <a href="/partner-forgot.php?resend=1" class="text-indigo-600 hover:underline">Resend OTP</a>
        <a href="/partner-forgot.php" class="text-slate-400 hover:text-slate-600 underline"
           onclick="<?php
             // Clear session on restart
           ?>">Start again</a>
      </div>

    <?php elseif ($step === 'password'): /* ═══ STEP 3: New Password ═══ */ ?>

      <p class="text-sm text-emerald-700 font-semibold mb-4">✓ OTP verified. Set your new password.</p>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="set_password">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">New password</label>
          <input type="password" name="password" required autofocus
                 minlength="6" autocomplete="new-password"
                 placeholder="Min 6 characters"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Confirm password</label>
          <input type="password" name="password2" required
                 minlength="6" autocomplete="new-password"
                 placeholder="Repeat password"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm">
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full text-sm">
          Save new password →
        </button>
      </form>

    <?php endif; ?>

    <p class="text-xs text-slate-400 mt-8 text-center">
      Need help? WhatsApp <a href="https://wa.me/919311883132" class="text-indigo-500 hover:underline">+91-9311883132</a>
    </p>

  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
