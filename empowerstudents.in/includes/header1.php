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
