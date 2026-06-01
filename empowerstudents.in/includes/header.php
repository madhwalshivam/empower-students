<?php
if (!isset($page_title)) $page_title = SITE_NAME;
$logged_parent = function_exists('current_parent') ? current_parent() : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= e(SITE_TAGLINE) ?>">
<meta name="theme-color" content="#fef7e7">
<title><?= e($page_title) ?> &mdash; <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" href="/assets/images/logo-small.png">
<!-- Suppress Tailwind CDN production warning -->
<script>
  (function () {
    const w = console.warn;
    console.warn = function () {
      if (arguments[0] && String(arguments[0]).indexOf('cdn.tailwindcss.com') !== -1) return;
      return w.apply(console, arguments);
    };
  })();
</script>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<style>
  body { font-family: 'Inter', 'Noto Sans Devanagari', system-ui, sans-serif; }
  html[lang="hi"] body { font-family: 'Noto Sans Devanagari', 'Inter', system-ui, sans-serif; }

  /* Brand gradient — used on buttons + logo halo */
  .brand-grad { background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 50%, #10b981 100%); background-size: 180% 180%; }
  .brand-text {
    background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 60%);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }

  /* ── App background — soft cream that subtly hints at the homepage palette ── */
  body {
    background:
      radial-gradient(1100px 600px at 0% -10%, rgba(255, 200, 87, 0.15), transparent 60%),
      radial-gradient(900px 600px at 100% 110%, rgba(168, 230, 207, 0.18), transparent 60%),
      #fafafb;
    min-height: 100vh;
  }

  /* ── Polished header: glass background, soft hairline, sticky shadow ── */
  .es-header {
    background: rgba(255, 255, 255, 0.78);
    backdrop-filter: saturate(1.4) blur(12px);
    -webkit-backdrop-filter: saturate(1.4) blur(12px);
    border-bottom: 1px solid rgba(15, 23, 42, 0.06);
    transition: box-shadow 0.2s ease;
  }
  .es-header.scrolled {
    box-shadow: 0 4px 20px -8px rgba(15, 23, 42, 0.08);
    background: rgba(255, 255, 255, 0.92);
  }

  /* ── Logo block ── */
  .es-logo-img {
    width: 36px; height: 36px;
    object-fit: contain;
    filter: drop-shadow(0 2px 4px rgba(79, 70, 229, 0.18));
  }
  .es-brand-name {
    background: linear-gradient(135deg, #1e293b 0%, #4f46e5 100%);
    -webkit-background-clip: text; background-clip: text;
    color: transparent;
    font-weight: 700;
    letter-spacing: -0.015em;
  }

  /* ── Lang toggle: pill with smooth slide ── */
  .lang-toggle {
    background: rgba(241, 245, 249, 0.9);
    border: 1px solid rgba(15, 23, 42, 0.06);
  }
  .lang-toggle button { color: #64748b; }
  .lang-toggle button.active {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: white;
    box-shadow: 0 2px 8px -2px rgba(79, 70, 229, 0.4);
  }

  /* ── Credits pill: shimmery emerald ── */
  .es-credits-pill {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(6, 182, 212, 0.10));
    border: 1px solid rgba(16, 185, 129, 0.25);
    color: #065f46;
    font-weight: 600;
  }
  .es-credits-pill:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(6, 182, 212, 0.18));
    transform: translateY(-1px);
  }

  /* ── Mobile: smaller, tighter ── */
  @media (max-width: 640px) {
    .es-logo-img { width: 30px; height: 30px; }
    .es-brand-name { font-size: 0.95rem !important; }
    body { font-size: 14px; }
    main { padding-top: 1rem !important; padding-bottom: 1rem !important; }
    h1 { font-size: 1.5rem !important; line-height: 1.2 !important; }
    h2 { font-size: 1.15rem !important; }
    .es-credits-pill { padding: 4px 10px !important; font-size: 11px !important; }
  }

  /* ── Mobile dropdown — cleaner anim ── */
  details[open] > div { animation: es-drop 0.2s ease-out; }
  @keyframes es-drop { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body class="text-slate-800">

<header class="es-header sticky top-0 z-30">
  <div class="max-w-7xl mx-auto px-3 sm:px-6 py-2.5 flex items-center justify-between gap-3">

    <!-- Logo + brand -->
    <a href="/" class="flex items-center gap-2.5 shrink-0 group">
      <img src="/assets/images/logo-small.png" alt="Empower Students" class="es-logo-img"
           onerror="this.outerHTML='&lt;span class=\&quot;brand-grad text-white w-8 h-8 rounded-lg flex items-center justify-center font-bold text-base\&quot;&gt;E&lt;/span&gt;'">
      <span class="es-brand-name text-base sm:text-xl" data-i18n="brand"><?= e(SITE_NAME) ?></span>
    </a>

    <!-- Desktop nav -->
    <nav class="hidden md:flex items-center gap-4 text-sm font-medium">
      <a href="/" class="hover:text-indigo-600" data-i18n="nav.home">Home</a>
      <a href="/specialists.php" class="hover:text-indigo-600" data-i18n="nav.panel">Our Panel</a>
      <a href="/about.php" class="hover:text-indigo-600" data-i18n="nav.about">About</a>

      <div class="lang-toggle inline-flex items-center rounded-full p-0.5 text-xs font-bold ml-1">
        <button type="button" data-set-lang="en" class="px-2.5 py-1 rounded-full">EN</button>
        <button type="button" data-set-lang="hi" class="px-2.5 py-1 rounded-full">हिं</button>
      </div>

      <?php if ($logged_parent): ?>
        <a href="/wallet.php" class="es-credits-pill hidden sm:inline-flex items-center gap-1 text-xs rounded-full px-3 py-1.5">
          💰 <?= (int)($logged_parent['credits'] ?? 0) ?> <span data-i18n="nav.cr">cr</span>
        </a>
        <?php /* fresh-v8f: removed My Modules — dashboard covers it */ ?>
        <a href="/dashboard.php" class="hover:text-indigo-600" data-i18n="nav.dashboard">Dashboard</a>
        <a href="/logout.php" class="text-slate-500 hover:text-rose-600" data-i18n="nav.logout">Logout</a>
      <?php else: ?>
        <a href="/login.php" class="brand-grad text-white px-4 py-1.5 rounded-lg font-semibold hover:opacity-90 shadow-sm" data-i18n="nav.login">Parent Login</a>
      <?php endif; ?>
    </nav>

    <!-- Mobile: credits pill + menu -->
    <div class="md:hidden flex items-center gap-2">
      <?php if ($logged_parent): ?>
        <a href="/wallet.php" class="es-credits-pill inline-flex items-center gap-1 text-xs rounded-full px-2.5 py-1">
          💰 <?= (int)($logged_parent['credits'] ?? 0) ?>
        </a>
      <?php endif; ?>
      <details class="relative">
        <summary class="list-none cursor-pointer p-2 -mr-2 rounded-lg hover:bg-slate-100">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </summary>
        <div class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-200 p-2 flex flex-col text-sm z-50">
          <div class="lang-toggle inline-flex items-center rounded-full p-0.5 text-xs font-bold mx-3 my-2 self-start">
            <button type="button" data-set-lang="en" class="px-2.5 py-1 rounded-full">EN</button>
            <button type="button" data-set-lang="hi" class="px-2.5 py-1 rounded-full">हिं</button>
          </div>
          <a href="/" class="px-3 py-2 hover:bg-slate-50 rounded-lg" data-i18n="nav.home">Home</a>
          <a href="/specialists.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg" data-i18n="nav.panel">Our Panel</a>
          <a href="/about.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg" data-i18n="nav.about">About</a>
          <?php if ($logged_parent): ?>
            <?php /* fresh-v8f: removed My Modules — dashboard covers it */ ?>
            <a href="/dashboard.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg" data-i18n="nav.dashboard">Dashboard</a>
            <a href="/wallet.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg text-emerald-700 font-semibold">💰 <span data-i18n="nav.wallet">Wallet</span> · <?= (int)($logged_parent['credits'] ?? 0) ?> cr</a>
            <a href="/logout.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg text-rose-600" data-i18n="nav.logout">Logout</a>
          <?php else: ?>
            <a href="/login.php" class="px-3 py-2 hover:bg-slate-50 rounded-lg text-indigo-600 font-semibold" data-i18n="nav.login">Parent Login</a>
          <?php endif; ?>
        </div>
      </details>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-3 sm:px-6 py-4 sm:py-10">

<!-- ── Header scroll-shadow trigger ── -->
<script>
(function(){
  const h = document.querySelector('.es-header');
  if (!h) return;
  const onScroll = () => h.classList.toggle('scrolled', window.scrollY > 8);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>

<!-- ── i18n engine — full dictionary ── -->
<script>
window.ES_I18N = {
  hi: {
    'brand'         : 'एम्पावर स्टूडेंट्स',
    'nav.home'      : 'होम',
    'nav.panel'     : 'हमारा पैनल',
    'nav.about'     : 'हमारे बारे में',
    'nav.login'     : 'पैरेंट लॉगिन',
    'nav.dashboard' : 'डैशबोर्ड',
    'nav.mymodules' : 'मेरे मॉड्यूल',
    'nav.logout'    : 'लॉगआउट',
    'nav.wallet'    : 'वॉलेट',
    'nav.cr'        : 'क्रेडिट',

    'hero.eyebrow'  : 'एम्पावर स्टूडेंट्स',
    'hero.title.1'  : 'अपने बच्चे को समझें —',
    'hero.title.2'  : 'हर पहलू में',
    'hero.subtitle' : 'आपके बच्चे की सेहत, मन, भावनाएँ, व्यवहार, भाषा, गणित, बोलने की क्षमता और विशेष प्रतिभा का एक मित्रवत 360° मूल्यांकन।',
    'cta.start'     : 'मुफ़्त मूल्यांकन शुरू करें',
    'cta.meet'      : 'पैनल से मिलें',
    'svc.heading'   : 'एक संपूर्ण बाल मूल्यांकन — उम्र के अनुसार',
    'svc.subhead'   : 'हर मॉड्यूल आसान शुरू होकर बच्चे के स्तर के अनुसार ढलता है।',
    'svc.health.t'  : 'स्वास्थ्य जाँच', 'svc.health.d': 'उम्र के अनुसार वृद्धि, नींद, पोषण और संकेतक।',
    'svc.mind.t'    : 'मन की शक्ति',   'svc.mind.d'  : 'कार्यशील स्मृति, ध्यान, समस्या-समाधान।',
    'svc.emo.t'     : 'भावनाएँ',       'svc.emo.d'   : 'बच्चा भावनाओं को कैसे पहचानता और नियंत्रित करता है।',
    'svc.beh.t'     : 'व्यवहार',       'svc.beh.d'   : 'उम्र के अनुसार प्रश्नावली।',
    'svc.gen.t'     : 'सामान्य ज्ञान', 'svc.gen.d'   : '2 मिनट का अनुकूलित प्रश्नोत्तरी।',
    'svc.tal.t'     : 'विशेष प्रतिभा', 'svc.tal.d'   : 'कला, संगीत, संख्याओं, स्मृति में प्रतिभा।',
    'svc.spe.t'     : 'बोलना और आवाज़','svc.spe.d'   : 'AI धाराप्रवाहता का विश्लेषण करता है।',
    'svc.spo.t'     : 'स्वाभाविक अभिव्यक्ति','svc.spo.d': 'खुले प्रश्न का उत्तर।',
    'svc.math.t'    : 'गणित का स्तर',  'svc.math.d'  : 'अनुकूलित: आसान से शुरू होकर मूल स्तर तक।',
    'svc.lang.t'    : 'भाषा और पठन',   'svc.lang.d'  : 'शब्द-शक्ति और पठन गद्यांश।',
    'svc.par.t'     : 'पैरेंट इंडेक्स','svc.par.d'   : 'माता-पिता बच्चे को कितनी अच्छी तरह संवारते हैं।',
    'svc.diet.t'    : 'आहार सलाह',     'svc.diet.d'  : 'उम्र और स्थिति के अनुसार।',
    'how.heading'   : 'यह कैसे काम करता है',
    'how.s1.t' : 'WhatsApp से लॉगिन', 'how.s1.d': 'नंबर डालें, OTP सत्यापित करें।',
    'how.s2.t' : 'बच्चे को जोड़ें',   'how.s2.d': 'नाम, जन्म तिथि, स्कूल।',
    'how.s3.t' : 'मूल्यांकन करें',     'how.s3.d': '2–5 मिनट प्रति मॉड्यूल।',
    'how.s4.t' : 'AI रिपोर्ट प्राप्त करें', 'how.s4.d': 'ताक़त, चिंताएँ, अगले कदम।',
    'panel.heading' : 'हमारा बहु-विषयक विशेषज्ञ पैनल',
    'panel.subhead' : 'एक टीम जो बच्चे को संपूर्ण व्यक्तित्व के रूप में देखती है।',
    'panel.viewAll' : 'सभी देखें →',
    'cta.heading'   : 'शुरू करने के लिए तैयार हैं?',
    'cta.body'      : '20 मिनट का निवेश सालों के लिए मार्गदर्शन बदल सकता है।',

    'login.title'           : 'पैरेंट लॉगिन',
    'login.subtitle'        : 'अपना WhatsApp नंबर डालें — हम 6-अंकों का OTP भेजेंगे।',
    'login.phone.label'     : 'WhatsApp नंबर',
    'login.phone.ph'        : '98xxxxxxxx',
    'login.country'         : '+91',
    'login.send'            : 'OTP भेजें',
    'login.demo.note'       : 'डेमो मोड',
    'login.note.persistent' : '60 दिनों तक साइन-इन रहेंगे।',
    'login.otp.numberLabel' : 'नंबर:',
    'login.otp.label'       : '6-अंकों का OTP',
    'login.otp.name'        : 'आपका नाम',
    'login.otp.name.hint'   : '(पहली बार)',
    'login.otp.remember'    : '60 दिनों के लिए साइन-इन रखें',
    'login.otp.verify'      : 'सत्यापित करें और आगे बढ़ें',
    'login.otp.forgot'      : 'OTP नहीं मिला?',
    'login.otp.resend'      : 'OTP दोबारा भेजें',
    'login.otp.change'      : 'बदलें',

    'dash.greet'           : 'नमस्ते',
    'dash.intro.sentence'  : 'जारी रखने के लिए बच्चा चुनें।',
    'dash.intro.balance'   : 'आपके पास',
    'dash.add_child'       : '+ बच्चा जोड़ें',
    'dash.empty'           : 'अभी कोई बच्चा नहीं',
    'dash.empty.hint'      : 'मूल्यांकन शुरू करने के लिए बच्चे की प्रोफ़ाइल जोड़ें।',
    'dash.empty.cta'       : 'अपना पहला बच्चा जोड़ें',
    'dash.modules'         : 'मॉड्यूल पूरे',
    'dash.note'            : 'टीम से नोट:',
    'dash.markRead'        : 'पढ़ा हुआ चिह्नित करें',
    'dash.lastReport'      : 'पिछली AI रिपोर्ट',
    'dash.lastActivity'    : 'पिछली गतिविधि',
    'dash.notStarted'      : 'अभी तक कोई मूल्यांकन नहीं!',
    'dash.notStarted2'     : 'अभी शुरू नहीं किया',
    'dash.lastScore'       : 'पिछला स्कोर:',
    'dash.completed'       : 'पूर्ण',
    'dash.attempts'        : 'प्रयास',
    'dash.lockedToday'     : 'कल वापस आएँ',
    'dash.btn.open'        : '📂 खोलें',
    'dash.btn.report'      : '📋 AI रिपोर्ट',

    'addc.title'      : 'बच्चा जोड़ें',
    'addc.intro'      : 'एक छोटी प्रोफ़ाइल।',
    'addc.name'       : 'पूरा नाम',
    'addc.dob'        : 'जन्म तिथि',
    'addc.gender'     : 'लिंग',
    'addc.gender.m'   : 'लड़का',
    'addc.gender.f'   : 'लड़की',
    'addc.gender.o'   : 'अन्य',
    'addc.school'     : 'स्कूल',
    'addc.diagnosis'  : 'ज्ञात निदान',
    'addc.diag.ph'    : 'जैसे ADHD, ASD',
    'addc.notes'      : 'अन्य जानकारी',
    'addc.submit'     : 'सहेजें',
    'addc.cancel'     : 'रद्द करें',

    'child.back'    : '← सभी बच्चे',
    'child.modules' : 'मूल्यांकन मॉड्यूल',
    'child.report'  : 'पूरी AI रिपोर्ट',
    'child.start'   : 'शुरू करें',
    'child.redo'    : 'फिर से करें',
    'child.years'   : 'वर्ष',
    'child.summary' : 'पूर्ण',

    'wal.title'        : 'वॉलेट',
    'wal.balance'      : 'वर्तमान शेष',
    'wal.credits'      : 'क्रेडिट',
    'wal.note'         : '1 क्रेडिट = ₹1। नए पैरेंट्स को 100 मुफ़्त।',
    'wal.topup.title'  : 'टॉप-अप',
    'wal.bonus'        : 'बोनस',
    'wal.history'      : 'गतिविधि',
    'wal.empty'        : 'अभी तक कोई गतिविधि नहीं।',
    'wal.feedback'     : 'से:',
    'wal.need.prefix'  : 'आपको कम से कम',
    'wal.need.suffix'  : 'क्रेडिट चाहिए।',

    'about.title'     : 'एम्पावर स्टूडेंट्स के बारे में',
    'about.intro'     : 'बच्चे की क्षमताओं को पहचानने और उन्हें संवारने का मंच।',
    'about.mission.h' : 'हमारा मिशन',
    'about.mission.b' : 'हर बच्चे को पूरी क्षमता तक पहुँचने का मार्गदर्शन।',
    'about.story.h'   : 'हमारी कहानी',
    'about.story.b'   : 'AIIMS से प्रशिक्षित न्यूरोसर्जन डॉ. पी. के. झा द्वारा स्थापित।',
    'about.contact.h' : 'संपर्क',

    // ── Marketing landing page (m.*) — added for new index.php ──
    'm.eyebrow'      : 'ग्लोबल ऑटिज़्म लर्निंग स्कूल के सहयोग से',
    'm.h1.1'         : 'हर बच्चा अनोखा है।',
    'm.h1.2'         : 'हम आपके बच्चे की',
    'm.h1.3'         : 'क्षमता पहचानने में मदद करते हैं।',
    'm.sub'          : 'विशेषज्ञ देखभाल। व्यक्तिगत सहयोग। बेहतर विकास। उज्ज्वल भविष्य। बोलने, व्यवहार, सीखने और विकास संबंधी चिंताओं के लिए दिल्ली एनसीआर के माता-पिता का भरोसा।',
    'm.cta.eval'     : 'मुफ़्त मूल्यांकन पाएं',
    'm.cta.wa'       : 'WhatsApp करें',
    'm.cta.call'     : 'कॉल करें',
    'm.cta.wa.label' : '💬 WhatsApp 9311883132',
    'm.trust.1'      : '100% मुफ़्त मूल्यांकन',
    'm.trust.2'      : 'माता-पिता का भरोसा',
    'm.trust.3'      : 'विशेषज्ञ मार्गदर्शन',
    'm.trust.4'      : 'AIIMS-प्रशिक्षित',

    'm.badge.100'    : '100%',
    'm.badge.free'   : 'मुफ़्त',
    'm.badge.eval'   : 'मूल्यांकन',
    'm.form.title'   : 'आज ही पहला कदम बढ़ाएँ',
    'm.form.sub'     : '4 जानकारियाँ भरें — हम 2 घंटे में WhatsApp करेंगे।',
    'm.form.name'    : 'अभिभावक का नाम',
    'm.form.name.ph' : 'जैसे प्रिया शर्मा',
    'm.form.phone'   : 'WhatsApp नंबर',
    'm.form.phone.ph': '98xxxxxxxx',
    'm.form.age'     : 'बच्चे की उम्र',
    'm.form.age.ph'  : 'उम्र चुनें',
    'm.form.age.1'   : '0–2 वर्ष',
    'm.form.age.2'   : '2–4 वर्ष',
    'm.form.age.3'   : '5–7 वर्ष',
    'm.form.age.4'   : '8–10 वर्ष',
    'm.form.age.5'   : '11–14 वर्ष',
    'm.form.age.6'   : '15+ वर्ष',
    'm.form.concern'    : 'मुख्य चिंता',
    'm.form.concern.ph' : 'चिंता चुनें',
    'm.form.concern.1'  : 'बोलने / भाषा में देरी',
    'm.form.concern.2'  : 'व्यवहार / भावनात्मक',
    'm.form.concern.3'  : 'ऑटिज़्म / विकास संबंधी',
    'm.form.concern.4'  : 'सीखने में कठिनाई',
    'm.form.concern.5'  : 'ADHD / ध्यान की समस्या',
    'm.form.concern.6'  : 'संवेदी / गति संबंधी',
    'm.form.concern.7'  : 'पता नहीं — मार्गदर्शन चाहिए',
    'm.form.submit'  : 'मेरा मुफ़्त मूल्यांकन बुक करें',
    'm.form.privacy' : '🔒 कोई दबाव नहीं। कोई निर्णय नहीं। बस स्पष्टता।',

    'm.svc.heading'  : 'हम इन बच्चों की मदद करते हैं:',
    'm.fp.heading'  : '🎁 अपना मुफ़्त मॉड्यूल चुनें',
    'm.fp.sub'      : 'नीचे से कोई एक चुनें — मूल्यांकन, AI रिपोर्ट और 3 सलाह प्रश्न मुफ़्त में पाएँ। हर अभिभावक के लिए जीवन भर एक मुफ़्त मॉड्यूल। 12-सप्ताह की होम एक्शन योजना पूर्ण मॉड्यूल के साथ खुलती है।',
    'm.fp.foot'     : 'पहले से सदस्य हैं? पहले लॉग इन करें — हम आपको दावा करने के लिए वापस लाएँगे।',
    'm.fp.speech'   : 'भाषा और बोलचाल',
    'm.fp.speech.d' : 'पढ़कर सुनाना + खुली बातचीत। AI fluency, articulation, अभिव्यक्ति आँकता है।',
    'm.fp.math'     : 'गणित और संख्या-ज्ञान',
    'm.fp.math.d'   : 'AI द्वारा अनुकूलित quiz: number sense, problem-solving, कमज़ोर क्षेत्र।',
    'm.fp.beh'      : 'व्यवहार और भावनाएँ',
    'm.fp.beh.d'    : 'भावनात्मक नियंत्रण, meltdowns, सामाजिक कठिनाइयों की जाँच।',
    'm.fp.parent'   : 'पालन-पोषण शैली',
    'm.fp.parent.d' : 'अपनी शैली की ख़ूबियाँ और blind spots पहचानें, AI मार्गदर्शन के साथ।',
    'm.fp.lang'     : 'भाषा और पठन',
    'm.fp.lang.d'   : 'शब्दावली, समझ, व्याकरण — आयु-वर्ग के अनुसार AI रिपोर्ट।',
    'm.fp.diet'     : 'पारिवारिक स्वास्थ्य',
    'm.fp.diet.d'   : 'BMI, खानपान, नींद — भारतीय संदर्भ में सलाह।',
    'm.fp.ga'       : 'सामान्य ज्ञान',
    'm.fp.ga.d'     : '2-मिनट का adaptive quiz — current affairs, विज्ञान, भूगोल।',
    'm.fp.dev'      : 'विकास के पड़ाव',
    'm.fp.dev.d'    : 'आयु-अनुकूल milestone जाँच, AI insights के साथ।',
    'm.svc.sub'      : 'आपके बच्चे के विकास का संपूर्ण, संवेदनशील आकलन।',
    'm.svc.1'        : 'संवेदी और गति संबंधी कठिनाइयाँ',
    'm.svc.2'        : 'बोलने और भाषा की समस्याएँ',
    'm.svc.3'        : 'व्यवहार और भावनात्मक चिंताएँ',
    'm.svc.4'        : 'विकास में देरी',
    'm.svc.5'        : 'सीखने में कठिनाइयाँ',

    'm.why.heading'  : 'संवेदनशील। गोपनीय। बच्चे पर केंद्रित।',
    'm.why.1'        : 'योग्य पेशेवरों का विशेषज्ञ पैनल',
    'm.why.2'        : 'हर बच्चे के लिए व्यक्तिगत आकलन और देखभाल योजना',
    'm.why.3'        : 'टिकाऊ प्रगति के लिए सिद्ध सहायता',
    'm.why.4'        : 'AIIMS-प्रशिक्षित संस्थापक, 30+ वर्षों का अनुभव',
    'm.why.5'        : 'हिंदी और अंग्रेज़ी सहायता — हर परिवार के लिए सहज',
    'm.why.6'        : 'ग्लोबल ऑटिज़्म लर्निंग स्कूल के सहयोग से',

    'm.story.eyebrow': 'प्रिय अभिभावक,',
    'm.story.1'      : 'आप जानते हैं कि कुछ ठीक नहीं है… और यह ठीक है। शायद आपका बच्चा दूसरों की तरह नहीं बोलता। शायद वो आँख मिलाने से बचता है… या ध्यान केंद्रित नहीं कर पाता। और एक सवाल बार-बार आता है: <em>"क्या मैं कुछ चूक रहा हूँ?"</em>',
    'm.story.2'      : 'आप <strong>अकेले नहीं हैं</strong>। उलझन। चिंता। अपराधबोध। हर माता-पिता ने यही महसूस किया है। सच्चाई? सही समय पर मिली मदद सब कुछ बदल सकती है।',
    'm.story.3'      : '🌟 EmpowerStudents.in पर हम सिर्फ़ मूल्यांकन नहीं करते — हम आपको आपके बच्चे की ताकत, चुनौतियाँ और सही अगला कदम समझने में मदद करते हैं। <strong>कोई दबाव नहीं। कोई निर्णय नहीं। बस स्पष्टता।</strong>',

    'm.panel.eyebrow': 'हमारे विशेषज्ञ पैनल से मिलें',
    'm.panel.heading': 'असली डॉक्टर। असली देखभाल।',
    'm.panel.sub'    : 'एक बहु-विषयक टीम जो बच्चे को संपूर्ण व्यक्ति के रूप में देखती है।',
    'm.panel.viewAll': 'पूरा पैनल देखें →',

    'm.faq.heading'  : 'अक्सर पूछे जाने वाले प्रश्न',
    'm.faq.sub'      : 'संपर्क करने से पहले आप जो जानना चाहेंगे।',
    'm.faq.q1'       : 'क्या मूल्यांकन वाकई मुफ़्त है?',
    'm.faq.a1'       : 'हाँ — 100% मुफ़्त। मूल्यांकन के बाद किसी भी सशुल्क सेवा को जारी रखने की कोई बाध्यता नहीं है।',
    'm.faq.q2'       : 'मूल्यांकन में क्या होता है?',
    'm.faq.a2'       : 'हमारे विशेषज्ञ 30–45 मिनट का सत्र लेते हैं जिसमें बोलना, व्यवहार, गति-कौशल और सीखने का आकलन होता है। निष्कर्ष उसी दिन साझा किए जाते हैं।',
    'm.faq.q3'       : 'आप किस उम्र के बच्चों की मदद करते हैं?',
    'm.faq.a3'       : '2 से 14 वर्ष तक के बच्चे। 2 साल से छोटे बच्चों के लिए कोमल विकासात्मक स्क्रीनिंग।',
    'm.faq.q4'       : 'क्या आप ऑटिज़्म, ADHD, सीखने की कठिनाई का इलाज करते हैं?',
    'm.faq.a4'       : 'हम मूल्यांकन करते हैं और हमारे स्पीच थेरेपिस्ट, ऑक्यूपेशनल थेरेपिस्ट, बाल रोग विशेषज्ञ और मनोवैज्ञानिक के साथ व्यक्तिगत सहायता योजना बनाते हैं।',
    'm.faq.q5'       : 'क्या सब कुछ गोपनीय है?',
    'm.faq.a5'       : 'बिल्कुल। सभी रिकॉर्ड निजी हैं और केवल माता-पिता के साथ साझा किए जाते हैं।',
    'm.faq.q6'       : 'आप कहाँ स्थित हैं? क्या ऑनलाइन कर सकते हैं?',
    'm.faq.a6'       : 'हम ग्रेटर नोएडा (गौर सिटी 1) में हैं और पूरे दिल्ली NCR में सेवा देते हैं। पूरे भारत के लिए टेली-कंसल्ट उपलब्ध है।',

    'm.cta.script'   : 'मिलकर, आइए आपके बच्चे की असली क्षमता को उजागर करें।',
    'm.cta.heading'  : 'आज ही पहला कदम उठाएँ।',
    'm.cta.body'     : 'क्योंकि आज मिली सही सहायता एक बेहतर कल बना सकती है।',

    // ── Homepage v2 additions: catalogue CTA, 2-row services, testimonials ──
    'm.cta.modules'      : 'मॉड्यूल देखें',
    'm.svc.row1'         : 'विशेष ज़रूरत वाले बच्चे',
    'm.svc.row2'         : 'सभी बच्चे',
    'm.svc.6'            : 'गणित स्तर और प्रतिभा',
    'm.svc.7'            : 'भाषा और पढ़ना',
    'm.svc.8'            : 'सामान्य ज्ञान',
    'm.svc.9'            : 'मस्तिष्क शक्ति और एकाग्रता',
    'm.svc.10'           : 'विशेष प्रतिभा और योग्यता',
    'm.svc.cta.heading'  : 'सिर्फ़ उतना भुगतान करें जितनी ज़रूरत हो।',
    'm.svc.cta.sub'      : 'हर मॉड्यूल में अपना मूल्यांकन, AI रिपोर्ट और व्यक्तिगत योजना। बंडल में 35% तक की बचत।',
    'm.svc.cta.btn'      : 'सभी मॉड्यूल देखें',

    'm.testi.eyebrow'    : 'अभिभावकों की आवाज़',
    'm.testi.heading'    : 'चिंता से राहत तक।',
    'm.testi.sub'        : 'पहला कदम उठाने वाले अभिभावकों के कुछ शब्द।',
    'm.testi.note'       : 'गोपनीयता के लिए नाम बदले गए हैं। हर परिवार से अनुमति लेकर साझा किए गए।',
    'm.testi.1.body'     : 'मेरा बेटा 3 साल का था और मुश्किल से 5 शब्द बोलता था। हम बहुत डरे हुए थे। स्पीच प्लान शुरू करने के 2 हफ़्तों के अंदर वो ख़ुद से चीज़ों के नाम लेने लगा। डॉ. झा की टीम धैर्यवान है और हमें कभी जजमेंटल महसूस नहीं कराया।',
    'm.testi.1.name'     : 'प्रिया एस.',
    'm.testi.1.ctx'      : '3 साल के बच्चे की माँ · ग्रेटर नोएडा',
    'm.testi.2.body'     : 'हमें बताया गया था कि हमारी बेटी स्कूल में "बस आलसी" है। लर्निंग डिफ़िकल्टी असेसमेंट में dyslexia के साफ़ संकेत मिले। अब उसके पास एक असली योजना है और वो होमवर्क पर रोना बंद कर चुकी है। काश हम साल भर पहले आए होते।',
    'm.testi.2.name'     : 'राजेश एम.',
    'm.testi.2.ctx'      : '8 साल के बच्चे के पिता · नोएडा',
    'm.testi.3.body'     : 'मैं अकेली माँ हूँ और ख़र्च की चिंता थी। मूल्यांकन मुफ़्त था और मैं सिर्फ़ ज़रूरी मॉड्यूल (₹399 प्रत्येक) चुन सकती थी — इसने सब फ़र्क़ डाला। ईमानदार, बिना कोई दबाव।',
    'm.testi.3.name'     : 'कविता वी.',
    'm.testi.3.ctx'      : '6 साल के बच्चे की माँ · ग़ाज़ियाबाद',
    'm.testi.4.body'     : 'हिंदी सपोर्ट मेरी सास के लिए वरदान साबित हुआ जो मेरे बेटे को स्कूल के बाद संभालती हैं। वो हिंदी प्लान पढ़कर रोज़ की 5-मिनट गतिविधियाँ कर लेती हैं। छोटी सी बात, बड़ा फ़र्क़।',
    'm.testi.4.name'     : 'अंजली के.',
    'm.testi.4.ctx'      : '5 साल के बच्चे की माँ · फ़रीदाबाद',
    'm.testi.5.body'     : 'व्यवहार मॉड्यूल ने हमें मेल्टडाउन्स को संकेत के रूप में देखना सिखाया, बुरा व्यवहार नहीं। AI consults से मैं रात 11 बजे भी सवाल पूछ सकती हूँ — अगली अपॉइंटमेंट का इंतज़ार नहीं करना पड़ता। एक-एक रुपये की क़ीमत वसूल।',
    'm.testi.5.name'     : 'सुरेश एन.',
    'm.testi.5.ctx'      : '7 साल के बच्चे के पिता · गुरुग्राम',
    'm.testi.6.body'     : 'हमने General Awareness और Math मॉड्यूल किए ये देखने के लिए कि मेरी बेटी असल में कहाँ है — स्कूल के अंक क्या कहते हैं उससे अलग। ईमानदार रिपोर्ट ने उसके हफ़्ते की प्लानिंग बदल दी। एक भरोसेमंद दूसरी राय।',
    'm.testi.6.name'     : 'मीरा डी.',
    'm.testi.6.ctx'      : '10 साल के बच्चे की माँ · दिल्ली',

    // ── About page (ab.*) — full Hindi for redesigned about.php ──
    'ab.eyebrow'         : 'ग्लोबल ऑटिज़्म लर्निंग स्कूल के सहयोग से',
    'ab.h1.1'            : 'आपके बच्चे का 360° दृश्य —',
    'ab.h1.2'            : 'जल्दी लिया गया, सालों तक अपडेट होता रहे।',
    'ab.lead'            : 'EmpowerStudents.in एक बहु-विषयक विशेषज्ञ पैनल और आधुनिक AI टूल्स को साथ लाता है, ताकि आप अपने बच्चे को समझ सकें — उनकी ताक़त, संघर्ष, और घर पर उन्हें कैसे संवारें।',

    'ab.mission.eyebrow' : 'हमने यह क्यों बनाया',
    'ab.mission.h'       : 'पूरी तस्वीर, सिर्फ़ एक झलक नहीं।',
    'ab.mission.b1'      : 'अधिकांश बच्चों का मूल्यांकन तभी होता है जब कुछ ग़लत हो जाए — और तब भी, एक समय में सिर्फ़ एक पहलू पर।',
    'ab.mission.b2'      : 'हम मानते हैं कि एक 360° तस्वीर — जो जल्दी ली जाए और सालों तक अपडेट होती रहे — किसी बच्चे और उसके माता-पिता को दिया जा सकने वाला सबसे प्यारा उपहार है। चाहे आपकी चिंता बोलना हो, व्यवहार हो, सीखना हो, या बस यह कि <em>"क्या मेरा बच्चा सही दिशा में है?"</em> — हमारा प्लेटफ़ॉर्म आपको साफ़ देखने में मदद करता है — बिना घबराहट, बिना दबाव।',

    'ab.diff.eyebrow'    : 'हम क्यों अलग हैं',
    'ab.diff.h'          : 'छह बातें जो माता-पिता सबसे ज़्यादा सराहते हैं।',
    'ab.diff.1.h'        : 'उम्र के अनुसार अनुकूलित',
    'ab.diff.1.b'        : 'हर मॉड्यूल आपके बच्चे की उम्र के अनुसार ढलता है। 2 साल से छोटे बच्चों के लिए हम सूक्ष्म व्यवहार संकेतकों पर ध्यान देते हैं ताकि शुरुआती ASD या सीखने की चिंताएँ छूट न जाएँ।',
    'ab.diff.2.h'        : 'अनुकूलनशील परीक्षण',
    'ab.diff.2.b'        : 'गणित, सामान्य ज्ञान और भाषा के क्विज़ बहुत आसान शुरू होते हैं और तभी कठिन होते हैं जब उत्तर सही और तेज़ हों — हम बच्चे का सहज स्तर ढूँढते हैं, कभी अभिभूत नहीं करते।',
    'ab.diff.3.h'        : 'सुनने वाली स्पीच',
    'ab.diff.3.b'        : 'आपका बच्चा छोटे वाक्य पढ़ता है और एक खुले सवाल का जवाब देता है। AI fluency, articulation, आत्मविश्वास और हकलाहट को आँकता है — किसी हाँ/नहीं चेकलिस्ट से कहीं ज़्यादा उपयोगी।',
    'ab.diff.4.h'        : 'अभिभावक-मित्रवत, बिना निर्णय',
    'ab.diff.4.b'        : 'आप अभी अपने बच्चे को कैसे समझ रहे हैं और संवार रहे हैं — इसका एक नर्म आकलन, ठोस अगले क़दमों के साथ। माता-पिता के लिए कोई ग्रेड नहीं — बस स्पष्टता और साथ।',
    'ab.diff.5.h'        : 'द्विभाषी — हिंदी और English',
    'ab.diff.5.b'        : 'रिपोर्ट, योजनाएँ और AI consults — सब हिंदी में भी, ताकि पूरा परिवार (दादा-दादी सहित) साथ चल सके।',
    'ab.diff.6.h'        : 'सिर्फ़ ज़रूरत भर का भुगतान',
    'ab.diff.6.b'        : 'अलग-अलग मॉड्यूल ₹199 से चुनें। बंडल में 35% तक की बचत। पहला मूल्यांकन हमेशा मुफ़्त। कोई सब्सक्रिप्शन नहीं, कोई छुपा शुल्क नहीं।',

    'ab.cta.h'           : 'देखें कि आपके बच्चे के लिए क्या सही है?',
    'ab.cta.sub'         : 'पूरा मॉड्यूल कैटलॉग देखें, या मुफ़्त मूल्यांकन से शुरुआत करें।',
    'ab.cta.modules'     : 'मॉड्यूल देखें',
    'ab.cta.eval'        : 'मुफ़्त मूल्यांकन पाएँ',

    'ab.story.eyebrow'   : 'हमारी कहानी',
    'ab.story.h'         : 'एक चिकित्सक द्वारा, अभिभावकों के लिए बनाया गया।',
    'ab.story.name'      : 'डॉ. पी. के. झा',
    'ab.story.role'      : 'संस्थापक · निदेशक · न्यूरोसर्जन',
    'ab.story.b1'        : 'AIIMS-प्रशिक्षित न्यूरोसर्जन, 30+ वर्षों का क्लिनिकल अनुभव — न्यूरोलॉजी, बाल विकास और परिवारिक देखभाल में। दशकों तक माता-पिता को बहुत देर से आते देखने के बाद — जब समस्याएँ बढ़ चुकी होती थीं — डॉ. झा ने EmpowerStudents.in की स्थापना की, ताकि हर भारतीय परिवार को जल्दी, समग्र, और किफ़ायती मूल्यांकन मिल सके।',
    'ab.story.b2'        : 'यह प्लेटफ़ॉर्म क्लिनिक में सीखे गए अनुभव को AI के धैर्य और निरंतरता के साथ जोड़ता है — ताकि हर अभिभावक को वही गहराई मिले, चाहे वो कहीं भी रहता हो।',

    'ab.cred.1'          : 'M.Ch (AIIMS)',
    'ab.cred.2'          : '30+ वर्ष',
    'ab.cred.3'          : 'न्यूरो केयर इंडिया',
    'ab.cred.4'          : 'ग्रेटर नोएडा',

    'ab.contact.eyebrow' : 'संपर्क करें',
    'ab.contact.h'       : 'हम बस एक संदेश दूर हैं।',
    'ab.contact.b'       : 'पार्टनरशिप, अभिभावक पूछताछ, या मुफ़्त मूल्यांकन बुक करने के लिए — नीचे किसी भी माध्यम से संपर्क करें।',
    'ab.contact.call'    : 'कॉल',
    'ab.contact.wa'      : 'WhatsApp',
    'ab.contact.email'   : 'ईमेल',
  }
};

(function() {
  const KEY = 'es_lang';
  function applyLang(lang) {
    const dict = (lang === 'hi') ? (window.ES_I18N.hi || {}) : null;
    document.documentElement.lang = lang;
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      if (!el.dataset.i18nOriginal) el.dataset.i18nOriginal = el.innerHTML;
      el.innerHTML = (dict && dict[key]) ? dict[key] : el.dataset.i18nOriginal;
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      if (!el.dataset.i18nPhOriginal) el.dataset.i18nPhOriginal = el.placeholder;
      el.placeholder = (dict && dict[key]) ? dict[key] : el.dataset.i18nPhOriginal;
    });
    document.querySelectorAll('.lang-toggle button').forEach(b => {
      b.classList.toggle('active', b.dataset.setLang === lang);
    });
    try { localStorage.setItem(KEY, lang); } catch(_){}
  }
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => applyLang(b.dataset.setLang));
  });
  let initial = 'en';
  try { initial = localStorage.getItem(KEY) || 'en'; } catch(_){}
  applyLang(initial);
})();
</script>
