<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sms.php';

$page_title = 'Parent Login';
$step = 'phone';
$error = '';
$info  = '';
$shown_otp = '';        // demo mode only
$phone_in_session = $_SESSION['otp_phone'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } elseif ($action === 'send_otp') {
        $phone_raw = trim($_POST['phone'] ?? '');
        $phone     = normalize_phone($phone_raw);
        if (!preg_match('/^\+\d{10,15}$/', $phone)) {
            $error = 'Please enter a valid WhatsApp number.';
        } else {
            // Throttle resends
            $last = db()->prepare("SELECT sent_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
            $last->execute([$phone]);
            $row = $last->fetch();
            if ($row && (time() - strtotime($row['sent_at'])) < OTP_RESEND_GAP) {
                $error = 'Please wait a few seconds before requesting another OTP.';
            } else {
                $code = generate_otp_code();
                $hash = password_hash($code, PASSWORD_DEFAULT);
                $exp  = date('Y-m-d H:i:s', time() + OTP_TTL_SECS);
                db()->prepare("INSERT INTO otps (whatsapp, code_hash, expires_at) VALUES (?,?,?)")
                   ->execute([$phone, $hash, $exp]);
                $r = send_otp_message($phone, $code);
                $_SESSION['otp_phone'] = $phone;
                $phone_in_session = $phone;
                $step = 'otp';
                $info = 'OTP sent to ' . $phone . '. It is valid for 5 minutes.';
                if (OTP_MODE === 'demo') {
                    $shown_otp = $code; // for testing only
                }
            }
        }
    } elseif ($action === 'verify_otp') {
        $phone = $_SESSION['otp_phone'] ?? '';
        $entered = preg_replace('/\D/','', $_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if (!$phone) {
            $error = 'Please request an OTP first.';
            $step = 'phone';
        } elseif (strlen($entered) !== 6) {
            $error = 'Enter the 6-digit OTP.';
            $step = 'otp';
            $phone_in_session = $phone;
        } else {
            $st = db()->prepare("SELECT * FROM otps WHERE whatsapp = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
            $st->execute([$phone]);
            $otp = $st->fetch();
            if (!$otp) {
                $error = 'No active OTP. Please request a new one.';
                $step = 'phone';
            } elseif (strtotime($otp['expires_at']) < time()) {
                $error = 'OTP expired. Please request a new one.';
                $step = 'phone';
            } elseif ((int)$otp['attempts'] >= OTP_MAX_TRY) {
                $error = 'Too many wrong attempts. Please request a new OTP.';
                $step = 'phone';
            } elseif (!password_verify($entered, $otp['code_hash'])) {
                db()->prepare('UPDATE otps SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
                $error = 'Incorrect OTP. ' . (OTP_MAX_TRY - (int)$otp['attempts'] - 1) . ' attempts left.';
                $step = 'otp';
                $phone_in_session = $phone;
            } else {
                db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$otp['id']]);
                // Find or create parent
                $p = db()->prepare('SELECT * FROM parents WHERE whatsapp = ?');
                $p->execute([$phone]);
                $parent = $p->fetch();
                if (!$parent) {
                    db()->prepare('INSERT INTO parents (whatsapp, name) VALUES (?,?)')
                        ->execute([$phone, $name ?: null]);
                    $pid = (int) db()->lastInsertId();
                } else {
                    $pid = (int) $parent['id'];
                    if ($name && empty($parent['name'])) {
                        db()->prepare('UPDATE parents SET name = ? WHERE id = ?')->execute([$name, $pid]);
                    }
                }
                db()->prepare("UPDATE parents SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pid]);
                // Welcome bonus — 100 free credits, idempotent (only granted once)
                require_once __DIR__ . '/includes/wallet.php';
                wallet_grant_signup_credits_if_new($pid);

                // Partner attribution — first-touch wins (silent no-op if no ?ref captured)
                if (file_exists(__DIR__ . '/includes/partner_capture.php')) {
                    require_once __DIR__ . '/includes/partner_capture.php';
                    partner_capture_attribute_session_parent($pid);
                }

                $_SESSION['parent_id'] = $pid;
                unset($_SESSION['otp_phone']);

                // Issue 60-day persistent-login cookie (default ON, can opt out)
                $remember = !isset($_POST['remember']) || $_POST['remember'] === '1';
                if ($remember) set_remember_cookie($pid);

                // Redirect to ?next=… if it points back into our site, else dashboard
                $next = $_GET['next'] ?? $_POST['next'] ?? '/dashboard.php';
                if (!preg_match('#^/[^/]#', $next)) $next = '/dashboard.php';
                header('Location: ' . $next);
                exit;
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-8">
  <h1 class="text-2xl font-bold mb-1">Parent Login</h1>
  <p class="text-sm text-slate-500 mb-6">Login with your WhatsApp number. We&rsquo;ll send a 6-digit OTP.</p>

  <?php if ($error): ?>
    <div class="bg-rose-50 text-rose-800 border border-rose-200 rounded-lg px-3 py-2 text-sm mb-4"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($info): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-lg px-3 py-2 text-sm mb-4"><?= e($info) ?></div>
  <?php endif; ?>
  <?php if ($shown_otp): ?>
    <div class="bg-amber-50 text-amber-800 border border-amber-200 rounded-lg px-3 py-2 text-sm mb-4">
      <strong>Demo mode</strong>: your OTP is <span class="font-mono text-lg"><?= e($shown_otp) ?></span>. (Switch <code>OTP_MODE</code> in <code>config.php</code> to send real WhatsApp/SMS.)
    </div>
  <?php endif; ?>

  <?php if ($step === 'phone'): ?>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="send_otp">
      <div>
        <label class="block text-sm font-medium mb-1">WhatsApp number</label>
        <div class="flex">
          <span class="inline-flex items-center px-3 bg-slate-100 border border-r-0 border-slate-300 rounded-l-lg text-slate-600 text-sm">+91</span>
          <input type="tel" name="phone" required pattern="[0-9 +\-]{10,15}" inputmode="numeric"
                 placeholder="98xxxxxxxx"
                 class="w-full border border-slate-300 rounded-r-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
        </div>
        <p class="text-xs text-slate-500 mt-1">For Indian numbers you may type just the 10 digits.</p>
      </div>
      <button class="w-full brand-grad text-white font-semibold py-2.5 rounded-lg hover:opacity-90">Send OTP</button>
    </form>
  <?php else: ?>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="verify_otp">
      <input type="hidden" name="next" value="<?= e($_GET['next'] ?? '/dashboard.php') ?>">
      <p class="text-sm">Number: <strong><?= e($phone_in_session) ?></strong>
        &middot; <a href="/login.php" class="text-indigo-600 hover:underline">change</a></p>
      <div>
        <label class="block text-sm font-medium mb-1">6-digit OTP</label>
        <input type="text" name="code" required maxlength="6" pattern="\d{6}" inputmode="numeric"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-2xl tracking-[0.5em] text-center focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Your name <span class="text-slate-400 font-normal">(first time only)</span></label>
        <input type="text" name="name" maxlength="100"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
      </div>
      <label class="flex items-center gap-2 text-sm cursor-pointer">
        <input type="checkbox" name="remember" value="1" checked class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
        <span>Keep me signed in for 60 days on this device</span>
      </label>
      <button class="w-full brand-grad text-white font-semibold py-2.5 rounded-lg hover:opacity-90">Verify &amp; continue</button>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
