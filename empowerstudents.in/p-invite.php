<?php
/**
 * p-invite.php — Parent invite magic-link landing page  (fresh-v12)
 *
 * URL: /p-invite.php?token=XXXXXXXX
 *
 * - Validates the invite token (pending + not expired)
 * - Shows ₹2,000 credit banner
 * - Pre-fills parent name + WhatsApp from invite row
 * - OTP verify → calls p-api.php?action=claim_invite → wallet credit
 * - Redirects to /dashboard.php?invited=1
 *
 * PHP 7.4 / GoDaddy shared hosting / SQLite
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();

// Ensure parent_invites table exists (idempotent)
try {
    db()->exec("CREATE TABLE IF NOT EXISTS parent_invites (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        partner_id        INTEGER NOT NULL,
        parent_name       TEXT NOT NULL,
        whatsapp_clean    TEXT NOT NULL,
        invite_token      TEXT UNIQUE NOT NULL,
        credit_amount     REAL DEFAULT 2000,
        status            TEXT DEFAULT 'pending',
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by        TEXT DEFAULT 'admin',
        claimed_at        DATETIME,
        claimed_parent_id INTEGER,
        expires_at        DATETIME
    )");
} catch (Throwable $_) {}

$token = trim((string)($_GET['token'] ?? ''));
$invite = null;
$invite_error = '';

if ($token !== '') {
    try {
        $st = db()->prepare("SELECT * FROM parent_invites WHERE invite_token = ?");
        $st->execute([$token]);
        $invite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $_) {}
}

if (!$invite) {
    $invite_error = 'Invalid invite link. Please contact your clinic for a new one.';
} elseif ($invite['status'] === 'claimed') {
    $invite_error = 'This invite has already been used.';
} elseif ($invite['status'] !== 'pending') {
    $invite_error = 'This invite is no longer active.';
} elseif (strtotime($invite['expires_at']) < time()) {
    // Auto-mark expired
    try { db()->prepare("UPDATE parent_invites SET status='expired' WHERE invite_token=?")->execute([$token]); } catch (Throwable $_) {}
    $invite_error = 'This invite link has expired. Please ask your clinic for a new one.';
}

// Prefill values
$prefill_name  = $invite ? $invite['parent_name'] : '';
$prefill_phone = $invite ? $invite['whatsapp_clean'] : '';
if (strlen($prefill_phone) === 12 && substr($prefill_phone, 0, 2) === '91') {
    $prefill_phone = substr($prefill_phone, 2);   // show as 10-digit
}

$credit_amount = $invite ? (float)$invite['credit_amount'] : 2000;

// Partner name for banner
$partner_name = '';
if ($invite && !empty($invite['partner_id'])) {
    try {
        $pst = db()->prepare("SELECT name FROM partners WHERE id = ?");
        $pst->execute([$invite['partner_id']]);
        $partner_name = (string)($pst->fetchColumn() ?: '');
    } catch (Throwable $_) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Gift Invite — EmpowerStudents</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  @keyframes fadeup { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
  .fade-up { animation: fadeup 0.4s ease-out; }
</style>
</head>
<body class="bg-gradient-to-br from-emerald-50 to-teal-50 min-h-screen flex items-center justify-center p-4">

<div class="max-w-md w-full space-y-4 fade-up">

  <!-- Logo -->
  <div class="text-center">
    <a href="/" class="inline-flex items-center gap-2">
      <div class="w-10 h-10 bg-emerald-600 rounded-xl flex items-center justify-center text-white font-bold text-xl">E</div>
      <span class="font-bold text-slate-800 text-lg">EmpowerStudents.in</span>
    </a>
    <p class="text-xs text-slate-500 mt-1">Child Development &amp; Assessment</p>
  </div>

  <?php if ($invite_error): ?>
  <!-- Error state -->
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-rose-200 text-center">
    <div class="text-4xl mb-3">⚠️</div>
    <h2 class="font-bold text-lg text-slate-900 mb-2">Link not valid</h2>
    <p class="text-sm text-slate-600 mb-4"><?= htmlspecialchars($invite_error) ?></p>
    <a href="/" class="inline-block px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl">← Go to homepage</a>
  </div>

  <?php else: ?>
  <!-- ₹2000 credit banner -->
  <div class="bg-gradient-to-br from-amber-400 to-orange-400 rounded-2xl p-5 text-center text-white shadow-lg">
    <div class="text-sm font-semibold opacity-90 mb-1">
      🎁 <?= $partner_name ? htmlspecialchars($partner_name) . ' has sent you a gift!' : 'Special gift for you!' ?>
    </div>
    <div class="text-5xl font-black">₹<?= number_format($credit_amount, 0) ?></div>
    <div class="text-sm opacity-90 mt-1">Added to your wallet on signup</div>
    <div class="text-xs opacity-75 mt-2">Use it for child evaluations &amp; courses · Expires <?= date('d M Y', strtotime($invite['expires_at'])) ?></div>
  </div>

  <!-- Signup card -->
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
    <h2 class="font-bold text-xl text-slate-900 mb-1">Claim your gift</h2>
    <p class="text-sm text-slate-500 mb-4">Verify your WhatsApp number to create your account and receive the credit.</p>

    <div id="msgBox" class="hidden text-sm rounded-lg p-3 mb-3"></div>

    <!-- Step 1: Name + phone -->
    <div id="step1">
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1 uppercase tracking-wide">Your Name</label>
          <input id="iName" type="text" placeholder="Priya Sharma"
                 value="<?= htmlspecialchars($prefill_name) ?>"
                 class="w-full p-3 border <?= $prefill_name ? 'border-emerald-300 bg-emerald-50' : 'border-slate-300' ?> rounded-xl text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1 uppercase tracking-wide">WhatsApp Number</label>
          <div class="flex">
            <span class="px-3 py-3 bg-slate-100 border border-r-0 border-slate-300 rounded-l-xl text-sm text-slate-600 whitespace-nowrap">🇮🇳 +91</span>
            <input id="iPhone" type="tel" placeholder="9XXXXXXXXX" maxlength="10"
                   value="<?= htmlspecialchars($prefill_phone) ?>"
                   class="flex-1 p-3 border <?= $prefill_phone ? 'border-emerald-300 bg-emerald-50' : 'border-slate-300' ?> rounded-r-xl text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
          </div>
          <p class="text-xs text-slate-400 mt-1">OTP will arrive on WhatsApp</p>
        </div>
        <input type="hidden" id="iToken" value="<?= htmlspecialchars($token) ?>">
        <button type="button" id="btnSendOtp" onclick="sendOtp()"
                class="w-full py-3 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold rounded-xl transition text-sm">
          Get OTP on WhatsApp →
        </button>
      </div>
    </div>

    <!-- Step 2: OTP -->
    <div id="step2" class="hidden">
      <p class="text-sm text-slate-600 mb-3 text-center">OTP sent to <strong id="phoneDisplay"></strong></p>
      <div class="space-y-3">
        <input id="iOtp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
               placeholder="6-digit OTP" autocomplete="one-time-code"
               class="w-full p-4 border-2 border-slate-300 rounded-xl text-center text-2xl font-bold tracking-widest focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
        <button type="button" id="btnVerify" onclick="verifyOtp()"
                class="w-full py-3 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold rounded-xl transition text-sm">
          ✓ Verify &amp; Claim ₹<?= number_format($credit_amount, 0) ?> →
        </button>
        <div class="flex justify-between text-xs">
          <button type="button" onclick="resetStep()" class="text-slate-400 hover:text-slate-600 underline">← Change number</button>
          <button type="button" id="btnResend" onclick="resendOtp()" disabled class="text-emerald-700 hover:text-emerald-900 underline" id="btnResend">
            <span id="resendTxt">Resend OTP</span>
          </button>
        </div>
      </div>
    </div>

    <p class="text-center text-xs text-slate-400 mt-4">🔒 100% private · DPDP Act 2023 compliant</p>
  </div>
  <?php endif; ?>

</div>

<script>
(function(){
  const TOKEN = <?= json_encode($token) ?>;
  let resendTimer = null;

  function showMsg(text, type) {
    var el = document.getElementById('msgBox');
    el.textContent = text;
    el.className = 'text-sm rounded-lg p-3 mb-3 ' +
      (type === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
                     : 'bg-rose-50 border border-rose-200 text-rose-800');
    el.classList.remove('hidden');
  }
  function hideMsg() { document.getElementById('msgBox').classList.add('hidden'); }

  function startResend(secs) {
    var btn = document.getElementById('btnResend');
    var txt = document.getElementById('resendTxt');
    btn.disabled = true;
    var left = secs;
    txt.textContent = 'Resend in ' + left + 's';
    if (resendTimer) clearInterval(resendTimer);
    resendTimer = setInterval(function(){
      left--;
      if (left <= 0) {
        clearInterval(resendTimer);
        btn.disabled = false;
        txt.textContent = 'Resend OTP';
      } else {
        txt.textContent = 'Resend in ' + left + 's';
      }
    }, 1000);
  }

  async function api(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    var r = await fetch('/p-api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  window.sendOtp = async function() {
    hideMsg();
    var name  = document.getElementById('iName').value.trim();
    var phone = document.getElementById('iPhone').value.trim().replace(/\D/g,'');
    if (!name) return showMsg('Please enter your name.', 'error');
    if (phone.length !== 10) return showMsg('Please enter a valid 10-digit number.', 'error');

    var btn = document.getElementById('btnSendOtp');
    btn.disabled = true; btn.textContent = 'Sending…';

    try {
      var r = await api('send_otp', { name: name, phone: '+91' + phone, ref_code: '' });
      if (!r.ok) {
        showMsg(r.error || 'Could not send OTP.', 'error');
        btn.disabled = false; btn.textContent = 'Get OTP on WhatsApp →';
        return;
      }
      document.getElementById('phoneDisplay').textContent = '+91 ' + phone;
      document.getElementById('step1').classList.add('hidden');
      document.getElementById('step2').classList.remove('hidden');
      startResend(30);
      if (r.demo_otp) showMsg('DEMO MODE — OTP: ' + r.demo_otp, 'ok');
    } catch(e) {
      showMsg('Network error. Try again.', 'error');
      btn.disabled = false; btn.textContent = 'Get OTP on WhatsApp →';
    }
  };

  window.verifyOtp = async function() {
    hideMsg();
    var otp = document.getElementById('iOtp').value.trim();
    if (otp.length < 4) return showMsg('Enter the OTP.', 'error');

    var btn = document.getElementById('btnVerify');
    btn.disabled = true; btn.textContent = 'Verifying…';

    try {
      // Step 1: verify OTP (creates/logs in parent)
      var rv = await api('verify_otp', { code: otp, name: document.getElementById('iName').value.trim() });
      if (!rv.ok) {
        showMsg(rv.error || 'Incorrect OTP.', 'error');
        btn.disabled = false; btn.textContent = '✓ Verify & Claim →';
        return;
      }

      // Step 2: claim the invite + credit wallet
      btn.textContent = 'Claiming credit…';
      var rc = await api('claim_invite', { invite_token: TOKEN });
      if (!rc.ok) {
        showMsg(rc.error || 'Could not claim invite.', 'error');
        btn.disabled = false; btn.textContent = '✓ Verify & Claim →';
        return;
      }

      // Success — redirect
      btn.textContent = '✓ ₹' + Math.round(rc.credit) + ' added! Redirecting…';
      showMsg('🎉 ₹' + Math.round(rc.credit) + ' added to your wallet!', 'ok');
      setTimeout(function(){ window.location.href = rc.redirect || '/dashboard.php'; }, 1200);

    } catch(e) {
      showMsg('Network error. Try again.', 'error');
      btn.disabled = false; btn.textContent = '✓ Verify & Claim →';
    }
  };

  window.resendOtp = async function() {
    if (document.getElementById('btnResend').disabled) return;
    hideMsg();
    var name  = document.getElementById('iName').value.trim();
    var phone = document.getElementById('iPhone').value.trim().replace(/\D/g,'');
    try {
      var r = await api('send_otp', { name: name, phone: '+91' + phone, ref_code: '' });
      if (!r.ok) return showMsg(r.error || 'Could not resend.', 'error');
      showMsg('OTP resent.', 'ok');
      startResend(30);
      if (r.demo_otp) showMsg('DEMO OTP: ' + r.demo_otp, 'ok');
    } catch(e) { showMsg('Network error.', 'error'); }
  };

  window.resetStep = function() {
    document.getElementById('step1').classList.remove('hidden');
    document.getElementById('step2').classList.add('hidden');
    document.getElementById('iOtp').value = '';
    document.getElementById('btnSendOtp').disabled = false;
    document.getElementById('btnSendOtp').textContent = 'Get OTP on WhatsApp →';
    hideMsg();
  };

  // Enter key on OTP field
  document.getElementById('iOtp') && document.getElementById('iOtp').addEventListener('keydown', function(e){
    if (e.key === 'Enter') window.verifyOtp();
  });
})();
</script>
</body>
</html>
