<?php
/**
 * p.php — Pediatrician-branded landing page
 *
 * URL patterns:
 *   /p.php?code=DRSHARMA  (always works)
 *   /p/DRSHARMA            (works if rewrite installed — see bottom)
 *
 * Shows the EmpowerStudents pitch with the referring pediatrician's
 * clinic + doctor branding at the top. Captures the referral so any
 * subsequent paid evaluation flows revenue share to this partner.
 *
 * Image paths read from partners.clinic_image_path and partners.doctor_image_path
 * (nullable — falls back to placeholder if not set).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/partner_capture.php';  // canonical chain; pulls in partner_schema + provides partner_by_code() & partner_capture_from_url()

// Defensive: ensure new partner branding columns exist
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
        $names = array_column($cols, 'name');
        foreach (['clinic_image_path','doctor_image_path','clinic_address','doctor_credentials','custom_message'] as $col) {
            if (!in_array($col, $names, true)) {
                @db()->exec("ALTER TABLE partners ADD COLUMN $col TEXT");
            }
        }
    } catch (Throwable $_) {}
})();

// Resolve code (?code=X, or ?ref=X, or path /p/X via REQUEST_URI)
$code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string)($_GET['code'] ?? $_GET['ref'] ?? '')));
if ($code === '' && !empty($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/p/([A-Z0-9_-]+)#i', $_SERVER['REQUEST_URI'], $m)) {
        $code = strtoupper($m[1]);
    }
}

if ($code === '') {
    header('Location: /');
    exit;
}

$partner = partner_by_code($code);
if (!$partner || ($partner['status'] ?? '') !== 'active') {
    // Land on a friendly note rather than a 404
    header('Location: /?invalid_ref=' . urlencode($code));
    exit;
}

// Capture referral (sets session + cookie)
$_GET['ref'] = $code;
partner_capture_from_url();

// Language toggle
$lang = isset($_GET['lang']) ? strtolower((string)$_GET['lang']) : (isset($_COOKIE['emp_lang']) ? $_COOKIE['emp_lang'] : 'en');
$lang = in_array($lang, ['en', 'hi'], true) ? $lang : 'en';
if (!isset($_COOKIE['emp_lang']) || $_COOKIE['emp_lang'] !== $lang) {
    setcookie('emp_lang', $lang, [
        'expires' => time() + 86400 * 90, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax',
    ]);
}
$is_hindi = $lang === 'hi';

// Branding fields
$partner_name       = (string)($partner['name'] ?? 'our clinical partner');
$contact_name       = (string)($partner['contact_name'] ?? '');
$city               = (string)($partner['city'] ?? '');
$clinic_image       = (string)($partner['clinic_image_path'] ?? '');
$doctor_image       = (string)($partner['doctor_image_path'] ?? '');
$clinic_address     = (string)($partner['clinic_address'] ?? '');
$doctor_credentials = (string)($partner['doctor_credentials'] ?? ($is_hindi ? 'पीडियाट्रिशियन' : 'Pediatrician'));
$custom_message     = (string)($partner['custom_message'] ?? '');

$doctor_display = $contact_name !== '' ? $contact_name : $partner_name;

$page_title = ($is_hindi ? 'अनुशंसित — ' : 'Recommended by ') . $doctor_display . ' · EmpowerStudents';

// Build URL with ref preserved for CTAs
$register_url = '/parent-register.php?ref=' . urlencode($code);
$start_eval_url = '/parent-reflect.php?ref=' . urlencode($code);
$switch_lang = '/p.php?code=' . urlencode($code) . '&lang=' . ($is_hindi ? 'en' : 'hi');

?><!DOCTYPE html>
<html lang="<?= $is_hindi ? 'hi' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($is_hindi
  ? "AI-powered Parenting Evaluation, " . $doctor_display . " द्वारा अनुशंसित। 1 घंटे में psychologist द्वारा review किया गया PDF report।"
  : "AI-powered Parenting Evaluation, recommended by " . $doctor_display . ". Psychologist-reviewed PDF report in 1 hour.") ?>">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  .hi { font-family: 'Noto Sans Devanagari', 'DM Sans', sans-serif; }
  body.hi { font-family: 'Noto Sans Devanagari', 'DM Sans', sans-serif; }
  .gradient-text {
    background: linear-gradient(135deg, #059669, #0d9488);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .step-num { font-feature-settings: "tnum"; }
  .placeholder-photo {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #94a3b8;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; text-align: center;
  }
  .icon-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f0fdf4; color: #047857;
    border: 1px solid #bbf7d0;
    padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 600;
  }
  details > summary { list-style: none; cursor: pointer; }
  details > summary::-webkit-details-marker { display: none; }
  details[open] .toggle-icon { transform: rotate(180deg); }
  .toggle-icon { transition: transform 0.2s; }
  @keyframes fadeup { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }
  .fade-up { animation: fadeup 0.5s ease-out; }
</style>
</head>
<body class="bg-slate-50 text-slate-800<?= $is_hindi ? ' hi' : '' ?>">

<!-- ── Top brand strip ── -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-20 shadow-sm">
  <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="/" class="flex items-center gap-2">
      <div class="w-9 h-9 bg-emerald-600 rounded-lg flex items-center justify-center text-white font-bold text-lg">E</div>
      <span class="font-bold text-slate-900">EmpowerStudents.in</span>
    </a>
    <a href="<?= htmlspecialchars($switch_lang) ?>" class="text-xs px-3 py-1.5 border border-slate-300 rounded-full hover:bg-slate-100">
      <?= $is_hindi ? 'English' : 'हिंदी' ?>
    </a>
  </div>
</header>

<main class="max-w-4xl mx-auto px-4 py-6 space-y-5">

  <!-- ── PEDIATRICIAN BRAND HEADER ── -->
  <section class="bg-gradient-to-br from-white to-emerald-50 border-2 border-emerald-200 rounded-2xl p-5 sm:p-6 shadow-sm fade-up">
    <div class="text-center mb-4">
      <span class="inline-block bg-emerald-600 text-white text-[10px] uppercase tracking-widest font-bold px-3 py-1 rounded-full">
        <?= $is_hindi ? '✓ आपके पीडियाट्रिशियन की अनुशंसा' : '✓ Recommended by your pediatrician' ?>
      </span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-center">
      <!-- Clinic image + name -->
      <div class="text-center">
        <?php if ($clinic_image): ?>
          <img src="<?= htmlspecialchars($clinic_image) ?>" alt="<?= htmlspecialchars($partner_name) ?>"
               class="w-full h-40 object-cover rounded-xl mx-auto border border-emerald-200 shadow-sm">
        <?php else: ?>
          <div class="placeholder-photo w-full h-40 rounded-xl border border-emerald-200">
            <span><?= $is_hindi ? '[Clinic की photo]' : '[Clinic photo]' ?></span>
          </div>
        <?php endif; ?>
        <p class="font-bold text-slate-900 mt-3 text-base"><?= htmlspecialchars($partner_name) ?></p>
        <?php if ($city): ?>
          <p class="text-xs text-slate-600 mt-0.5"><?= htmlspecialchars($city) ?></p>
        <?php endif; ?>
        <?php if ($clinic_address): ?>
          <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($clinic_address) ?></p>
        <?php endif; ?>
      </div>

      <!-- Doctor photo + name -->
      <div class="text-center">
        <?php if ($doctor_image): ?>
          <img src="<?= htmlspecialchars($doctor_image) ?>" alt="<?= htmlspecialchars($doctor_display) ?>"
               class="w-32 h-32 object-cover rounded-full mx-auto border-4 border-white shadow-md">
        <?php else: ?>
          <div class="placeholder-photo w-32 h-32 rounded-full mx-auto border-4 border-white shadow-md">
            <span><?= $is_hindi ? '[Doctor की photo]' : '[Doctor photo]' ?></span>
          </div>
        <?php endif; ?>
        <p class="font-bold text-slate-900 mt-3 text-base"><?= htmlspecialchars($doctor_display) ?></p>
        <p class="text-xs text-emerald-700 font-semibold mt-0.5"><?= htmlspecialchars($doctor_credentials) ?></p>
      </div>
    </div>

    <?php if ($custom_message): ?>
      <div class="mt-5 bg-white/70 rounded-xl p-4 border border-emerald-100">
        <p class="text-sm text-slate-700 italic text-center leading-relaxed">"<?= htmlspecialchars($custom_message) ?>"</p>
        <p class="text-xs text-emerald-700 text-center mt-2">— <?= htmlspecialchars($doctor_display) ?></p>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── OPENING LETTER ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <p class="text-sm text-slate-500 mb-2"><?= $is_hindi ? 'प्रिय अभिभावक,' : 'Dear Parent,' ?></p>
    <p class="text-slate-700 mb-3 leading-relaxed">
      <?= $is_hindi
        ? 'अभिभावकत्व जीवन की सबसे महत्वपूर्ण ज़िम्मेदारियों में से एक है — और सबसे चुनौतीपूर्ण भी।'
        : 'Parenting is one of the most important responsibilities in life — and one of the most challenging.' ?>
    </p>
    <p class="text-slate-700 mb-3 leading-relaxed">
      <?= $is_hindi
        ? "आज की तेज़ बदलती दुनिया में अभिभावकों को बच्चे के confidence, behavior, screen addiction, emotional well-being, academic pressure और communication skills को लेकर बढ़ती चिंता है। ज़्यादातर अभिभावक सच में अपने बच्चों के लिए सबसे अच्छा चाहते हैं — लेकिन अक्सर पता नहीं चलता कि वो सही approach अपना रहे हैं या नहीं।"
        : "In today's fast-changing world, parents face increasing concerns about their child's confidence, behavior, screen addiction, emotional well-being, academic pressure, and communication skills. Most parents genuinely want the best for their children, but often do not know whether they are using the right parenting approach." ?>
    </p>
    <p class="text-slate-700 leading-relaxed">
      <?= $is_hindi ? 'इसी के लिए ' : 'To address this, ' ?>
      <strong>EmpowerStudents.in</strong>
      <?= $is_hindi ? 'ने अनुभवी child psychologists के मार्गदर्शन में एक अनूठा ' : ' has developed a unique ' ?>
      <strong class="gradient-text"><?= $is_hindi ? 'AI-powered Parenting Evaluation Program' : 'AI-powered Parenting Evaluation Program' ?></strong>
      <?= $is_hindi ? ' तैयार किया है।' : ' under the guidance of experienced child psychologists.' ?>
    </p>
  </section>

  <!-- ── STEP 1 ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <div class="flex items-start gap-4 mb-4">
      <div class="step-num bg-emerald-600 text-white font-bold w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">1</div>
      <div class="flex-1 min-w-0">
        <h2 class="text-lg sm:text-xl font-bold text-slate-900"><?= $is_hindi ? 'Parenting Evaluation' : 'Parenting Evaluation' ?></h2>
        <p class="text-sm text-slate-600 mt-1">
          <?= $is_hindi
            ? 'Voice-based AI interview — हिंदी या English में, naturally बोलिए।'
            : 'A voice-based AI interview — speak naturally, in Hindi or English.' ?>
        </p>
      </div>
    </div>

    <p class="text-slate-700 mb-3 leading-relaxed text-sm sm:text-base">
      <?= $is_hindi ? 'हमारा AI system analyse करता है:' : 'Our AI system analyzes:' ?>
    </p>
    <ul class="space-y-2 mb-3">
      <?php
      $step1_items = $is_hindi
        ? [
            ['responses', 'आपके जवाब'],
            ['tone of voice', 'आवाज़ का tone'],
            ['emotional patterns', 'भावनात्मक patterns'],
            ['communication style', 'Communication style'],
            ['parenting beliefs', 'Parenting beliefs और attitudes'],
          ]
        : [
            ['Responses', 'Your responses'],
            ['Tone of voice', 'Your tone of voice'],
            ['Emotional patterns', 'Emotional patterns'],
            ['Communication style', 'Your communication style'],
            ['Beliefs', 'Your parenting beliefs and attitudes'],
          ];
      foreach ($step1_items as $item):
      ?>
        <li class="flex items-start gap-2 text-sm text-slate-700">
          <span class="text-emerald-600 font-bold mt-0.5">▸</span>
          <span><strong><?= htmlspecialchars($item[1]) ?></strong></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="text-sm text-slate-600 leading-relaxed italic">
      <?= $is_hindi
        ? 'आपके जवाबों के आधार पर AI intelligent follow-up सवाल पूछता है, ताकि हर ज़रूरी area गहराई से explore हो।'
        : 'The AI asks intelligent follow-up questions based on your answers, ensuring every relevant area is explored thoroughly.' ?>
    </p>

    <div class="mt-4 flex flex-wrap gap-2">
      <span class="icon-pill">⏱ <?= $is_hindi ? '13–15 मिनट' : '13–15 minutes' ?></span>
      <span class="icon-pill">🎙 <?= $is_hindi ? 'Voice intake' : 'Voice intake' ?></span>
      <span class="icon-pill">⏸ <?= $is_hindi ? 'Pause / resume' : 'Pause / resume' ?></span>
      <span class="icon-pill">🔒 <?= $is_hindi ? '100% private' : '100% private' ?></span>
    </div>
  </section>

  <!-- ── STEP 2 ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <div class="flex items-start gap-4 mb-4">
      <div class="step-num bg-emerald-600 text-white font-bold w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">2</div>
      <div class="flex-1 min-w-0">
        <h2 class="text-lg sm:text-xl font-bold text-slate-900"><?= $is_hindi ? 'तुरंत Parenting Report' : 'Instant Parenting Report' ?></h2>
        <p class="text-sm text-slate-600 mt-1">
          <?= $is_hindi
            ? 'Interview के तुरंत बाद, आपको scores मिलते हैं।'
            : 'Immediately after the interview, you receive your scores.' ?>
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <?php
      $step2_items = $is_hindi
        ? [
            ['🎯', 'Parenting Score'],
            ['💖', 'Emotional Connection Index'],
            ['⚖️', 'Discipline Style Assessment'],
            ['🗣️', 'Communication Effectiveness Score'],
            ['😮‍💨', 'Stress and Burnout Indicators'],
            ['🌟', 'Child Confidence Impact Score'],
            ['📝', 'Short personalized recommendations'],
          ]
        : [
            ['🎯', 'Parenting Score'],
            ['💖', 'Emotional Connection Index'],
            ['⚖️', 'Discipline Style Assessment'],
            ['🗣️', 'Communication Effectiveness Score'],
            ['😮‍💨', 'Stress and Burnout Indicators'],
            ['🌟', 'Child Confidence Impact Score'],
            ['📝', 'Short personalized recommendations'],
          ];
      foreach ($step2_items as $item):
      ?>
        <div class="flex items-center gap-2 bg-slate-50 rounded-lg p-2.5 text-sm text-slate-700">
          <span class="text-lg flex-shrink-0"><?= $item[0] ?></span>
          <span><?= htmlspecialchars($item[1]) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── STEP 3 ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <div class="flex items-start gap-4 mb-4">
      <div class="step-num bg-emerald-600 text-white font-bold w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">3</div>
      <div class="flex-1 min-w-0">
        <h2 class="text-lg sm:text-xl font-bold text-slate-900"><?= $is_hindi ? 'Detailed Psychologist Report' : 'Detailed Psychologist Report' ?></h2>
        <p class="text-sm text-slate-600 mt-1">
          <?= $is_hindi
            ? 'एक घंटे में — psychologist supervision में तैयार comprehensive PDF।'
            : 'Within one hour — a comprehensive PDF, prepared under psychologist supervision.' ?>
        </p>
      </div>
    </div>

    <ul class="space-y-2 mb-4">
      <?php
      $step3_items = $is_hindi
        ? [
            'आपकी parenting style का गहराई से analysis',
            'आपकी मुख्य strengths और blind spots',
            'विशिष्ट dos और don\'ts',
            'बच्चे की confidence, behavior और emotional health सुधारने के practical तरीक़े',
          ]
        : [
            "In-depth analysis of your parenting style",
            "Key strengths and blind spots",
            "Specific dos and don'ts",
            "Practical strategies to improve your child's confidence, behavior, and emotional health",
          ];
      foreach ($step3_items as $item):
      ?>
        <li class="flex items-start gap-2 text-sm text-slate-700">
          <span class="text-emerald-600 font-bold mt-0.5">✓</span>
          <span><?= htmlspecialchars($item) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Sample PDF download -->
    <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 rounded-xl p-4 flex items-center gap-3">
      <div class="text-3xl">📄</div>
      <div class="flex-1 min-w-0">
        <div class="font-bold text-emerald-900 text-sm">
          <?= $is_hindi ? 'Sample report देखें' : 'See a sample report' ?>
        </div>
        <div class="text-xs text-emerald-700 mt-0.5">
          <?= $is_hindi ? '7-page PDF — असली शक्ल जैसी आपको मिलेगी।' : '7-page PDF — the actual shape of what you receive.' ?>
        </div>
      </div>
      <a href="/sample_parent_evaluation_report.pdf" target="_blank"
         class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-lg flex-shrink-0">
        <?= $is_hindi ? 'देखें →' : 'View →' ?>
      </a>
    </div>
  </section>

  <!-- ── STEP 4 ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <div class="flex items-start gap-4 mb-4">
      <div class="step-num bg-emerald-600 text-white font-bold w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">4</div>
      <div class="flex-1 min-w-0">
        <h2 class="text-lg sm:text-xl font-bold text-slate-900"><?= $is_hindi ? '7-दिन का Parenting Transformation Program' : 'One-Week Parenting Transformation Program' ?></h2>
        <p class="text-sm text-slate-600 mt-1">
          <?= $is_hindi
            ? 'चाहें तो 7-दिन के guided program में आगे बढ़ें।'
            : 'If you choose to continue, enroll in our 7-day guided program.' ?>
        </p>
      </div>
    </div>

    <p class="text-sm text-slate-700 mb-2"><?= $is_hindi ? 'हर दिन include करता है:' : 'Each day includes:' ?></p>
    <ul class="space-y-2">
      <?php
      $step4_items = $is_hindi
        ? [
            ['🎙', 'एक छोटा AI voice interview'],
            ['🔍', 'आपके actions और challenges की review'],
            ['💬', 'Personalized feedback'],
            ['🧭', 'Psychologist-designed protocols से daily guidance'],
            ['📋', 'क्या करना है और क्या नहीं — step-by-step'],
          ]
        : [
            ['🎙', 'A short AI voice interview'],
            ['🔍', 'Review of your actions and challenges'],
            ['💬', 'Personalized feedback'],
            ['🧭', 'Daily guidance from psychologist-designed protocols'],
            ['📋', 'Step-by-step instructions on what to do and what to avoid'],
          ];
      foreach ($step4_items as $item):
      ?>
        <li class="flex items-center gap-3 text-sm text-slate-700 bg-slate-50 rounded-lg p-3">
          <span class="text-xl flex-shrink-0"><?= $item[0] ?></span>
          <span><?= htmlspecialchars($item[1]) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── THE OUTCOME ── -->
  <section class="bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-200 rounded-2xl p-5 sm:p-6">
    <h2 class="text-lg sm:text-xl font-bold text-orange-900 mb-3">🌱 <?= $is_hindi ? 'क्या होगा? — The Outcome' : 'The Outcome' ?></h2>
    <p class="text-base text-orange-900 font-semibold mb-3 leading-relaxed">
      <?= $is_hindi ? 'जब अभिभावक बदलते हैं — बच्चे बदलते हैं।' : 'When parents change, children change.' ?>
    </p>
    <p class="text-sm text-orange-800 mb-3 leading-relaxed">
      <?= $is_hindi
        ? "हफ़्ते के अंत तक, अभिभावक आम तौर पर अनुभव करते हैं:"
        : "By the end of the week, parents typically experience:" ?>
    </p>
    <ul class="space-y-2">
      <?php
      $outcomes = $is_hindi
        ? [
            'बच्चे के साथ बेहतर communication',
            'कम conflict और कम stress',
            'बिना चिल्लाए improved discipline',
            'मज़बूत emotional bonding',
            'बच्चे के confidence और behavior में noticeable positive change',
          ]
        : [
            "Better communication with their child",
            "Reduced conflicts and stress",
            "Improved discipline without shouting",
            "Stronger emotional bonding",
            "Noticeable positive changes in their child's confidence and behavior",
          ];
      foreach ($outcomes as $item):
      ?>
        <li class="flex items-start gap-2 text-sm text-orange-900">
          <span class="font-bold mt-0.5">✓</span>
          <span><?= htmlspecialchars($item) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── PRICING + IN-PAGE SIGNUP FLOW ── -->
  <section id="signupCard" class="bg-white rounded-2xl p-5 sm:p-6 shadow-md border-2 border-emerald-300 scroll-mt-20">
    <div class="text-center mb-4">
      <div class="text-xs uppercase tracking-widest font-bold text-emerald-700"><?= $is_hindi ? 'आज शुरू करें' : 'Begin Today' ?></div>
      <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 mt-2">
        <?= $is_hindi ? 'Parenting Evaluation' : 'Parenting Evaluation' ?>
      </h2>
      <div class="flex items-baseline justify-center gap-2 mt-3">
        <span class="text-4xl font-bold gradient-text">₹1,000</span>
        <span class="text-sm text-slate-500"><?= $is_hindi ? '· one-time' : '· one-time' ?></span>
      </div>
      <p class="text-xs text-slate-600 mt-2 max-w-md mx-auto">
        <?= $is_hindi
          ? 'Voice interview + तुरंत scores + 1 घंटे में detailed psychologist-reviewed PDF।'
          : 'Voice interview + instant scores + detailed psychologist-reviewed PDF in 1 hour.' ?>
      </p>
    </div>

    <!-- Progress dots -->
    <div class="flex items-center justify-center gap-1.5 mb-4" id="signupProgress">
      <span class="progress-dot active w-2.5 h-2.5 rounded-full bg-emerald-600" data-step="phone"></span>
      <span class="progress-line w-6 h-0.5 bg-slate-300"></span>
      <span class="progress-dot w-2.5 h-2.5 rounded-full bg-slate-300" data-step="otp"></span>
      <span class="progress-line w-6 h-0.5 bg-slate-300"></span>
      <span class="progress-dot w-2.5 h-2.5 rounded-full bg-slate-300" data-step="child"></span>
      <span class="progress-line w-6 h-0.5 bg-slate-300"></span>
      <span class="progress-dot w-2.5 h-2.5 rounded-full bg-slate-300" data-step="pay"></span>
    </div>

    <!-- STEP 1: Phone -->
    <div id="step-phone" class="signup-step">
      <p class="text-xs text-slate-500 mb-3 text-center"><?= $is_hindi ? 'चरण 1 / 4 — आपका WhatsApp number' : 'Step 1 / 4 — Your WhatsApp number' ?></p>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'आपका नाम' : 'Your name' ?></label>
          <input id="signupName" type="text" placeholder="<?= $is_hindi ? 'जैसे: प्रिया शर्मा' : 'e.g. Priya Sharma' ?>"
                 class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'WhatsApp Number' : 'WhatsApp Number' ?></label>
          <input id="signupPhone" type="tel" placeholder="+91 9876543210" autocomplete="tel"
                 class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
          <p class="text-xs text-slate-500 mt-1"><?= $is_hindi ? 'OTP इसी number पर WhatsApp से आएगा।' : 'OTP will arrive on WhatsApp.' ?></p>
        </div>
        <button type="button" id="btnSendOtp"
                class="w-full py-4 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white text-base font-bold rounded-xl shadow transition">
          <?= $is_hindi ? '✓ OTP भेजें' : '✓ Send OTP' ?>
        </button>

        <!-- fresh-v10: promo code (collapsible) -->
        <details class="mt-3">
          <summary class="cursor-pointer text-sm text-emerald-700 hover:text-emerald-900 font-semibold">
            🎁 <?= $is_hindi ? 'Promo / discount code है?' : 'Have a promo / discount code?' ?>
          </summary>
          <div class="mt-2">
            <input id="signupPromoCode" type="text"
                   placeholder="<?= $is_hindi ? 'e.g. DRPABC' : 'e.g. DRPABC' ?>"
                   autocomplete="off" maxlength="20"
                   style="text-transform: uppercase"
                   class="w-full p-3 border border-emerald-300 rounded-lg text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
            <p class="text-xs text-slate-500 mt-1">
              <?= $is_hindi
                ? 'अगर doctor ने code WhatsApp पर भेजा है तो यहाँ डालें — free credit मिलेगा।'
                : 'If your doctor shared a code via WhatsApp, enter it here — free wallet credits will be added.' ?>
            </p>
          </div>
        </details>
      </div>
    </div>

    <!-- STEP 2: OTP -->
    <div id="step-otp" class="signup-step hidden">
      <p class="text-xs text-slate-500 mb-3 text-center"><?= $is_hindi ? 'चरण 2 / 4 — OTP डालें' : 'Step 2 / 4 — Enter the OTP' ?></p>
      <p class="text-sm text-slate-700 mb-3 text-center" id="otpInfo">
        <?= $is_hindi ? 'OTP भेजा गया है' : 'OTP sent to' ?> <span id="otpPhoneDisplay" class="font-bold"></span>
      </p>
      <div class="space-y-3">
        <input id="signupOtp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
               placeholder="123456" autocomplete="one-time-code"
               class="w-full p-4 border-2 border-slate-300 rounded-lg text-center text-2xl font-bold tracking-widest focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
        <button type="button" id="btnVerifyOtp"
                class="w-full py-4 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white text-base font-bold rounded-xl shadow transition">
          <?= $is_hindi ? '✓ Verify करें' : '✓ Verify' ?>
        </button>
        <div class="flex items-center justify-between text-xs">
          <button type="button" id="btnBackToPhone" class="text-slate-500 hover:text-slate-700 underline">← <?= $is_hindi ? 'Number बदलें' : 'Change number' ?></button>
          <button type="button" id="btnResendOtp" class="text-emerald-700 hover:text-emerald-900 underline" disabled>
            <span id="resendText"><?= $is_hindi ? 'OTP फिर भेजें' : 'Resend OTP' ?></span>
          </button>
        </div>
      </div>
    </div>

    <!-- STEP 3: Child info -->
    <div id="step-child" class="signup-step hidden">
      <p class="text-xs text-slate-500 mb-3 text-center"><?= $is_hindi ? 'चरण 3 / 4 — बच्चे की जानकारी' : 'Step 3 / 4 — About your child' ?></p>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'बच्चे का नाम' : "Child's name" ?></label>
          <input id="childName" type="text" placeholder="<?= $is_hindi ? 'जैसे: आरव' : 'e.g. Aarav' ?>"
                 class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'जन्म तिथि' : 'Date of birth' ?></label>
            <input id="childDob" type="date" max="<?= date('Y-m-d') ?>"
                   class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'लिंग' : 'Gender' ?></label>
            <select id="childGender" class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
              <option value="">—</option>
              <option value="Male"><?= $is_hindi ? 'लड़का' : 'Boy' ?></option>
              <option value="Female"><?= $is_hindi ? 'लड़की' : 'Girl' ?></option>
              <option value="Other"><?= $is_hindi ? 'अन्य' : 'Other' ?></option>
            </select>
          </div>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1 uppercase tracking-wide"><?= $is_hindi ? 'घर की language' : 'Mother tongue' ?></label>
          <select id="childLang" class="w-full p-3 border border-slate-300 rounded-lg text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
            <option value="Hindi"<?= $is_hindi ? ' selected' : '' ?>>Hindi</option>
            <option value="English"<?= !$is_hindi ? ' selected' : '' ?>>English</option>
            <option value="Hinglish">Hinglish (mix)</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <button type="button" id="btnSaveChild"
                class="w-full py-4 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white text-base font-bold rounded-xl shadow transition">
          <?= $is_hindi ? 'अगला: Payment →' : 'Continue to Payment →' ?>
        </button>
      </div>
    </div>

    <!-- STEP 4: Pay -->
    <div id="step-pay" class="signup-step hidden">
      <p class="text-xs text-slate-500 mb-3 text-center"><?= $is_hindi ? 'चरण 4 / 4 — Payment' : 'Step 4 / 4 — Payment' ?></p>
      <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-4 text-center">
        <p class="text-sm text-emerald-900 leading-relaxed">
          <?= $is_hindi
            ? "✓ आपका registration complete हो गया। अब ₹1,000 का payment करें और तुरंत evaluation शुरू हो जाएगी।"
            : "✓ Registration complete. Pay ₹1,000 and your evaluation starts immediately." ?>
        </p>
      </div>
      <div class="space-y-2 text-sm mb-4 bg-slate-50 rounded-lg p-3">
        <div class="flex justify-between"><span class="text-slate-600"><?= $is_hindi ? 'Parent Evaluation' : 'Parent Evaluation' ?></span><span class="font-bold">₹1,000</span></div>
        <div class="flex justify-between text-xs text-slate-500"><span><?= $is_hindi ? 'Tax शामिल' : 'Tax included' ?></span><span><?= $is_hindi ? 'एक बार' : 'One-time' ?></span></div>
      </div>
      <button type="button" id="btnPay"
              class="w-full text-center py-4 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white text-lg font-bold rounded-xl shadow-lg transition">
        <?= $is_hindi ? '💳 ₹1,000 Pay करें' : '💳 Pay ₹1,000' ?>
      </button>
      <p class="text-center text-xs text-slate-500 mt-3">
        🔒 <?= $is_hindi ? 'Cashfree से secure payment · UPI, Card, NetBanking' : 'Secure payment via Cashfree · UPI, Card, NetBanking' ?>
      </p>
    </div>

    <!-- STEP 5: Ready (already paid — show start button) -->
    <div id="step-ready" class="signup-step hidden">
      <p class="text-xs text-slate-500 mb-3 text-center">✓ <?= $is_hindi ? 'सब तैयार है' : 'All set' ?></p>
      <div class="bg-emerald-50 border-2 border-emerald-300 rounded-xl p-4 mb-4 text-center">
        <div class="text-3xl mb-2">🌿</div>
        <p class="text-sm text-emerald-900 leading-relaxed">
          <?= $is_hindi
            ? "आपका registration और payment पहले से ही complete है। अभी evaluation शुरू कर सकते हैं।"
            : "Your registration and payment are complete. You can start your evaluation now." ?>
        </p>
      </div>
      <a href="/parent-reflect.php?fresh=1"
         class="block w-full text-center py-4 px-6 bg-gradient-to-br from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white text-lg font-bold rounded-xl shadow-lg transition">
        <?= $is_hindi ? '🎙 Evaluation शुरू करें →' : '🎙 Start your evaluation →' ?>
      </a>
    </div>

    <!-- Error / info messages -->
    <div id="signupMsg" class="mt-3 hidden text-sm rounded-lg p-3"></div>

    <p class="text-center text-xs text-emerald-700 font-semibold mt-3">
      🔒 <?= $is_hindi ? '100% private · DPDP Act 2023 compliant' : '100% private · DPDP Act 2023 compliant' ?>
    </p>
  </section>

  <script>
  (function () {
    const REF_CODE = <?= json_encode($code) ?>;
    const IS_HINDI = <?= $is_hindi ? 'true' : 'false' ?>;
    const T = (en, hi) => IS_HINDI ? hi : en;
    let resendTimer = null;
    let csrf = '';

    const $ = id => document.getElementById(id);

    function setStep(step) {
      ['phone', 'otp', 'child', 'pay', 'ready'].forEach(s => {
        const el = $('step-' + s);
        if (el) el.classList.toggle('hidden', s !== step);
      });
      // Update progress dots
      const order = ['phone', 'otp', 'child', 'pay'];
      let reached = order.indexOf(step);
      if (step === 'ready') reached = order.length;   // all dots active
      document.querySelectorAll('#signupProgress .progress-dot').forEach((el, i) => {
        if (i <= reached) {
          el.classList.remove('bg-slate-300');
          el.classList.add('bg-emerald-600');
        } else {
          el.classList.add('bg-slate-300');
          el.classList.remove('bg-emerald-600');
        }
      });
      // Scroll to top of card
      const card = document.getElementById('signupCard');
      if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showMsg(text, type) {
      const el = $('signupMsg');
      el.textContent = text;
      el.classList.remove('hidden', 'bg-rose-50', 'border', 'border-rose-200', 'text-rose-800',
                          'bg-emerald-50', 'border-emerald-200', 'text-emerald-800');
      if (type === 'error') {
        el.classList.add('bg-rose-50', 'border', 'border-rose-200', 'text-rose-800');
      } else {
        el.classList.add('bg-emerald-50', 'border', 'border-emerald-200', 'text-emerald-800');
      }
    }

    function hideMsg() {
      $('signupMsg').classList.add('hidden');
    }

    async function api(action, data) {
      const fd = new FormData();
      fd.append('action', action);
      for (const k in data) fd.append(k, data[k]);
      const r = await fetch('/p-api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.json();
    }

    function startResendCountdown(secs) {
      const btn = $('btnResendOtp');
      const txt = $('resendText');
      btn.disabled = true;
      let remaining = secs;
      txt.textContent = T(`Resend in ${remaining}s`, `${remaining}s में फिर भेजें`);
      if (resendTimer) clearInterval(resendTimer);
      resendTimer = setInterval(() => {
        remaining--;
        if (remaining <= 0) {
          clearInterval(resendTimer);
          btn.disabled = false;
          txt.textContent = T('Resend OTP', 'OTP फिर भेजें');
        } else {
          txt.textContent = T(`Resend in ${remaining}s`, `${remaining}s में फिर भेजें`);
        }
      }, 1000);
    }

    // ── Step 1: Send OTP ──
    $('btnSendOtp').addEventListener('click', async () => {
      hideMsg();
      const name = $('signupName').value.trim();
      const phone = $('signupPhone').value.trim();
      if (!name) return showMsg(T('Please enter your name.', 'कृपया नाम डालें।'), 'error');
      if (!phone || phone.replace(/\D/g, '').length < 10) {
        return showMsg(T('Please enter a valid WhatsApp number with country code.', 'सही WhatsApp number country code के साथ डालें।'), 'error');
      }

      const btn = $('btnSendOtp');
      btn.disabled = true;
      btn.textContent = T('Sending…', 'भेजा जा रहा है…');
      try {
        const r = await api('send_otp', { name, phone, ref_code: REF_CODE });
        if (!r.ok) {
          showMsg(r.error || 'Could not send OTP', 'error');
          btn.disabled = false;
          btn.textContent = T('✓ Send OTP', '✓ OTP भेजें');
          return;
        }
        $('otpPhoneDisplay').textContent = r.phone || phone;
        setStep('otp');
        startResendCountdown(30);
        if (r.demo_otp) {
          showMsg('DEMO MODE — OTP: ' + r.demo_otp, 'info');
        }
      } catch (e) {
        showMsg(T('Network error. Try again.', 'Network error। फिर try करें।'), 'error');
        btn.disabled = false;
        btn.textContent = T('✓ Send OTP', '✓ OTP भेजें');
      }
    });

    // ── Step 2: Verify OTP ──
    $('btnVerifyOtp').addEventListener('click', async () => {
      hideMsg();
      const code = $('signupOtp').value.trim();
      if (code.length < 4) return showMsg(T('Enter the OTP.', 'OTP डालें।'), 'error');

      const btn = $('btnVerifyOtp');
      btn.disabled = true;
      btn.textContent = T('Verifying…', 'Verify हो रहा है…');
      try {
        const r = await api('verify_otp', { code });
        if (!r.ok) {
          showMsg(r.error || 'Verification failed', 'error');
          btn.disabled = false;
          btn.textContent = T('✓ Verify', '✓ Verify करें');
          return;
        }
        csrf = r.csrf || '';
        // Use the same logic as check_status:
        // - no child → child step
        // - has child + balance >= 1000 → ready (start eval)
        // - has child + balance < 1000 → pay
        if (!r.has_child) {
          setStep('child');
        } else {
          // Balance check via check_status (more reliable than verify_otp response)
          const s = await api('check_status', {});
          if (s.ok && s.step === 'ready') setStep('ready');
          else setStep('pay');
        }
      } catch (e) {
        showMsg(T('Network error. Try again.', 'Network error।'), 'error');
        btn.disabled = false;
        btn.textContent = T('✓ Verify', '✓ Verify करें');
      }
    });

    // Back to phone
    $('btnBackToPhone').addEventListener('click', () => {
      hideMsg();
      setStep('phone');
    });

    // Resend OTP
    $('btnResendOtp').addEventListener('click', async () => {
      if ($('btnResendOtp').disabled) return;
      hideMsg();
      const name = $('signupName').value.trim();
      const phone = $('signupPhone').value.trim();
      try {
        const r = await api('send_otp', { name, phone, ref_code: REF_CODE });
        if (!r.ok) return showMsg(r.error || 'Could not resend', 'error');
        showMsg(T('OTP resent.', 'OTP फिर भेज दिया गया।'));
        startResendCountdown(30);
        if (r.demo_otp) showMsg('DEMO OTP: ' + r.demo_otp, 'info');
      } catch (e) {
        showMsg(T('Network error.', 'Network error।'), 'error');
      }
    });

    // ── Step 3: Save child ──
    $('btnSaveChild').addEventListener('click', async () => {
      hideMsg();
      const child_name = $('childName').value.trim();
      if (!child_name) return showMsg(T("Please enter your child's name.", 'बच्चे का नाम डालें।'), 'error');

      const btn = $('btnSaveChild');
      btn.disabled = true;
      btn.textContent = T('Saving…', 'Save हो रहा है…');

      try {
        const promoEl = document.getElementById('signupPromoCode');
        const promo_code = promoEl ? promoEl.value.trim().toUpperCase() : '';
        const r = await api('create_child', {
          child_name,
          child_dob: $('childDob').value,
          gender: $('childGender').value,
          mother_tongue: $('childLang').value,
          promo_code,
        });
        if (!r.ok) {
          showMsg(r.error || 'Could not save', 'error');
          btn.disabled = false;
          btn.textContent = T('Continue to Payment →', 'अगला: Payment →');
          return;
        }
        /* fresh-v10: show promo redemption result if any */
        if (r.promo) {
          if (r.promo.applied) {
            showMsg(T('🎁 Promo code applied — ₹', '🎁 Promo code apply हुआ — ₹') + r.promo.credit + T(' credited!', ' credit मिले!'), 'ok');
          } else if (r.promo.error) {
            /* non-fatal — show but still proceed */
            showMsg(T('Code issue: ', 'Code issue: ') + r.promo.error, 'error');
          }
        }
        setStep('pay');
      } catch (e) {
        showMsg(T('Network error.', 'Network error।'), 'error');
        btn.disabled = false;
        btn.textContent = T('Continue to Payment →', 'अगला: Payment →');
      }
    });

    // ── Step 4: Pay (Cashfree Drop-in) ──
    let cashfreeSdkLoaded = false;
    function loadCashfreeSDK() {
      if (cashfreeSdkLoaded || typeof window.Cashfree === 'function') {
        cashfreeSdkLoaded = true;
        return Promise.resolve();
      }
      return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://sdk.cashfree.com/js/v3/cashfree.js';
        s.onload = () => { cashfreeSdkLoaded = true; resolve(); };
        s.onerror = () => reject(new Error('Could not load payment SDK'));
        document.head.appendChild(s);
      });
    }

    const btnPay = $('btnPay');
    if (btnPay) {
      btnPay.addEventListener('click', async () => {
        hideMsg();
        btnPay.disabled = true;
        const origText = btnPay.textContent;
        btnPay.textContent = T('Starting payment…', 'Payment शुरू हो रहा है…');

        try {
          await loadCashfreeSDK();
          const r = await api('create_order', { ref_code: REF_CODE });
          if (!r.ok || !r.payment_session_id) {
            showMsg(r.error || T('Could not start payment.', 'Payment शुरू नहीं हो सका।'), 'error');
            btnPay.disabled = false;
            btnPay.textContent = origText;
            return;
          }

          if (typeof window.Cashfree !== 'function') {
            showMsg(T('Payment SDK not loaded.', 'Payment SDK load नहीं हुआ।'), 'error');
            btnPay.disabled = false;
            btnPay.textContent = origText;
            return;
          }

          const cf = window.Cashfree({ mode: r.mode === 'production' ? 'production' : 'sandbox' });
          cf.checkout({
            paymentSessionId: r.payment_session_id,
            redirectTarget: '_self',  // redirects window to /p-return.php
          });
          // Cashfree takes over the window.
        } catch (e) {
          showMsg(T('Network error. Try again.', 'Network error।'), 'error');
          btnPay.disabled = false;
          btnPay.textContent = origText;
        }
      });
    }

    // ── Check if user is already logged in (resume mid-flow) ──
    api('check_status', {}).then(r => {
      if (r && r.ok && r.step && r.step !== 'phone') {
        setStep(r.step);
      }
    }).catch(() => {});
  })();
  </script>

  <!-- ── WHY THIS, WHY NOW (FAQ) ── -->
  <section class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200">
    <h2 class="text-lg sm:text-xl font-bold text-slate-900 mb-4">❓ <?= $is_hindi ? 'सवाल जो हर अभिभावक के मन में होते हैं' : 'Questions parents often ask' ?></h2>

    <?php
    $faqs = $is_hindi
      ? [
          ['यह therapy है क्या? क्या मुझे therapist की ज़रूरत है?',
           'नहीं — यह एक wellness assessment है, therapy नहीं। यह आपको आपकी पूरी ज़िंदगी का — अकेलापन, थकान, support की कमी, बच्चे की चिंता — सब एक साथ देखने में मदद करता है। अगर ज़रूरत हो, हमारी psychologist आपको refer करेंगी।'],
          ['मेरी privacy सुरक्षित है?',
           'हाँ। आपका recording, transcript, और report सिर्फ़ आपके account में सुरक्षित हैं। आपकी अनुमति के बिना कोई और नहीं देखता। केवल आपके referring पीडियाट्रिशियन को PDF भेजा जाता है (अगर आपने consent दिया है)।'],
          ['क्या मेरे पति/wife को पता चलेगा?',
           'नहीं — आप अकेले अपने phone पर करते हैं। आप चाहें तो report share करें, चाहें तो ना करें।'],
          ['यह 13-15 minute में सच में helpful हो सकता है?',
           'Listing-based approach का यही मक़सद है। हम सब areas को छूते हैं — child, couple, family, finances, यानी आप। आप सब कुछ नाम देकर देख पाते हैं। यह real शुरुआत है।'],
          ['मैं Hindi में बोलूँ या English में?',
           'जो भी comfortable हो। Code-switching (Hindi-English mix) बिल्कुल OK है। AI आपके natural pattern को follow करेगा।'],
          ['Pause कर सकती हूँ?',
           'हाँ। बीच में Pause button है — कुछ काम है तो रुक जाइए, बाद में वापस आकर वहीं से continue कीजिए।'],
        ]
      : [
          ["Is this therapy? Do I need a therapist?",
           "No — this is a wellness assessment, not therapy. It helps you see your whole life — exhaustion, isolation, lack of support, child concerns — together. If you need professional help, our psychologist will refer you."],
          ["Is my privacy protected?",
           "Yes. Your recording, transcript, and report are private to your account. Nobody else sees them without your consent. Only your referring pediatrician receives a copy of the PDF (if you opt in)."],
          ["Will my spouse find out?",
           "No — you do it alone on your phone. Share the report only if you want to."],
          ["Can 13-15 minutes really help?",
           "That's the point of the listing-based approach. We touch every area — child, couple, family, finances, you. Naming everything is where real change starts."],
          ["Should I speak Hindi or English?",
           "Whichever feels natural. Code-switching (mixing Hindi and English) is welcome. The AI follows your pattern."],
          ["Can I pause?",
           "Yes. There's a Pause button on every screen — step away if you need to, come back any time and continue exactly where you left off."],
        ];
    foreach ($faqs as $f):
    ?>
      <details class="border-b border-slate-200 last:border-0">
        <summary class="flex items-center justify-between py-3 hover:bg-slate-50 -mx-2 px-2 rounded">
          <span class="text-sm font-semibold text-slate-900 pr-4"><?= htmlspecialchars($f[0]) ?></span>
          <span class="toggle-icon text-slate-400">▼</span>
        </summary>
        <p class="text-sm text-slate-700 pb-3 px-1 leading-relaxed"><?= htmlspecialchars($f[1]) ?></p>
      </details>
    <?php endforeach; ?>
  </section>

  <!-- ── SECOND CTA ── -->
  <section class="text-center py-6">
    <a href="#signupCard"
       class="inline-block py-3 px-8 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow">
      <?= $is_hindi ? '✓ Evaluation शुरू करें — ₹1,000' : '✓ Start your evaluation — ₹1,000' ?>
    </a>
    <p class="text-xs text-slate-500 mt-2">
      <?= $is_hindi ? "बच्चे का बेहतर कल — आज आपके यहाँ से शुरू।" : "Your child's future begins with empowered parenting." ?>
    </p>
  </section>

  <!-- ── DOCTOR'S CLOSING NOTE ── -->
  <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 text-center">
    <p class="text-sm text-slate-600 italic mb-1">
      <?= $is_hindi ? 'सादर,' : 'Warm regards,' ?>
    </p>
    <p class="font-bold text-slate-900"><?= htmlspecialchars($doctor_display) ?></p>
    <p class="text-xs text-emerald-700 font-semibold"><?= htmlspecialchars($doctor_credentials) ?></p>
    <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($partner_name) ?></p>
    <p class="text-xs text-slate-400 mt-3">— Team EmpowerStudents.in</p>
  </section>

</main>

<footer class="bg-white border-t border-slate-200 mt-6 py-5">
  <div class="max-w-4xl mx-auto px-4 text-center text-xs text-slate-500">
    <p>EmpowerStudents.in · care@empowerstudents.in</p>
    <p class="mt-1">📞 +91-9311696923 · WhatsApp +91-9311883132</p>
    <p class="mt-2 text-[10px] text-slate-400">
      <?= $is_hindi
        ? 'Wellness assessment, चिकित्सकीय निदान नहीं। DPDP Act 2023 compliant।'
        : 'Wellness assessment, not a medical diagnosis. DPDP Act 2023 compliant.' ?>
    </p>
  </div>
</footer>

</body>
</html>
