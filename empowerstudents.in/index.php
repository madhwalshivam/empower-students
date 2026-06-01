<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sms.php';
if (file_exists(__DIR__ . '/includes/launch_config.php')) {
    require_once __DIR__ . '/includes/launch_config.php';
}

// Partner referral capture
if (file_exists(__DIR__ . '/includes/partner_capture.php')) {
    require_once __DIR__ . '/includes/partner_capture.php';
    @partner_capture_from_url();
}

if (!empty($_SESSION['parent_id'])) {
    header('Location: /dashboard.php');
    exit;
}

try {
    db()->exec("CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_name TEXT NOT NULL, phone TEXT NOT NULL,
        child_age TEXT, concern TEXT, message TEXT,
        source TEXT, status TEXT DEFAULT 'new', notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $_) {}

// ── 2-step OTP login flow ──────────────────────────────────────────
$step = 'phone';
$error = ''; $info  = ''; $shown_otp = '';
$phone_in_session = $_SESSION['otp_phone'] ?? '';
$name_in_session  = $_SESSION['otp_name']  ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    }
    elseif ($action === 'send_otp') {
        $phone_raw = trim($_POST['phone'] ?? '');
        $name      = trim($_POST['name']  ?? '');
        $phone     = normalize_phone($phone_raw);

        if (!preg_match('/^\+\d{10,15}$/', $phone)) {
            $error = 'Please enter a valid WhatsApp number (with country code).';
        } elseif ($name === '' || mb_strlen($name) < 2) {
            $error = 'Please enter your name.';
        } else {
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
                @send_otp_message($phone, $code);

                $_SESSION['otp_phone'] = $phone;
                $_SESSION['otp_name']  = $name;
                $phone_in_session = $phone;
                $name_in_session  = $name;
                $step = 'otp';
                $info = 'OTP sent on WhatsApp. Valid for 5 minutes.';
                if (defined('OTP_MODE') && OTP_MODE === 'demo') $shown_otp = $code;
            }
        }
    }
    elseif ($action === 'verify_otp') {
        $phone   = $_SESSION['otp_phone'] ?? '';
        $name    = $_SESSION['otp_name']  ?? '';
        $entered = preg_replace('/\D/', '', $_POST['code'] ?? '');

        if (!$phone) { $error = 'Please request an OTP first.'; $step = 'phone'; }
        else {
            $ost = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
            $ost->execute([$phone]);
            $orow = $ost->fetch();

            if (!$orow) { $error = 'No OTP found. Request a new one.'; $step = 'phone'; }
            elseif (!empty($orow['used_at'])) { $error = 'OTP already used. Request a new one.'; $step = 'phone'; }
            elseif (strtotime($orow['expires_at']) < time()) { $error = 'OTP expired. Request a new one.'; $step = 'phone'; }
            elseif (!password_verify($entered, $orow['code_hash'])) { $error = 'Wrong OTP. Try again.'; $step = 'otp'; }
            else {
                db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$orow['id']]);

                $pst = db()->prepare("SELECT id, name FROM parents WHERE whatsapp = ? LIMIT 1");
                $pst->execute([$phone]);
                $prow = $pst->fetch();
                if ($prow) {
                    $pid = (int)$prow['id'];
                    if (empty($prow['name']) && $name !== '') {
                        db()->prepare("UPDATE parents SET name = ? WHERE id = ?")->execute([$name, $pid]);
                    }
                } else {
                    db()->prepare("INSERT INTO parents (whatsapp, name, credits) VALUES (?, ?, 0)")
                       ->execute([$phone, $name]);
                    $pid = (int) db()->lastInsertId();
                }

                if (function_exists('partner_attribute_to_parent')) {
                    @partner_attribute_to_parent($pid);
                }

                $_SESSION['parent_id'] = $pid;
                unset($_SESSION['otp_phone'], $_SESSION['otp_name']);
                header('Location: /dashboard.php');
                exit;
            }
        }
    }
}

$page_title = 'EmpowerStudents · Empowering Indian Families';
$page_meta_description = "Free 2-min parenting check. AI-guided parent voice evaluation. Adaptive child evaluation. Clinician-led autism screening. For Indian families.";

// ── Specialists with photo-fallback ────────────────────────────────
$specialists = [];
try {
    $specialists = db()->query("SELECT * FROM specialists WHERE active = 1 ORDER BY order_no ASC LIMIT 8")->fetchAll();
} catch (Throwable $_) {}

/**
 * Resolves a specialist's photo URL. DB may have .jpg but disk has .png.
 * Tries: photo as-is, then .png swap, then .jpg swap. Returns null if none found.
 */
function resolve_specialist_photo(?string $photo): ?string {
    if (!$photo) return null;
    $base = __DIR__ . '/assets/images/';
    if (file_exists($base . $photo)) return '/assets/images/' . $photo;
    // Try swapping extension
    $stem = pathinfo($photo, PATHINFO_FILENAME);
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
        if (file_exists($base . $stem . $ext)) return '/assets/images/' . $stem . $ext;
    }
    return null;
}

require __DIR__ . '/includes/header.php';
?>

<meta name="description" content="<?= e($page_meta_description) ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="https://empowerstudents.in/">

<meta property="og:type" content="website">
<meta property="og:site_name" content="EmpowerStudents.in">
<meta property="og:title" content="<?= e($page_title) ?>">
<meta property="og:description" content="<?= e($page_meta_description) ?>">
<meta property="og:url" content="https://empowerstudents.in/">
<meta property="og:image" content="https://empowerstudents.in/assets/images/hero.jpg">

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  .heading-fun { font-family: 'Fredoka', system-ui, sans-serif; font-weight: 700; letter-spacing: -0.01em; }

  /* HERO --------------------- */
  .es-hero {
    position: relative;
    background:
      radial-gradient(800px 400px at 0% 0%, rgba(255,182,77,0.30), transparent 60%),
      radial-gradient(700px 380px at 100% 100%, rgba(255,107,107,0.18), transparent 60%),
      linear-gradient(135deg, #FFF8EC 0%, #FFEEDB 60%, #FFE3CE 100%);
    border-radius: 28px;
    padding: 32px 24px;
    margin-bottom: 28px;
    overflow: hidden;
  }
  .es-hero::before {
    content: ""; position: absolute; right: -60px; top: -60px;
    width: 180px; height: 180px; border-radius: 50%;
    background: radial-gradient(circle, rgba(251,146,60,0.20), transparent 70%);
    z-index: 0;
  }
  .es-hero-grid {
    position: relative; z-index: 1;
    display: grid; gap: 32px;
    grid-template-columns: 1fr;
    align-items: center;
  }
  @media (min-width: 768px) {
    .es-hero-grid { grid-template-columns: 1.15fr 0.85fr; gap: 44px; }
  }
  .es-eyebrow {
    display: inline-block;
    background: white;
    color: #c2410c;
    font-size: 11px; font-weight: 700;
    padding: 6px 14px;
    border-radius: 999px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-transform: uppercase; letter-spacing: 0.08em;
  }
  .es-hero h1 {
    font-family: 'Fredoka', system-ui, sans-serif;
    font-size: clamp(28px, 4.4vw, 48px);
    line-height: 1.1;
    color: #0f172a;
    margin: 16px 0 14px;
  }
  .es-hero h1 .accent { color: #ea580c; }
  .es-hero p.lead { font-size: 16px; color: #475569; line-height: 1.55; margin-bottom: 16px; }
  .es-hero .badge-row {
    display: flex; flex-wrap: wrap;
    gap: 8px; align-items: center;
    font-size: 12px;
  }
  .es-hero .badge-row .pill {
    background: white; padding: 5px 11px; border-radius: 999px;
    color: #475569; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }

  /* Login card --------------------- */
  .es-login-card {
    background: white;
    border-radius: 22px;
    padding: 26px 22px;
    box-shadow: 0 16px 48px rgba(15,23,42,0.10);
    border: 2px solid #FFE0C0;
  }
  .es-login-card h3 {
    font-family: 'Fredoka', system-ui, sans-serif;
    font-size: 22px; color: #0f172a; margin-bottom: 4px;
  }
  .es-login-card .sub { font-size: 13px; color: #64748b; margin-bottom: 16px; }
  .es-login-card label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; display: block; }
  .es-login-card input {
    width: 100%; padding: 12px 14px;
    border: 1.5px solid #FFE0C0; border-radius: 12px;
    font-size: 15px; margin-bottom: 14px;
    background: #FFFBF5;
  }
  .es-login-card input:focus { background: white; border-color: #f97316; outline: none; box-shadow: 0 0 0 3px rgba(249,115,22,0.15); }
  .es-login-card .btn-go {
    width: 100%; padding: 14px;
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    color: white; border: none; border-radius: 12px;
    font-weight: 700; font-size: 15px; cursor: pointer;
    box-shadow: 0 6px 20px rgba(234,88,12,0.25);
    transition: transform 0.12s;
  }
  .es-login-card .btn-go:hover { transform: translateY(-1px); }
  .es-login-card .otp-input { font-size: 22px; text-align: center; letter-spacing: 0.4em; font-weight: 700; }

  /* FREE perk strip -------- */
  .free-perk {
    background: linear-gradient(90deg, #ECFDF5 0%, #DEF7E6 100%);
    border-radius: 16px;
    padding: 14px 18px;
    border: 1.5px solid #6ee7b7;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    font-size: 14px;
    margin-bottom: 28px;
  }
  .free-perk strong { color: #047857; }
  .free-perk .free-emoji { font-size: 28px; }

  /* Product cards -------- */
  .pc-row {
    display: grid; gap: 20px;
    grid-template-columns: 1fr;
  }
  @media (min-width: 640px) {
    .pc-row { grid-template-columns: repeat(2, 1fr); }
  }
  @media (min-width: 1024px) {
    .pc-row { grid-template-columns: repeat(4, 1fr); }
  }
  .pc-card {
    background: white;
    border-radius: 22px;
    padding: 22px;
    border: 2px solid #f1f5f9;
    display: flex; flex-direction: column; gap: 10px;
    transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s;
    position: relative;
    overflow: hidden;
  }
  .pc-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,0,0,0.10); border-color: #fdba74; }
  .pc-emoji { font-size: 42px; line-height: 1; }
  .pc-chip {
    display: inline-block; align-self: flex-start;
    padding: 4px 11px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em;
  }
  .pc-title { font-family: 'Fredoka', system-ui; font-size: 19px; color: #0f172a; line-height: 1.2; }
  .pc-sub { font-size: 13px; color: #64748b; line-height: 1.6; flex-grow: 1; }
  .pc-cta {
    margin-top: auto;
    display: inline-block;
    padding: 11px 16px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 13px;
    text-align: center;
    text-decoration: none;
    transition: opacity 0.15s, transform 0.15s;
  }
  .pc-cta:hover { transform: translateY(-1px); }
  .pc-cta-primary { background: linear-gradient(135deg, #f97316, #ea580c); color: white; }
  .pc-cta-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; }
  .pc-cta-green { background: linear-gradient(135deg, #10b981, #059669); color: white; }
  .pc-cta-wa { background: linear-gradient(135deg, #25D366, #128C7E); color: white; }
  .pc-cta-disabled {
    background: #f1f5f9; color: #94a3b8;
    cursor: not-allowed; text-decoration: line-through;
  }
  .pc-card.coming-soon {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-style: dashed;
    border-color: #cbd5e1;
  }
  .pc-coming-badge {
    position: absolute; top: 12px; right: 12px;
    background: #fef3c7; color: #92400e;
    font-size: 10px; font-weight: 700;
    padding: 3px 9px; border-radius: 999px;
    letter-spacing: 0.05em;
  }

  /* Specialist photo strip */
  .specialist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
  }
  .specialist-card {
    text-align: center;
    padding: 14px 10px;
    border-radius: 16px;
    background: white;
    border: 1px solid #f1f5f9;
    transition: transform 0.15s, box-shadow 0.15s;
  }
  .specialist-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.06); }
  .specialist-photo {
    width: 88px; height: 88px;
    border-radius: 50%;
    object-fit: cover;
    margin: 0 auto 8px;
    border: 4px solid #FFF7E6;
    box-shadow: 0 3px 12px rgba(251,146,60,0.18);
    background: #fef3c7;
  }
  .specialist-photo-fallback {
    width: 88px; height: 88px;
    border-radius: 50%;
    margin: 0 auto 8px;
    border: 4px solid #FFF7E6;
    box-shadow: 0 3px 12px rgba(251,146,60,0.18);
    background: linear-gradient(135deg, #fb923c, #ea580c);
    color: white;
    font-size: 32px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
  }
  .specialist-name { font-weight: 700; color: #0f172a; font-size: 13px; line-height: 1.25; margin-bottom: 2px; }
  .specialist-role { font-size: 11px; color: #64748b; line-height: 1.3; }

  /* Section spacing */
  section { margin-bottom: 36px; }
  .section-title {
    font-family: 'Fredoka', system-ui;
    font-size: 26px;
    color: #0f172a;
    text-align: center;
    margin-bottom: 6px;
  }
  .section-sub { text-align: center; font-size: 14px; color: #64748b; margin-bottom: 22px; max-width: 560px; margin-left: auto; margin-right: auto; }

  /* FAQ */
  .faq-item {
    background: white;
    border-radius: 14px;
    padding: 14px 18px;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
  }
  .faq-item summary {
    font-weight: 700;
    color: #0f172a;
    cursor: pointer;
    font-size: 15px;
  }
  .faq-item summary::-webkit-details-marker { display: none; }
  .faq-item summary::before { content: "▸ "; color: #ea580c; }
  .faq-item[open] summary::before { content: "▾ "; }
  .faq-item p { font-size: 14px; color: #475569; line-height: 1.55; margin-top: 8px; }
</style>

<!-- HERO -->
<section class="es-hero">
  <div class="es-hero-grid max-w-6xl mx-auto">

    <div>
      <span class="es-eyebrow">🤖 AI + Clinicians · Parent-First</span>
      <h1>
        Understand your child.<br>
        <span class="accent">Strengthen yourself.</span>
      </h1>
      <p class="lead">
        A warm, AI-guided journey for Indian families. Take a free 2-min check first. Then go deeper —
        voice-led parent reflection, adaptive cognitive evaluations for your child, and clinician-led screening if you need it.
      </p>
      <div class="badge-row">
        <span class="pill">🎙️ Voice-first</span>
        <span class="pill">📄 Real PDF reports</span>
        <span class="pill">👨‍⚕️ AIIMS-trained</span>
        <span class="pill">🇮🇳 Hindi · English · Hinglish</span>
      </div>
    </div>

    <div>
      <div class="es-login-card">
        <?php if ($step === 'phone'): ?>
          <h3>Start free →</h3>
          <p class="sub">Two simple steps. OTP on WhatsApp.</p>

          <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg p-3 mb-3"><?= e($error) ?></div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send_otp">

            <label>Your name</label>
            <input type="text" name="name" value="<?= e($name_in_session) ?>" autocomplete="name" placeholder="e.g. Jyoti" required maxlength="80">

            <label>WhatsApp number</label>
            <input type="tel" name="phone" value="<?= e($phone_in_session) ?>" autocomplete="tel" placeholder="+91 98XXX XXXXX" required>

            <button class="btn-go" type="submit">Send OTP →</button>
          </form>

          <p class="text-xs text-slate-500 mt-3 text-center">
            We never share your data. <a href="/about.php" class="underline">Terms</a>.
          </p>

        <?php else: ?>
          <h3>Enter OTP</h3>
          <p class="sub">Sent on WhatsApp to <strong><?= e($phone_in_session) ?></strong></p>

          <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-lg p-3 mb-3"><?= e($error) ?></div>
          <?php endif; ?>
          <?php if ($info && !$error): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-lg p-3 mb-3"><?= e($info) ?></div>
          <?php endif; ?>
          <?php if ($shown_otp): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg p-3 mb-3 font-mono">
              DEMO OTP: <strong><?= e($shown_otp) ?></strong>
            </div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify_otp">
            <input type="text" inputmode="numeric" pattern="\d{4,6}" maxlength="6"
                   name="code" placeholder="••••••" class="otp-input"
                   autocomplete="one-time-code" autofocus required>
            <button class="btn-go" type="submit">Verify & continue →</button>
          </form>

          <div class="text-center mt-3">
            <a href="/" class="text-xs text-slate-500 hover:text-orange-600 underline">← Use a different number</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>

<!-- FREE perk strip — call out the 2-min check -->
<div class="free-perk max-w-6xl mx-auto">
  <span class="free-emoji">🎁</span>
  <span>
    <strong>FREE: 2-min parenting self-check.</strong>
    Take it the moment you sign in.
    See where you stand across 4 areas — instant report, no payment.
  </span>
</div>

<!-- 4 product cards -->
<section class="max-w-6xl mx-auto">
  <h2 class="section-title">What we do</h2>
  <p class="section-sub">Four offerings. Use one or all. Start with whichever feels most pressing.</p>

  <div class="pc-row">

    <!-- 1. Parent Evaluation -->
    <div class="pc-card">
      <div class="pc-emoji">💜</div>
      <span class="pc-chip" style="background:#ede9fe;color:#6d28d9">₹1,000 · voice</span>
      <h3 class="pc-title">Parent Evaluation</h3>
      <p class="pc-sub">
        A 15-min AI-guided voice reflection on your home, your child, your own state.
        Detailed PDF across 9 life areas + callback from our psychologist within 48h.
      </p>
      <a href="/parent-reflect.php" class="pc-cta pc-cta-purple">Start parent eval →</a>
    </div>

    <!-- 2. Child Learning Hub -->
    <div class="pc-card">
      <div class="pc-emoji">🌱</div>
      <span class="pc-chip" style="background:#d1fae5;color:#065f46">Free baseline · ₹999 course</span>
      <h3 class="pc-title">Child Learning Hub</h3>
      <p class="pc-sub">
        10 adaptive evaluations for your child — Speech, Mind Power, Behaviour, GK, Maths, Language and more.
        AI calibrates to age (4–14). Unlock a 7-day course.
      </p>
      <a href="/child-learn.php" class="pc-cta pc-cta-green">Open child hub →</a>
    </div>

    <!-- 3. ISAA -->
    <div class="pc-card">
      <div class="pc-emoji">🧩</div>
      <span class="pc-chip" style="background:#fef3c7;color:#92400e">Clinician-led</span>
      <h3 class="pc-title">ISAA Autism Screening</h3>
      <p class="pc-sub">
        Indian Scale for Assessment of Autism — the NIMHANS-validated 40-item tool.
        Conducted by a trained clinician, scored and shared securely. Eligible for India's disability certification.
      </p>
      <a href="https://wa.me/919311883132?text=<?= rawurlencode('Hi, I would like to know more about ISAA assessment.') ?>"
         class="pc-cta pc-cta-wa" target="_blank" rel="noopener">💬 Ask on WhatsApp</a>
    </div>

    <!-- 4. Ages 15-25 Coming Soon -->
    <div class="pc-card coming-soon">
      <span class="pc-coming-badge">Coming soon</span>
      <div class="pc-emoji" style="opacity:0.7">🎓</div>
      <span class="pc-chip" style="background:#e0e7ff;color:#3730a3">Ages 15-25</span>
      <h3 class="pc-title" style="color:#475569">Young Adults</h3>
      <p class="pc-sub">
        For students 15-25 — career interests, study habits, identity, emotional resilience.
        Adaptive AI evaluations tailored to teens and young adults.
      </p>
      <a class="pc-cta pc-cta-disabled" onclick="return false;">Launching soon</a>
    </div>

  </div>
</section>

<!-- Trust strip: specialists with photos -->
<?php if (!empty($specialists)): ?>
<section>
  <h2 class="section-title">Trusted by parents · backed by clinicians</h2>
  <p class="section-sub">Our panel of doctors, therapists and counsellors</p>

  <div class="specialist-grid max-w-5xl mx-auto">
    <?php foreach ($specialists as $sp):
      $photo = resolve_specialist_photo($sp['photo'] ?? '');
      $initial = mb_substr($sp['name'] ?? '?', 0, 1);
    ?>
      <div class="specialist-card">
        <?php if ($photo): ?>
          <img class="specialist-photo" src="<?= e($photo) ?>" alt="<?= e($sp['name']) ?>" loading="lazy">
        <?php else: ?>
          <div class="specialist-photo-fallback"><?= e($initial) ?></div>
        <?php endif; ?>
        <div class="specialist-name"><?= e($sp['name']) ?></div>
        <div class="specialist-role"><?= e($sp['role'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="text-center text-sm text-slate-600 mt-6">
    Need to speak to a real person?
    <a href="https://wa.me/919311883132" class="text-emerald-600 font-semibold underline" target="_blank">💬 WhatsApp +91-9311883132</a>
    &middot;
    <a href="tel:+919311696923" class="text-orange-600 font-semibold underline">📞 Call +91-9311696923</a>
  </p>
</section>
<?php endif; ?>

<!-- FAQ -->
<section class="max-w-3xl mx-auto">
  <h2 class="section-title">Common questions</h2>
  <p class="section-sub">If you're new, start here.</p>

  <details class="faq-item">
    <summary>Is the 2-min parenting check really free?</summary>
    <p>Yes. After signing in you get an unlimited free parenting self-check across 4 areas. Real report, no payment, no commitment. Take it as often as you want to see how you change over time.</p>
  </details>

  <details class="faq-item">
    <summary>What ages does Child Learning Hub support?</summary>
    <p>4 to 14 years currently. AI calibrates question difficulty based on your child's exact age. Under-8s use parent-guided mode; 8+ self-led. Ages 15-25 launching soon.</p>
  </details>

  <details class="faq-item">
    <summary>Is the Parent Evaluation also useful if my child is fine?</summary>
    <p>Absolutely. The Parent Evaluation covers 9 areas — couple alignment, finances, your own well-being, family stress, hopes for your child — not just child concerns. Most parents tell us they wished they'd done it earlier.</p>
  </details>

  <details class="faq-item">
    <summary>Is my data safe?</summary>
    <p>Yes. Your conversations, child's data, and reports are never shared. Reports are accessible only by you (via your WhatsApp number) and the clinician you choose to share with.</p>
  </details>

  <details class="faq-item">
    <summary>What is ISAA?</summary>
    <p>Indian Scale for Assessment of Autism — a 40-item NIMHANS-validated tool. It's conducted by a trained clinician, scored, and the result is shared with you via a secure PIN-protected link. Used for India's disability certification process.</p>
  </details>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
