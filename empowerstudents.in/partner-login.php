<?php
/**
 * partner-login.php  — fresh-v12b
 *
 * OTP-based partner login. No password needed.
 * Flow (all on this page, no redirects to index):
 *   Step 1 — Enter WhatsApp → validate partner exists → send OTP
 *   Step 2 — Enter OTP → verified → session set → go to dashboard
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/partner_auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (current_partner()) {
    header('Location: /partner-dashboard.php');
    exit;
}

$page_title       = 'Partner Sign-in — EmpowerStudents';
$page_description = 'Sign in to your EmpowerStudents partner account.';
$flash_ok         = '';
$flash_error      = '';
$step             = 'phone';

if (!empty($_GET['logged_out'])) $flash_ok = 'Signed out.';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = $_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

if (!empty($_SESSION['pl_whatsapp'])) $step = 'otp';

/* ── send_otp ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    $wa = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
    $result = partner_send_reset_otp($wa);
    if ($result['ok']) {
        $_SESSION['pl_whatsapp'] = $wa;
        if (!empty($result['demo_otp'])) $_SESSION['pl_demo_otp'] = $result['demo_otp'];
        $step = 'otp';
    } else {
        $flash_error = $result['message'];
        $step = 'phone';
    }
}

/* ── verify_otp → login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $wa      = $_SESSION['pl_whatsapp'] ?? '';
    $entered = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
    if (!$wa) {
        $flash_error = 'Session expired. Enter your WhatsApp number again.';
        $step = 'phone';
    } elseif (strlen($entered) < 4) {
        $flash_error = 'Enter the 6-digit OTP.';
        $step = 'otp';
    } else {
        $phone = '+91' . substr($wa, -10);
        $st = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$phone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $ok = $row && empty($row['used_at'])
              && strtotime($row['expires_at']) >= time()
              && password_verify($entered, (string)$row['code_hash']);
        if ($ok) {
            db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$row['id']]);
            $pst = db()->prepare("SELECT id, status FROM partners WHERE whatsapp = ?");
            $pst->execute([$wa]);
            $partner = $pst->fetch(PDO::FETCH_ASSOC);
            if (!$partner || $partner['status'] !== 'active') {
                $flash_error = 'Partner account not active. Contact admin.';
                $step = 'phone';
                unset($_SESSION['pl_whatsapp']);
            } else {
                $_SESSION[PARTNER_SESSION_KEY] = (int)$partner['id'];
                db()->prepare("UPDATE partners SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$partner['id']]);
                unset($_SESSION['pl_whatsapp'], $_SESSION['pl_demo_otp']);
                $next = $_GET['next'] ?? '/partner-dashboard.php';
                if (!preg_match('#^/[a-zA-Z0-9_/.?=&-]*$#', $next)) $next = '/partner-dashboard.php';
                header('Location: ' . $next);
                exit;
            }
        } else {
            $flash_error = !$row ? 'No OTP found. Request a new one.'
                : (!empty($row['used_at']) ? 'OTP already used. Request a new one.'
                : (strtotime($row['expires_at']) < time() ? 'OTP expired. Request a new one.'
                : 'Incorrect OTP. Try again.'));
            $step = 'otp';
        }
    }
}

/* ── resend ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['resend'])) {
    $wa = $_SESSION['pl_whatsapp'] ?? '';
    if ($wa) {
        $result = partner_send_reset_otp($wa);
        $step = 'otp';
        if ($result['ok']) {
            $flash_ok = 'OTP resent.';
            if (!empty($result['demo_otp'])) $_SESSION['pl_demo_otp'] = $result['demo_otp'];
        } else {
            $flash_error = $result['message'];
        }
    } else { $step = 'phone'; }
}

/* ── restart ── */
if (!empty($_GET['restart'])) {
    unset($_SESSION['pl_whatsapp'], $_SESSION['pl_demo_otp']);
    header('Location: /partner-login.php'); exit;
}

/* ── apply_partner — save application for admin review ── */
$show_apply  = false;   // true = show apply form instead of error
$apply_wa    = '';      // pre-fill WhatsApp on apply form
$apply_done  = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_partner') {
    $app_name   = trim((string)($_POST['app_name']   ?? ''));
    $app_clinic = trim((string)($_POST['app_clinic'] ?? ''));
    $app_wa     = preg_replace('/\D/', '', (string)($_POST['app_wa'] ?? ''));
    $app_city   = trim((string)($_POST['app_city']   ?? ''));
    if ($app_name && $app_wa && strlen($app_wa) >= 10) {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS partner_applications (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                clinic     TEXT,
                whatsapp   TEXT NOT NULL,
                city       TEXT,
                status     TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            // Avoid duplicate pending applications
            $dup = db()->prepare("SELECT id FROM partner_applications WHERE whatsapp=? AND status='pending' LIMIT 1");
            $dup->execute([$app_wa]);
            if (!$dup->fetchColumn()) {
                db()->prepare("INSERT INTO partner_applications (name, clinic, whatsapp, city) VALUES (?,?,?,?)")
                   ->execute([$app_name, $app_clinic, $app_wa, $app_city]);
            }
            $apply_done = true;
            $flash_ok   = '✅ Application submitted! We\'ll review and WhatsApp you within 24 hours.';
        } catch (Throwable $e) {
            $flash_error = 'Could not save application. Please try again.';
        }
    } else {
        $flash_error = 'Please enter your name and WhatsApp number.';
        $show_apply  = true;
        $apply_wa    = $app_wa;
    }
}

// When send_otp returns "No partner account found", show apply form
if ($flash_error === 'No partner account found with that WhatsApp number.') {
    $show_apply  = true;
    $apply_wa    = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
    $flash_error = '';   // suppress the red error box — apply form replaces it
}

// Direct ?apply=1 link
if (!empty($_GET['apply']) && $step === 'phone') {
    $show_apply = true;
}

$demo_otp   = $_SESSION['pl_demo_otp'] ?? '';
$wa_display = !empty($_SESSION['pl_whatsapp']) ? '+91 ' . substr($_SESSION['pl_whatsapp'], -10) : '';

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-md mx-auto px-4 py-10">
  <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8">

    <h1 class="text-2xl font-bold text-slate-900 mb-1">Partner sign-in</h1>
    <p class="text-slate-600 text-sm mb-6">We'll send a one-time code to your registered WhatsApp.</p>

    <?php if ($flash_ok): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm mb-4"><?= e($flash_ok) ?></div>
    <?php endif; ?>
    <?php if ($flash_error && !$show_apply): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4"><?= e($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($demo_otp && $step === 'otp'): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-3 text-sm mb-4 font-mono">🛠 DEMO — OTP: <strong><?= e($demo_otp) ?></strong></div>
    <?php endif; ?>

    <?php if ($show_apply && !$apply_done): /* ═══ APPLY FORM ═══ */ ?>

      <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-4">
        <p class="text-sm font-semibold text-indigo-900 mb-1">🤝 Not registered as a partner yet?</p>
        <p class="text-xs text-indigo-700">Fill in your details — admin will review and activate your account within 24 hours. You'll get a WhatsApp confirmation.</p>
      </div>

      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="apply_partner">
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Your Name *</label>
          <input type="text" name="app_name" required autofocus placeholder="Dr. Sharma"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Clinic / Organisation</label>
          <input type="text" name="app_clinic" placeholder="Sunrise Therapy Centre"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">WhatsApp Number *</label>
          <input type="tel" name="app_wa" required placeholder="9XXXXXXXXX" maxlength="12"
                 value="<?= e($apply_wa) ?>"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">City / Area</label>
          <input type="text" name="app_city" placeholder="Noida, Greater Noida…"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-2.5 rounded-xl hover:opacity-90 w-full text-sm">
          Submit Application →
        </button>
        <div class="text-center">
          <a href="/partner-login.php" class="text-xs text-slate-400 hover:text-slate-600 underline">← Back to sign-in</a>
        </div>
      </form>

    <?php elseif ($step === 'phone'): /* ═══ STEP 1: WhatsApp ═══ */ ?>

      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="send_otp">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">WhatsApp number</label>
          <input type="tel" name="whatsapp" required autofocus autocomplete="tel"
                 placeholder="9311696923"
                 class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm">
          <p class="text-xs text-slate-400 mt-1">Enter the number registered with your partner account.</p>
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full text-sm">
          Send OTP on WhatsApp →
        </button>
      </form>

      <div class="mt-5 pt-4 border-t border-slate-100 text-center">
        <p class="text-xs text-slate-400">New here? <a href="/partner-login.php?apply=1" class="text-indigo-500 hover:underline">Apply to become a partner →</a></p>
      </div>

    <?php else: /* ═══ STEP 2: OTP ═══ */ ?>

      <p class="text-sm text-slate-600 mb-1">OTP sent to <strong><?= e($wa_display) ?></strong></p>
      <p class="text-xs text-slate-400 mb-4">Valid for 10 minutes.</p>

      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="verify_otp">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">6-digit OTP</label>
          <input type="text" name="otp" required autofocus inputmode="numeric"
                 pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                 placeholder="123456"
                 class="w-full border-2 border-slate-300 rounded-lg px-3 py-3 text-center text-2xl font-bold tracking-widest focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 focus:outline-none">
        </div>
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full text-sm">
          ✓ Verify &amp; Sign in →
        </button>
      </form>

      <div class="flex items-center justify-between mt-4 text-xs">
        <a href="/partner-login.php?resend=1" class="text-indigo-600 hover:underline">↺ Resend OTP</a>
        <a href="/partner-login.php?restart=1" class="text-slate-400 hover:text-slate-600 underline">← Change number</a>
      </div>

    <?php endif; ?>

  </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
