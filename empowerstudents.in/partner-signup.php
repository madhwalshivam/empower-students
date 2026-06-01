<?php
/**
 * partner-signup.php — public partner registration page.
 *
 * Open to anyone (pediatricians / schools / coaching institutes / teachers).
 * Two-step flow:
 *   Step 1: pick partner type
 *   Step 2: fill details → create partner row → show their landing URL + QR
 *
 * The created partner gets a unique referral_code; landing page is /p.php?code=X.
 * Status defaults to 'pending' so admin reviews before activation.
 */

// Defensive: turn on errors so we know what's wrong if include fails
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';

// partner_schema.php auto-creates partners table (IIFE on require)
if (file_exists(__DIR__ . '/includes/partner_schema.php')) {
    require_once __DIR__ . '/includes/partner_schema.php';
}

// partner_types.php (added by us) — provides type registry + helpers
if (!file_exists(__DIR__ . '/includes/partner_types.php')) {
    die('Setup incomplete: please upload /includes/partner_types.php first.');
}
require_once __DIR__ . '/includes/partner_types.php';

partner_types_ensure_schema();

// ── Helpers ─────────────────────────────────────────────────
function gen_referral_code(string $base): string {
    $base = strtoupper(preg_replace('/[^A-Za-z]/', '', $base));
    if (strlen($base) < 3) $base = 'PARTNER' . mt_rand(100, 999);
    $base = substr($base, 0, 10);
    // Ensure uniqueness
    $candidate = $base;
    $n = 0;
    while (true) {
        $st = db()->prepare("SELECT id FROM partners WHERE referral_code = ?");
        $st->execute([$candidate]);
        if (!$st->fetchColumn()) return $candidate;
        $n++;
        $candidate = $base . $n;
        if ($n > 99) {
            $candidate = $base . mt_rand(100, 999);
            break;
        }
    }
    return $candidate;
}

// ── State ───────────────────────────────────────────────────
$step    = $_GET['step'] ?? '1';
$error   = '';
$success = null;
$created_code = null;

// Step 2 POST: create partner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } else {
        $type    = (string)($_POST['partner_type'] ?? 'pediatrician');
        if (!array_key_exists($type, partner_types_all())) $type = 'pediatrician';
        $type_cfg = partner_type_config($type);

        $name       = trim((string)($_POST['name'] ?? ''));            // institution name
        $contact    = trim((string)($_POST['contact_name'] ?? ''));    // referrer person
        $role       = trim((string)($_POST['referrer_role'] ?? $type_cfg['referrer_word_en']));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $whatsapp   = trim((string)($_POST['whatsapp'] ?? $phone));
        $email      = trim((string)($_POST['email'] ?? ''));
        $city       = trim((string)($_POST['city'] ?? ''));
        $custom_msg = trim((string)($_POST['custom_message'] ?? ''));

        if ($name === '' || strlen($name) < 2) $error = ($type_cfg['institution_word_en']) . ' name required';
        elseif ($contact === '') $error = 'Your name required';
        elseif ($phone === '' || !preg_match('/^\+?\d{10,15}$/', preg_replace('/\D/', '', $phone))) {
            $error = 'Valid phone number required';
        }

        if (!$error) {
            // Normalize phone
            $clean_phone = preg_replace('/\D/', '', $phone);
            if (strlen($clean_phone) === 10) $clean_phone = '91' . $clean_phone;
            $clean_phone = '+' . $clean_phone;
            $clean_wa = $whatsapp ?: $clean_phone;
            if (preg_match('/^\+?\d{10,15}$/', $clean_wa)) {
                $clean_wa = preg_replace('/\D/', '', $clean_wa);
                if (strlen($clean_wa) === 10) $clean_wa = '91' . $clean_wa;
                $clean_wa = '+' . $clean_wa;
            }

            $code = gen_referral_code($name);

            try {
                // Ensure all optional columns exist (clinic_image_path etc. for backward compat)
                foreach (['clinic_image_path', 'doctor_image_path', 'clinic_address', 'doctor_credentials', 'custom_message'] as $opt_col) {
                    try {
                        $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
                        $col_names = array_column($cols, 'name');
                        if (!in_array($opt_col, $col_names, true)) {
                            @db()->exec("ALTER TABLE partners ADD COLUMN $opt_col TEXT");
                        }
                    } catch (Throwable $_) {}
                }

                $st = db()->prepare("INSERT INTO partners (
                    name, contact_name, phone, whatsapp, email, city,
                    referral_code, revenue_share, status,
                    partner_type, institution_name, referrer_role,
                    custom_message, doctor_credentials
                ) VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?)");
                $st->execute([
                    $name, $contact, $clean_phone, $clean_wa, $email, $city,
                    $code, 0.20, 'pending',
                    $type, $name, $role,
                    $custom_msg, $role,
                ]);
                $created_code = $code;
                $step = 'done';
            } catch (Throwable $e) {
                $error = 'Error creating partner: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Become an EmpowerStudents Partner';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  .heading-fun { font-family: 'Fredoka', system-ui, sans-serif; font-weight: 700; }
  .type-card {
    border: 2px solid #e2e8f0; border-radius: 18px; padding: 22px;
    cursor: pointer; transition: all 0.18s;
    text-align: center;
    background: white;
  }
  .type-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.10); border-color: #f97316; }
  .type-emoji { font-size: 42px; line-height: 1; margin-bottom: 8px; }
  .type-name { font-family: 'Fredoka', system-ui; font-size: 18px; color: #0f172a; margin-bottom: 4px; }
  .type-desc { font-size: 12px; color: #64748b; line-height: 1.4; }

  .input-row label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: block; }
  .input-row input, .input-row textarea {
    width: 100%; padding: 11px 13px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: 14px; margin-bottom: 12px;
    background: #f8fafc;
  }
  .input-row input:focus, .input-row textarea:focus { background: white; border-color: #f97316; outline: none; }
</style>
</head>
<body class="bg-slate-50 min-h-screen">

<header class="bg-white border-b border-slate-200 sticky top-0 z-10">
  <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="/" class="font-bold text-slate-900">← EmpowerStudents</a>
    <span class="text-xs text-slate-500">Partner sign-up</span>
  </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-8">

  <?php if ($step === 'done' && $created_code):
    $landing_url = 'https://empowerstudents.in/p.php?code=' . urlencode($created_code);
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($landing_url);
  ?>

    <div class="bg-emerald-50 border-2 border-emerald-300 rounded-2xl p-6 sm:p-8 text-center">
      <div class="text-5xl mb-3">🎉</div>
      <h1 class="heading-fun text-2xl text-emerald-900 mb-2">You're in!</h1>
      <p class="text-sm text-emerald-800 mb-4">Your partner account is created. Our team will activate it within 24 hours.</p>

      <div class="bg-white rounded-xl p-5 mb-4">
        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Your referral code</div>
        <div class="font-mono text-xl font-bold text-orange-600 mb-3"><?= e($created_code) ?></div>

        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Share this URL with parents</div>
        <div class="bg-slate-100 rounded-lg p-3 break-all font-mono text-xs text-slate-700 mb-3"><?= e($landing_url) ?></div>

        <img src="<?= e($qr_url) ?>" alt="QR code" class="mx-auto rounded-lg border border-slate-200" width="200" height="200">
        <p class="text-xs text-slate-500 mt-2">Print this QR for your reception/notice board</p>
      </div>

      <div class="text-xs text-slate-600 mb-4">
        Once activated, parents who scan or click will see <strong>your branding</strong> on the EmpowerStudents signup page.
        Every paid evaluation gives you <strong>20% commission</strong>.
      </div>

      <a href="/myaccount-auth.php" class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 py-2.5 rounded-lg text-sm">
        Set up your dashboard login →
      </a>
    </div>

  <?php elseif ($step === '2' && isset($_GET['type'])):
    $type_key = (string)$_GET['type'];
    if (!array_key_exists($type_key, partner_types_all())) $type_key = 'pediatrician';
    $type_cfg = partner_type_config($type_key);
  ?>

    <!-- STEP 2: details form -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 shadow-sm">
      <a href="/partner-signup.php" class="text-xs text-slate-500 hover:text-orange-600">← Back</a>

      <div class="flex items-center gap-3 mt-3 mb-4">
        <span class="text-3xl"><?= $type_cfg['badge_emoji'] ?></span>
        <h1 class="heading-fun text-2xl text-slate-900">Sign up as <?= e($type_cfg['label_en']) ?></h1>
      </div>

      <?php if ($error): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg p-3 mb-4"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="partner_type" value="<?= e($type_key) ?>">

        <div class="input-row">
          <label><?= e($type_cfg['institution_word_en']) ?> name *</label>
          <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>"
                 placeholder="e.g. <?= e($type_cfg['institution_word_en']) ?> name" required maxlength="120">
        </div>

        <div class="input-row">
          <label>Your name *</label>
          <input type="text" name="contact_name" value="<?= e($_POST['contact_name'] ?? '') ?>"
                 placeholder="e.g. Dr Anita Sharma" required maxlength="100">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="input-row">
            <label>Your role</label>
            <input type="text" name="referrer_role" value="<?= e($_POST['referrer_role'] ?? $type_cfg['referrer_word_en']) ?>"
                   placeholder="<?= e($type_cfg['referrer_word_en']) ?>" maxlength="60">
          </div>

          <div class="input-row">
            <label>City</label>
            <input type="text" name="city" value="<?= e($_POST['city'] ?? '') ?>"
                   placeholder="Delhi, Mumbai, etc." maxlength="60">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="input-row">
            <label>Phone *</label>
            <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>"
                   placeholder="+91 98XXX XXXXX" required>
          </div>

          <div class="input-row">
            <label>WhatsApp (if different)</label>
            <input type="tel" name="whatsapp" value="<?= e($_POST['whatsapp'] ?? '') ?>"
                   placeholder="+91 98XXX XXXXX">
          </div>
        </div>

        <div class="input-row">
          <label>Email</label>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"
                 placeholder="you@example.com">
        </div>

        <div class="input-row">
          <label>A short message to parents (optional)</label>
          <textarea name="custom_message" rows="3" maxlength="300"
                    placeholder="e.g. 'I recommend this evaluation for any parent concerned about their child\'s development.'"><?= e($_POST['custom_message'] ?? '') ?></textarea>
        </div>

        <button class="w-full bg-gradient-to-br from-orange-500 to-red-600 text-white font-bold py-3 rounded-xl mt-3">
          Create my partner account →
        </button>

        <p class="text-xs text-slate-500 text-center mt-3">
          By signing up you agree to our standard <a href="/about.php" class="underline">partner terms</a> (20% commission, payable monthly).
        </p>
      </form>
    </div>

  <?php else: /* step 1: pick type */ ?>

    <div class="text-center mb-8">
      <h1 class="heading-fun text-3xl text-slate-900 mb-2">Become a partner</h1>
      <p class="text-sm text-slate-600 max-w-xl mx-auto">
        Refer parents and families to EmpowerStudents. Get a personalised landing page,
        QR code for your office/school, and 20% commission on every paid evaluation.
      </p>
    </div>

    <h2 class="heading-fun text-lg text-slate-700 text-center mb-4">What kind of partner are you?</h2>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <?php foreach (partner_types_all() as $key => $cfg): ?>
        <a href="/partner-signup.php?step=2&type=<?= e($key) ?>" class="type-card block">
          <div class="type-emoji"><?= $cfg['badge_emoji'] ?></div>
          <div class="type-name"><?= e($cfg['label_en']) ?></div>
          <div class="type-desc">
            <?php
              $desc = [
                'pediatrician' => 'Clinic-based child specialist',
                'school'       => 'Primary / secondary school',
                'coaching'     => 'Tuition or coaching institute',
                'teacher'      => 'Individual teacher',
              ];
              echo e($desc[$key] ?? '');
            ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="mt-8 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm">
      <div class="font-bold text-amber-900 mb-1">How it works</div>
      <ol class="text-amber-900 list-decimal list-inside space-y-1">
        <li>Pick your type and fill in your details (2 minutes)</li>
        <li>Get a unique referral URL and QR code</li>
        <li>Share with parents — anyone who signs up via your link counts as yours</li>
        <li>Earn 20% commission on every paid evaluation/package they buy</li>
        <li>Track everything on your dashboard at <code>/myaccount.php</code></li>
      </ol>
    </div>

  <?php endif; ?>

</main>
</body>
</html>
