<?php
/**
 * pediatrician-auth.php
 *
 * Handles pediatrician (partner) authentication:
 *
 *   GET /pediatrician-auth.php                     → login form
 *   POST action=send_otp     {phone}                → sends WhatsApp OTP
 *   POST action=verify_otp   {phone, code}          → returns ok + first_time flag
 *   POST action=set_password {phone, code, pwd}    → sets password + creates session
 *   POST action=login        {phone, pwd, remember} → password login + session
 *   POST action=request_reset{phone}                → forgot-password: re-uses send_otp
 *   POST action=logout                              → clears session cookie
 *
 * On successful auth, sets cookie ped_sess=<token> + DB row in partner_sessions.
 * The /pediatrician.php page checks this cookie.
 *
 * Returns JSON for all POSTs. GET renders HTML.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/sms.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ─── Ensure schema ────────────────────────────────────────
function _ped_ensure_schema(): void {
    // Add cols to partners
    foreach ([
        'password_hash'  => 'TEXT',
        'password_set_at' => 'TEXT',
        'last_login_at'  => 'TEXT',
    ] as $col => $type) {
        try {
            $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
            $names = array_column($cols, 'name');
            if (!in_array($col, $names, true)) {
                @db()->exec("ALTER TABLE partners ADD COLUMN $col $type");
            }
        } catch (Throwable $_) {}
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS partner_sessions (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            partner_id    INTEGER NOT NULL,
            token         TEXT NOT NULL UNIQUE,
            created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at    TEXT NOT NULL,
            last_used_at  TEXT,
            user_agent    TEXT,
            ip_addr       TEXT,
            revoked_at    TEXT
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partner_sessions_token ON partner_sessions(token)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_partner_sessions_partner ON partner_sessions(partner_id)");
    } catch (Throwable $_) {}
}
_ped_ensure_schema();

// ─── Helpers ────────────────────────────────────────
function _ped_normalize_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10) return '+91' . $d;
    if (strlen($d) > 10 && strpos($d, '91') === 0) return '+' . $d;
    return $d ? '+' . $d : '';
}

function _ped_find_partner_by_phone(string $phone): ?array {
    // Match either phone or whatsapp column on partners
    $st = db()->prepare("SELECT * FROM partners
                          WHERE (phone = ? OR whatsapp = ?) AND status = 'active'
                          LIMIT 1");
    $st->execute([$phone, $phone]);
    $row = $st->fetch();
    return $row ?: null;
}

function _ped_send_otp(string $phone): array {
    // Rate-limit: 30s between OTPs
    $st = db()->prepare("SELECT sent_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $last = $st->fetch();
    if ($last && (time() - strtotime($last['sent_at'])) < 30) {
        return ['ok' => false, 'error' => 'Please wait a few seconds before requesting another OTP.'];
    }

    $code = function_exists('generate_otp_code') ? generate_otp_code() : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $exp  = date('Y-m-d H:i:s', time() + 300);

    db()->prepare("INSERT INTO otps (whatsapp, code_hash, expires_at) VALUES (?,?,?)")
       ->execute([$phone, $hash, $exp]);

    if (function_exists('send_otp_message')) send_otp_message($phone, $code);

    $resp = ['ok' => true];
    if (defined('OTP_MODE') && OTP_MODE === 'demo') $resp['demo_otp'] = $code;
    return $resp;
}

function _ped_verify_otp(string $phone, string $code): bool {
    $st = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch();
    if (!$row) return false;
    if (!empty($row['used_at'])) return false;
    if (strtotime($row['expires_at']) < time()) return false;
    if (!password_verify($code, $row['code_hash'])) return false;
    db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$row['id']]);
    return true;
}

function _ped_create_session(int $partner_id, bool $remember = true): string {
    $token = bin2hex(random_bytes(24));
    $days  = $remember ? 60 : 1;
    $exp   = date('Y-m-d H:i:s', time() + 86400 * $days);
    db()->prepare("INSERT INTO partner_sessions (partner_id, token, expires_at, user_agent, ip_addr)
                   VALUES (?, ?, ?, ?, ?)")
       ->execute([
           $partner_id, $token, $exp,
           substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
           (string)($_SERVER['REMOTE_ADDR'] ?? ''),
       ]);
    setcookie('ped_sess', $token, [
        'expires' => time() + 86400 * $days,
        'path' => '/',
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
    ]);
    db()->prepare("UPDATE partners SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$partner_id]);
    return $token;
}

function _ped_destroy_session(): void {
    $tok = $_COOKIE['ped_sess'] ?? '';
    if ($tok) {
        db()->prepare("UPDATE partner_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE token = ?")
           ->execute([$tok]);
    }
    setcookie('ped_sess', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

// Used by /pediatrician.php to check if request is authed
function ped_current_partner(): ?array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $tok = $_COOKIE['ped_sess'] ?? '';
    if (!$tok) return null;
    $st = db()->prepare("SELECT p.* FROM partner_sessions s
                          JOIN partners p ON p.id = s.partner_id
                          WHERE s.token = ? AND s.revoked_at IS NULL
                            AND s.expires_at > datetime('now')
                            AND p.status = 'active'
                          LIMIT 1");
    $st->execute([$tok]);
    $row = $st->fetch();
    if ($row) {
        // touch session
        db()->prepare("UPDATE partner_sessions SET last_used_at = CURRENT_TIMESTAMP WHERE token = ?")
           ->execute([$tok]);
        $cache = $row;
        return $row;
    }
    $cache = null;
    return null;
}

// ─── POST handlers ────────────────────────────────────────
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($is_post) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if ($action === 'send_otp' || $action === 'request_reset') {
        $phone = _ped_normalize_phone((string)($_POST['phone'] ?? ''));
        if (!preg_match('/^\+\d{10,15}$/', $phone)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid phone number.']);
            exit;
        }
        $p = _ped_find_partner_by_phone($phone);
        if (!$p) {
            // Don't reveal whether phone exists — just say "OTP sent if registered"
            // but for first-pass UX, tell them clearly so they ask admin to register them
            echo json_encode(['ok' => false, 'error' => "This number isn't registered as a pediatrician partner. Please ask Empower Students to add you first."]);
            exit;
        }
        $r = _ped_send_otp($phone);
        $r['phone'] = $phone;
        $r['first_time'] = empty($p['password_hash']);
        echo json_encode($r);
        exit;
    }

    if ($action === 'verify_otp') {
        $phone = _ped_normalize_phone((string)($_POST['phone'] ?? ''));
        $code  = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (!_ped_verify_otp($phone, $code)) {
            echo json_encode(['ok' => false, 'error' => 'Wrong or expired OTP.']);
            exit;
        }
        $p = _ped_find_partner_by_phone($phone);
        if (!$p) { echo json_encode(['ok' => false, 'error' => 'Partner not found.']); exit; }

        // Mark phone verified in session so set_password trusts it
        $_SESSION['ped_otp_verified_phone'] = $phone;
        $_SESSION['ped_otp_verified_at']    = time();

        echo json_encode([
            'ok' => true,
            'first_time' => empty($p['password_hash']),
            'partner_name' => (string)($p['name'] ?? ''),
            'doctor_name'  => (string)($p['contact_name'] ?? ''),
        ]);
        exit;
    }

    if ($action === 'set_password') {
        $phone = _ped_normalize_phone((string)($_POST['phone'] ?? ''));
        $pwd   = (string)($_POST['password'] ?? '');

        // Must have verified OTP within last 10 minutes in this session
        $verified = ($_SESSION['ped_otp_verified_phone'] ?? '') === $phone
                 && (time() - (int)($_SESSION['ped_otp_verified_at'] ?? 0)) < 600;
        if (!$verified) {
            echo json_encode(['ok' => false, 'error' => 'OTP verification expired. Please request a new OTP.']);
            exit;
        }
        if (strlen($pwd) < 6) {
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 6 characters.']);
            exit;
        }

        $p = _ped_find_partner_by_phone($phone);
        if (!$p) { echo json_encode(['ok' => false, 'error' => 'Partner not found.']); exit; }

        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        db()->prepare("UPDATE partners SET password_hash = ?, password_set_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$hash, (int)$p['id']]);

        // Clear OTP-verified flag
        unset($_SESSION['ped_otp_verified_phone'], $_SESSION['ped_otp_verified_at']);

        _ped_create_session((int)$p['id'], true);
        echo json_encode([
            'ok' => true,
            'redirect' => '/pediatrician.php?code=' . urlencode($p['referral_code']),
        ]);
        exit;
    }

    if ($action === 'login') {
        $phone = _ped_normalize_phone((string)($_POST['phone'] ?? ''));
        $pwd   = (string)($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        $p = _ped_find_partner_by_phone($phone);
        if (!$p || empty($p['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'No account found. If this is your first time, use the OTP option below.']);
            exit;
        }
        if (!password_verify($pwd, $p['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Wrong password.']);
            exit;
        }

        _ped_create_session((int)$p['id'], $remember);
        echo json_encode([
            'ok' => true,
            'redirect' => '/pediatrician.php?code=' . urlencode($p['referral_code']),
        ]);
        exit;
    }

    if ($action === 'logout') {
        _ped_destroy_session();
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ─── GET — render login form ────────────────────────────────────────
// Only when this script is requested directly (not required by pediatrician.php)
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'pediatrician-auth.php') {
    return;
}

$already_logged_in = ped_current_partner();
if ($already_logged_in) {
    header('Location: /pediatrician.php?code=' . urlencode($already_logged_in['referral_code']));
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pediatrician Login · EmpowerStudents</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  .form-step { display: none; }
  .form-step.active { display: block; }
</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">

<div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8">
  <div class="text-center mb-6">
    <div class="w-12 h-12 mx-auto bg-emerald-600 rounded-lg flex items-center justify-center text-white font-bold text-xl mb-3">E</div>
    <h1 class="text-xl font-bold text-slate-900">Pediatrician Dashboard</h1>
    <p class="text-sm text-slate-500 mt-1">EmpowerStudents.in · Partner Login</p>
  </div>

  <!-- STEP 1: Phone -->
  <div id="step-phone" class="form-step active">
    <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide">WhatsApp number</label>
    <input id="inPhone" type="tel" placeholder="+91 9876543210" autocomplete="tel"
           class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none mb-3">

    <div id="passwordRow" class="hidden mb-3">
      <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide">Password</label>
      <input id="inPwd" type="password" placeholder="Your password" autocomplete="current-password"
             class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
      <label class="flex items-center gap-2 mt-2 text-xs text-slate-600">
        <input id="inRemember" type="checkbox" checked>
        <span>Remember this device for 60 days</span>
      </label>
    </div>

    <button id="btnLogin" type="button"
            class="w-full py-3 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 text-white text-base font-bold rounded-xl shadow transition">
      Continue
    </button>

    <div id="otpRow" class="hidden mt-4 text-center">
      <button id="btnUseOtp" type="button" class="text-sm text-slate-600 hover:text-emerald-700 underline">
        Forgot password? Login with OTP instead
      </button>
    </div>
  </div>

  <!-- STEP 2: OTP -->
  <div id="step-otp" class="form-step">
    <p class="text-sm text-slate-700 mb-3 text-center">
      OTP sent to <span id="otpPhoneDisplay" class="font-bold"></span>
    </p>
    <input id="inOtp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
           placeholder="123456"
           class="w-full p-4 border-2 border-slate-300 rounded-lg text-center text-2xl font-bold tracking-widest focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none mb-3">
    <button id="btnVerifyOtp" type="button"
            class="w-full py-3 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 text-white text-base font-bold rounded-xl shadow transition mb-2">
      Verify
    </button>
    <button id="btnBackToPhone" type="button" class="block w-full text-sm text-slate-500 hover:text-slate-700 underline">
      ← Use a different number
    </button>
  </div>

  <!-- STEP 3: Set password (first time OR after reset) -->
  <div id="step-setpwd" class="form-step">
    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 mb-4 text-sm text-emerald-900 text-center">
      ✓ Phone verified. <strong>Set a password</strong> to log in faster next time.
    </div>
    <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide">New password</label>
    <input id="inNewPwd" type="password" placeholder="6+ characters" autocomplete="new-password"
           class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none mb-3">
    <button id="btnSetPwd" type="button"
            class="w-full py-3 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 text-white text-base font-bold rounded-xl shadow transition">
      Save & enter dashboard
    </button>
  </div>

  <div id="errMsg" class="hidden mt-4 p-3 bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg"></div>
  <div id="okMsg"  class="hidden mt-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-lg"></div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  let phoneState = ''; // remembered between steps

  function showErr(t) { $('errMsg').textContent = t; $('errMsg').classList.remove('hidden'); $('okMsg').classList.add('hidden'); }
  function showOk(t)  { $('okMsg').textContent  = t; $('okMsg').classList.remove('hidden');  $('errMsg').classList.add('hidden'); }
  function clearMsg() { $('errMsg').classList.add('hidden'); $('okMsg').classList.add('hidden'); }
  function setStep(s) {
    ['phone', 'otp', 'setpwd'].forEach(x => $('step-' + x).classList.toggle('active', x === s));
  }
  async function api(action, data) {
    const fd = new FormData(); fd.append('action', action);
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('/pediatrician-auth.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  // ── Continue: if phone has account → ask password; if not → send OTP
  let knownHasPassword = null;  // null = unknown yet

  $('btnLogin').addEventListener('click', async () => {
    clearMsg();
    const phone = $('inPhone').value.trim();
    if (!phone) return showErr('Please enter your WhatsApp number.');

    // If we haven't asked for password yet, first call send_otp to find out
    // if this partner exists AND whether they have a password set.
    if (knownHasPassword === null) {
      const btn = $('btnLogin'); btn.disabled = true; btn.textContent = 'Checking…';
      const r = await api('send_otp', { phone });
      btn.disabled = false; btn.textContent = 'Continue';
      if (!r.ok) { return showErr(r.error || 'Could not continue.'); }
      phoneState = r.phone || phone;
      // If first time → straight to OTP step (no password yet)
      if (r.first_time) {
        $('otpPhoneDisplay').textContent = phoneState;
        if (r.demo_otp) showOk('DEMO OTP: ' + r.demo_otp);
        setStep('otp');
        return;
      }
      // Has password: don't send OTP unnecessarily — show password row
      // (Actually we already sent one. That's OK, parent can fall back to OTP via "Forgot password")
      knownHasPassword = true;
      $('passwordRow').classList.remove('hidden');
      $('otpRow').classList.remove('hidden');
      $('btnLogin').textContent = 'Login';
      showOk('Enter your password. (OTP also sent if you forgot it.)');
      if (r.demo_otp) showOk('DEMO OTP: ' + r.demo_otp + ' — or use password.');
      return;
    }

    // Second call: try password login
    const pwd = $('inPwd').value;
    if (!pwd) return showErr('Enter your password.');
    const btn = $('btnLogin'); btn.disabled = true; btn.textContent = 'Logging in…';
    const r = await api('login', { phone: phoneState, password: pwd, remember: $('inRemember').checked ? '1' : '' });
    btn.disabled = false; btn.textContent = 'Login';
    if (!r.ok) return showErr(r.error || 'Login failed.');
    window.location.href = r.redirect || '/pediatrician.php';
  });

  // ── OTP fallback
  $('btnUseOtp').addEventListener('click', () => {
    clearMsg();
    $('otpPhoneDisplay').textContent = phoneState;
    setStep('otp');
  });

  $('btnVerifyOtp').addEventListener('click', async () => {
    clearMsg();
    const code = $('inOtp').value.trim();
    if (code.length < 4) return showErr('Enter the OTP.');
    const btn = $('btnVerifyOtp'); btn.disabled = true; btn.textContent = 'Verifying…';
    const r = await api('verify_otp', { phone: phoneState, code });
    btn.disabled = false; btn.textContent = 'Verify';
    if (!r.ok) return showErr(r.error || 'Wrong OTP.');
    // If first time → set password step. Else → already password set, the OTP-verify here doesn't auto-login;
    // we treat it as a password reset opportunity.
    setStep('setpwd');
    showOk(r.first_time ? 'Welcome ' + (r.doctor_name || '') + '! Set a password to continue.'
                        : 'OTP verified. Set a new password.');
  });

  $('btnBackToPhone').addEventListener('click', () => {
    knownHasPassword = null;
    $('passwordRow').classList.add('hidden');
    $('otpRow').classList.add('hidden');
    $('btnLogin').textContent = 'Continue';
    clearMsg();
    setStep('phone');
  });

  $('btnSetPwd').addEventListener('click', async () => {
    clearMsg();
    const pwd = $('inNewPwd').value;
    if (pwd.length < 6) return showErr('Password must be at least 6 characters.');
    const btn = $('btnSetPwd'); btn.disabled = true; btn.textContent = 'Saving…';
    const r = await api('set_password', { phone: phoneState, password: pwd });
    btn.disabled = false; btn.textContent = 'Save & enter dashboard';
    if (!r.ok) return showErr(r.error || 'Could not save password.');
    window.location.href = r.redirect || '/pediatrician.php';
  });
})();
</script>
</body>
</html>
