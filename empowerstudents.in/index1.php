<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Home';
// Show the full panel on the homepage (8 specialists → 2 rows of 4 on desktop)
$specialists = db()->query('SELECT * FROM specialists WHERE active=1 ORDER BY order_no ASC LIMIT 8')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<!-- Playful display fonts (homepage only) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Caveat:wght@600&display=swap" rel="stylesheet">

<style>
  /* ── Homepage-only theme ──────────────────────────────────────────── */
  :root {
    --paper:    #fef7e7;
    --paper-2:  #fdf0d3;
    --ink:      #2a2440;
    --ink-soft: #5a536e;
    --coral:    #ff8b73;
    --peach:    #ffd0a8;
    --marigold: #ffc857;
    --mint:     #a8e6cf;
    --teal:     #7dd3c0;
    --sky:      #aed8f3;
    --lavender: #c8b8ff;
    --plum:     #b39bff;
  }
  body { background: var(--paper) !important; color: var(--ink); }
  .es-display { font-family: 'Fredoka', system-ui, sans-serif; letter-spacing: -0.01em; }
  .es-script  { font-family: 'Caveat', cursive; font-weight: 600; }

  /* ── SPLASH LOADER (first visit per session) ─────────────────────── */
  .es-splash {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; align-items: center; justify-content: center;
    background:
      radial-gradient(700px 500px at 30% 30%, rgba(255, 200, 87, 0.45), transparent 65%),
      radial-gradient(700px 500px at 70% 70%, rgba(168, 230, 207, 0.45), transparent 65%),
      linear-gradient(135deg, #fef7e7 0%, #fdf0d3 100%);
    animation: splashFade 2s ease-in-out forwards;
  }
  .es-splash img {
    width: 320px; max-width: 60vw; height: auto;
    filter: drop-shadow(0 20px 40px rgba(255, 139, 115, 0.25));
    animation: splashZoom 1.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  }
  @keyframes splashZoom {
    0%   { transform: scale(0.35) rotate(-6deg); opacity: 0; }
    35%  { opacity: 1; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
  }
  @keyframes splashFade {
    0%, 80% { opacity: 1; }
    100%    { opacity: 0; visibility: hidden; pointer-events: none; }
  }
  .es-splash::before, .es-splash::after {
    content: ""; position: absolute; border-radius: 50%;
    background: var(--coral); opacity: 0.5;
    animation: bob 2s ease-in-out infinite;
  }
  .es-splash::before { width: 16px; height: 16px; top: 28%; left: 22%; }
  .es-splash::after  { width: 12px; height: 12px; bottom: 30%; right: 24%; background: var(--plum); animation-delay: .3s; }
  @keyframes bob { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }

  /* ── HERO ─────────────────────────────────────────────────────────── */
  .es-hero {
    position: relative; overflow: hidden; border-radius: 32px;
    padding: 56px 40px 72px;
    background:
      radial-gradient(1200px 500px at 110% -10%, rgba(255, 200, 87, 0.55), transparent 60%),
      radial-gradient(900px 600px at -10% 110%, rgba(168, 230, 207, 0.65), transparent 60%),
      radial-gradient(700px 500px at 50% 50%,   rgba(255, 192, 216, 0.35), transparent 70%),
      linear-gradient(135deg, #ffb088 0%, #ffd28e 28%, #b3e0c9 65%, #aed8f3 100%);
    box-shadow: 0 30px 60px -30px rgba(255, 139, 115, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.6);
  }
  .es-hero-grid {
    position: relative; z-index: 10;
    display: grid; grid-template-columns: 1fr; gap: 32px;
    align-items: center;
  }
  @media (min-width: 1024px) {
    .es-hero-grid { grid-template-columns: 1.3fr 1fr; gap: 40px; }
  }
  .es-hero-logo {
    display: flex; justify-content: center; align-items: center;
    position: relative;
  }
  .es-hero-logo img {
    width: 100%; max-width: 380px; height: auto;
    filter: drop-shadow(0 24px 40px rgba(80, 50, 100, 0.18))
            drop-shadow(0 8px 16px rgba(255, 139, 115, 0.18));
    animation: float-logo 7s ease-in-out infinite;
  }
  @keyframes float-logo {
    0%, 100% { transform: translateY(0) rotate(-1deg); }
    50%      { transform: translateY(-14px) rotate(1deg); }
  }
  .es-hero-logo::before {
    content: ""; position: absolute;
    width: 360px; height: 360px; max-width: 90%;
    aspect-ratio: 1; border-radius: 50%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.55), transparent 70%);
    z-index: -1;
  }
  .squiggle {
    display: inline-block;
    background: linear-gradient(180deg, transparent 75%, rgba(255, 200, 87, 0.85) 75%);
    padding: 0 6px; border-radius: 4px;
  }
  .es-cta-primary {
    display: inline-flex; align-items: center; gap: 10px;
    background: var(--ink); color: white;
    padding: 14px 24px; border-radius: 999px;
    font-weight: 600; font-size: 16px;
    box-shadow: 0 6px 0 rgba(0,0,0,0.18), 0 12px 24px rgba(255,139,115,0.4);
    transition: transform .15s ease, box-shadow .15s ease;
  }
  .es-cta-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 0 rgba(0,0,0,0.18), 0 16px 28px rgba(255,139,115,0.5); }
  .es-cta-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    background: white; color: var(--ink);
    padding: 14px 24px; border-radius: 999px; font-weight: 600;
    border: 2px solid rgba(42,36,64,0.12);
    transition: all .15s ease;
  }
  .es-cta-secondary:hover { border-color: var(--ink); transform: translateY(-2px); }

  /* ── Doodles ──────────────────────────────────────────────────────── */
  .es-doodle { position: absolute; pointer-events: none; z-index: 2; }
  @keyframes float-slow { 0%,100% { transform: translateY(0) rotate(var(--r,0deg)); } 50% { transform: translateY(-12px) rotate(calc(var(--r,0deg) + 4deg)); } }
  @keyframes float-mid  { 0%,100% { transform: translateY(0) rotate(var(--r,0deg)); } 50% { transform: translateY(-8px)  rotate(calc(var(--r,0deg) - 6deg)); } }
  .float-slow { animation: float-slow 6s ease-in-out infinite; }
  .float-mid  { animation: float-mid 4.5s ease-in-out infinite; }

  /* ── Service cards (rainbow pastel) ──────────────────────────────── */
  .es-card {
    position: relative; border-radius: 28px;
    padding: 26px 22px 22px;
    border: 2px solid rgba(42,36,64,0.06);
    box-shadow: 0 4px 0 rgba(42,36,64,0.06), 0 12px 28px -16px rgba(42,36,64,0.18);
    transition: transform .18s ease, box-shadow .18s ease;
  }
  .es-card:hover { transform: translateY(-4px) rotate(-0.4deg); box-shadow: 0 6px 0 rgba(42,36,64,0.08), 0 18px 36px -16px rgba(42,36,64,0.24); }
  .es-card .es-emoji {
    display: inline-flex; align-items: center; justify-content: center;
    width: 56px; height: 56px; border-radius: 18px;
    background: rgba(255,255,255,0.7); font-size: 28px;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.9), 0 2px 0 rgba(42,36,64,0.05);
    margin-bottom: 14px;
  }
  .es-card h3 { font-family: 'Fredoka', system-ui, sans-serif; font-weight: 600; font-size: 18px; color: var(--ink); margin-bottom: 4px; }
  .es-card p  { color: var(--ink-soft); font-size: 14px; line-height: 1.5; }
  .bg-blush    { background: linear-gradient(155deg, #ffd9e3, #ffc1d8); }
  .bg-lavender { background: linear-gradient(155deg, #e0d4ff, #c8b8ff); }
  .bg-sun      { background: linear-gradient(155deg, #fff0c2, #ffd989); }
  .bg-mint     { background: linear-gradient(155deg, #d4f5e5, #a8e6cf); }
  .bg-sky      { background: linear-gradient(155deg, #d6ecf8, #aed8f3); }
  .bg-peach    { background: linear-gradient(155deg, #ffe2c9, #ffc7a0); }
  .bg-coral    { background: linear-gradient(155deg, #ffc6b9, #ffa088); }
  .bg-aqua     { background: linear-gradient(155deg, #c8efe6, #91dccb); }
  .bg-blue     { background: linear-gradient(155deg, #cfe1f5, #a3c7e8); }
  .bg-violet   { background: linear-gradient(155deg, #ddd2f5, #b8a4ea); }
  .bg-sand     { background: linear-gradient(155deg, #fbebcb, #f6dba8); }
  .bg-grass    { background: linear-gradient(155deg, #dceec0, #b6d792); }
  .bg-gold     { background: linear-gradient(155deg, #fff4d6, #ffe1a8); }

  /* ── How it works ─────────────────────────────────────────────────── */
  .es-step {
    position: relative; background: white; border-radius: 24px; padding: 24px 22px;
    border: 2px solid rgba(42,36,64,0.06);
    box-shadow: 0 4px 0 rgba(42,36,64,0.04);
  }
  .es-step.step-1 { transform: rotate(-1deg); }
  .es-step.step-2 { transform: rotate(0.6deg); }
  .es-step.step-3 { transform: rotate(-0.4deg); }
  .es-step.step-4 { transform: rotate(0.8deg); }
  .es-step-num {
    width: 48px; height: 48px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Fredoka', sans-serif; font-weight: 700; font-size: 22px;
    color: white; margin-bottom: 14px;
    box-shadow: 0 4px 0 rgba(42,36,64,0.1);
  }
  .step-1 .es-step-num { background: var(--coral); }
  .step-2 .es-step-num { background: var(--marigold); color: var(--ink); }
  .step-3 .es-step-num { background: var(--teal); }
  .step-4 .es-step-num { background: var(--plum); }

  /* ── Panel cards ──────────────────────────────────────────────────── */
  .es-panel-card {
    position: relative;
    background: white; border-radius: 24px; overflow: hidden;
    border: 2px solid rgba(42,36,64,0.06);
    box-shadow: 0 4px 0 rgba(42,36,64,0.04);
    transition: transform .15s ease;
  }
  .es-panel-card:hover { transform: translateY(-4px); }

  /* Director / Founder highlight */
  .es-panel-card.es-founder {
    border-color: var(--marigold);
    box-shadow: 0 6px 0 rgba(255, 200, 87, 0.45),
                0 18px 36px -16px rgba(255, 139, 115, 0.35);
  }
  .es-founder-badge {
    position: absolute; top: 12px; right: 12px; z-index: 5;
    background: var(--marigold); color: var(--ink);
    padding: 5px 12px; border-radius: 999px;
    font-family: 'Fredoka', sans-serif; font-size: 11px; font-weight: 700;
    letter-spacing: 0.06em; text-transform: uppercase;
    box-shadow: 0 2px 0 rgba(42, 36, 64, 0.18);
  }

  /* ── Final CTA ────────────────────────────────────────────────────── */
  .es-cta-band {
    border-radius: 32px; padding: 56px 32px;
    background:
      radial-gradient(800px 400px at 0% 0%,    rgba(255,200,184,0.6), transparent 60%),
      radial-gradient(700px 400px at 100% 100%, rgba(184,164,234,0.55), transparent 60%),
      linear-gradient(135deg, #ff9a7e, #ffc857 50%, #c8b8ff);
    color: var(--ink); text-align: center;
    box-shadow: 0 30px 60px -30px rgba(255, 139, 115, 0.4);
    position: relative; overflow: hidden;
  }
  .es-section-eyebrow {
    display: inline-block;
    background: white; padding: 6px 14px; border-radius: 999px;
    font-size: 12px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--ink); margin-bottom: 12px;
    border: 2px solid rgba(42,36,64,0.08);
  }
</style>

<!-- ─────────────────────  SPLASH LOADER  ───────────────────── -->
<div class="es-splash" id="esSplash">
  <img src="/assets/images/logo.png" alt="Empower Students">
</div>

<!-- ─────────────────────  HERO  ───────────────────── -->
<section class="es-hero mb-14">
  <!-- Floating doodles -->
  <svg class="es-doodle float-slow" style="top: 24px; right: 4%; --r: -8deg" width="58" height="58" viewBox="0 0 68 68" fill="none">
    <circle cx="34" cy="34" r="14" fill="#FFC857"/>
    <g stroke="#FFC857" stroke-width="3" stroke-linecap="round">
      <line x1="34" y1="6"  x2="34" y2="14"/><line x1="34" y1="54" x2="34" y2="62"/>
      <line x1="6"  y1="34" x2="14" y2="34"/><line x1="54" y1="34" x2="62" y2="34"/>
      <line x1="14" y1="14" x2="20" y2="20"/><line x1="48" y1="48" x2="54" y2="54"/>
      <line x1="14" y1="54" x2="20" y2="48"/><line x1="48" y1="20" x2="54" y2="14"/>
    </g>
  </svg>
  <svg class="es-doodle float-mid" style="top: 50px; left: 48%; --r: 6deg" width="74" height="44" viewBox="0 0 84 50" fill="none">
    <path d="M16 38 C8 38 4 32 8 26 C4 18 14 12 22 18 C24 8 38 6 42 16 C50 10 64 14 64 24 C72 22 78 28 76 36 C76 42 70 44 64 42 L20 42 C18 42 16 40 16 38 Z" fill="white" fill-opacity="0.85"/>
  </svg>
  <svg class="es-doodle float-slow" style="bottom: 20px; right: 6%; --r: 12deg" width="40" height="40" viewBox="0 0 44 44" fill="none">
    <path d="M22 4 L26.6 16.4 L40 17 L29.6 25.4 L33 38 L22 31 L11 38 L14.4 25.4 L4 17 L17.4 16.4 Z" fill="#FF8B73" stroke="#2a2440" stroke-width="2" stroke-linejoin="round"/>
  </svg>
  <svg class="es-doodle float-mid" style="bottom: 60px; left: 52%; --r: -10deg" width="52" height="52" viewBox="0 0 60 60" fill="none">
    <ellipse cx="30" cy="22" rx="18" ry="20" fill="#C8B8FF" stroke="#2a2440" stroke-width="2"/>
    <path d="M27 42 L30 46 L33 42 Z" fill="#2a2440"/>
    <path d="M30 46 Q26 52 30 58" stroke="#2a2440" stroke-width="1.5" fill="none" stroke-linecap="round"/>
  </svg>
  <svg class="es-doodle float-slow" style="top: 40%; right: 38%; --r: 4deg" width="36" height="36" viewBox="0 0 40 40" fill="none">
    <path d="M20 4 L23.5 14.5 L34 16 L26 23.5 L28 34 L20 28.5 L12 34 L14 23.5 L6 16 L16.5 14.5 Z" fill="#FFC857"/>
  </svg>
  <svg class="es-doodle" style="bottom: 24px; left: 4%; transform: rotate(-12deg)" width="100" height="40" viewBox="0 0 100 40" fill="none">
    <path d="M5 20 Q15 5 25 20 T 45 20 T 65 20 T 85 20 T 100 20" stroke="#FF8B73" stroke-width="3" stroke-linecap="round" fill="none"/>
  </svg>

  <!-- Two-column hero: text + logo -->
  <div class="es-hero-grid">
    <div>
      <p class="es-script text-xl mb-2" style="color: var(--coral)" data-i18n="hero.eyebrow">Empower Students</p>
      <h1 class="es-display text-4xl sm:text-6xl font-bold leading-[1.05] mb-5">
        <span data-i18n="hero.title.1">Understand your child &mdash; in</span>
        <span class="squiggle" data-i18n="hero.title.2">every dimension</span>.
      </h1>
      <p class="text-base sm:text-lg mb-7 max-w-xl" style="color: var(--ink-soft)" data-i18n="hero.subtitle">
        A friendly 360&deg; assessment of your child&rsquo;s health, mind, emotions, behaviour, language,
        maths, speech, special talent and parent connect &mdash; calibrated to age, with an AI-assisted report
        and recommendations from our multi-disciplinary panel.
      </p>
      <div class="flex flex-wrap gap-3">
        <a href="/login.php" class="es-cta-primary">
          <span data-i18n="cta.start">Start free assessment</span>
          <span style="font-size: 18px">→</span>
        </a>
        <a href="/specialists.php" class="es-cta-secondary" data-i18n="cta.meet">Meet the panel</a>
      </div>
    </div>

    <div class="es-hero-logo">
      <img src="/assets/images/logo.png" alt="Empower Students — Learn, Grow, Lead, Inspire">
    </div>
  </div>
</section>

<!-- ─────────────────────  SERVICES  ───────────────────── -->
<section class="mb-16">
  <div class="text-center mb-10">
    <span class="es-section-eyebrow">12 modules</span>
    <h2 class="es-display text-3xl sm:text-4xl font-bold mt-2 mb-3" data-i18n="svc.heading">A complete child assessment, calibrated to age</h2>
    <p class="max-w-2xl mx-auto" style="color: var(--ink-soft)" data-i18n="svc.subhead">Each module starts easy and adapts to your child&rsquo;s level so we never under- or over-stretch them. Subtle developmental markers are included for under-2s so early signs are not missed.</p>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php
    $services = [
      ['💗','Health screening',     'Growth, sleep, nutrition, sensory and milestone red-flags appropriate to age.', 'svc.health', 'bg-blush'   ],
      ['🧠','Mind power',           'Working memory, attention span, problem-solving &mdash; quick adaptive tasks.',   'svc.mind',   'bg-lavender'],
      ['😊','Emotions',             'How the child names, regulates and expresses feelings.',                        'svc.emo',    'bg-sun'     ],
      ['🧩','Behaviour',            'Age-appropriate questionnaire. For under-2s, subtle ASD &amp; learning markers (M-CHAT inspired).', 'svc.beh', 'bg-mint'   ],
      ['🌍','General awareness',    '2-minute adaptive quiz that finds your child&rsquo;s comfortable level.',         'svc.gen',    'bg-sky'     ],
      ['⭐','Special talent',       'Identify gifts in art, music, numbers, memory, mechanics &mdash; common in ASD &amp; ADHD.', 'svc.tal',  'bg-peach'  ],
      ['🎤','Speech &amp; voice',   'Read short sentences. AI analyses fluency, tone, confidence and stuttering.',    'svc.spe',    'bg-coral'   ],
      ['💬','Spontaneous expression','Open-ended question. AI scores content, fluency, confidence.',                  'svc.spo',    'bg-aqua'    ],
      ['🔢','Maths level',          'Adaptive: starts very easy, climbs (or steps down) to find the base level.',     'svc.math',   'bg-blue'    ],
      ['📚','Language &amp; reading','Word-power and a timed comprehension passage with MCQs.',                       'svc.lang',   'bg-violet'  ],
      ['👪','Parent index',         'How well the parent understands &amp; nurtures the child &mdash; what to focus on.', 'svc.par', 'bg-sand'   ],
      ['🥗','Diet advice',          'Tailored to age, nature and any morbidity &mdash; reviewed by our paediatrician.','svc.diet',  'bg-grass'   ],
    ];
    foreach ($services as $s): ?>
      <div class="es-card <?= $s[4] ?>">
        <div class="es-emoji"><?= $s[0] ?></div>
        <h3 data-i18n="<?= $s[3] ?>.t"><?= $s[1] ?></h3>
        <p data-i18n="<?= $s[3] ?>.d"><?= $s[2] ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ─────────────────────  HOW IT WORKS  ───────────────────── -->
<section class="mb-16">
  <div class="text-center mb-10">
    <span class="es-section-eyebrow">In four small steps</span>
    <h2 class="es-display text-3xl sm:text-4xl font-bold mt-2" data-i18n="how.heading">How it works</h2>
  </div>
  <div class="grid md:grid-cols-4 gap-5">
    <?php
    $steps = [
      ['1','Login with WhatsApp','Enter your number, verify the OTP &mdash; no password to remember.',      'how.s1'],
      ['2','Add your child','A short profile: name, date of birth, school, any known diagnosis.',          'how.s2'],
      ['3','Run the assessments','Pick modules at your pace. Most take 2&ndash;5 minutes each.',           'how.s3'],
      ['4','Get the AI report','See strengths, concerns and next steps &mdash; with our panel close at hand.', 'how.s4'],
    ];
    foreach ($steps as $st): ?>
      <div class="es-step step-<?= $st[0] ?>">
        <div class="es-step-num"><?= $st[0] ?></div>
        <h3 class="es-display text-lg font-semibold mb-1" style="color: var(--ink)" data-i18n="<?= $st[3] ?>.t"><?= $st[1] ?></h3>
        <p class="text-sm" style="color: var(--ink-soft)" data-i18n="<?= $st[3] ?>.d"><?= $st[2] ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ─────────────────────  PANEL PREVIEW (8 cards · 2 rows × 4)  ───────────────────── -->
<section class="mb-16">
  <div class="flex items-end justify-between mb-8 flex-wrap gap-3">
    <div>
      <span class="es-section-eyebrow">Real humans, not just AI</span>
      <h2 class="es-display text-3xl sm:text-4xl font-bold mt-2" data-i18n="panel.heading">Our multi-disciplinary panel</h2>
      <p style="color: var(--ink-soft)" data-i18n="panel.subhead">A team that sees your child as a whole person.</p>
    </div>
    <a href="/specialists.php" class="es-script text-xl" style="color: var(--coral)" data-i18n="panel.viewAll">View all &rarr;</a>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <?php
      // 8-tint rotation so each card has its own colour identity.
      // Director gets the gold tint regardless of position.
      $tints = ['bg-blush','bg-mint','bg-sky','bg-lavender','bg-peach','bg-aqua','bg-violet','bg-sun'];
      foreach ($specialists as $i => $sp):
        $is_founder = ($sp['photo'] === 'director.png') || stripos($sp['role'], 'director') !== false;
        $tint  = $is_founder ? 'bg-gold' : $tints[$i % count($tints)];
        $card_classes = 'es-panel-card' . ($is_founder ? ' es-founder' : '');
    ?>
      <div class="<?= $card_classes ?>">
        <?php if ($is_founder): ?>
          <span class="es-founder-badge">⭐ Founder</span>
        <?php endif; ?>
        <div class="aspect-square <?= $tint ?> flex items-center justify-center" style="color: var(--ink-soft)">
          <?php if ($sp['photo'] && file_exists(__DIR__ . '/assets/images/' . $sp['photo'])): ?>
            <img src="/assets/images/<?= e($sp['photo']) ?>" alt="<?= e($sp['name']) ?>" class="w-full h-full object-cover">
          <?php else: ?>
            <div class="text-center px-3">
              <div class="text-4xl mb-2">👤</div>
              <span class="text-xs">photo: <?= e($sp['photo']) ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div class="p-4">
          <p class="text-xs uppercase tracking-wide font-semibold" style="color: var(--coral)"><?= e($sp['role']) ?></p>
          <h3 class="es-display font-semibold mt-1"><?= e($sp['name']) ?></h3>
          <p class="text-xs mt-1" style="color: var(--ink-soft)"><?= e($sp['qualifications']) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ─────────────────────  FINAL CTA  ───────────────────── -->
<section class="es-cta-band mb-2">
  <svg class="es-doodle float-slow" style="top: 22px; left: 8%; --r: -10deg" width="36" height="36" viewBox="0 0 36 36">
    <path d="M18 3 L21 13 L31 13.5 L23 20 L25.5 30 L18 24 L10.5 30 L13 20 L5 13.5 L15 13 Z" fill="white" opacity="0.85"/>
  </svg>
  <svg class="es-doodle float-mid" style="top: 36px; right: 10%; --r: 8deg" width="60" height="36" viewBox="0 0 60 36">
    <path d="M10 26 C5 26 3 22 6 18 C3 12 10 8 16 12 C18 6 28 4 30 12 C36 8 46 12 44 18 C50 18 52 24 48 28 C46 32 38 32 34 30 L14 30 C12 30 10 28 10 26 Z" fill="white" opacity="0.8"/>
  </svg>
  <svg class="es-doodle" style="bottom: 18px; left: 16%; transform: rotate(8deg)" width="80" height="20" viewBox="0 0 80 20">
    <path d="M2 10 Q12 1 22 10 T 42 10 T 62 10 T 78 10" stroke="white" stroke-width="3" stroke-linecap="round" fill="none" opacity="0.9"/>
  </svg>

  <div class="relative z-10">
    <p class="es-script text-2xl mb-1" style="color: white">Ready, set, go!</p>
    <h2 class="es-display text-3xl sm:text-4xl font-bold mb-4" data-i18n="cta.heading">Ready to begin?</h2>
    <p class="opacity-90 mb-7 max-w-2xl mx-auto" data-i18n="cta.body">A 20-minute investment can change how you guide your child for years. Login with your WhatsApp number to start.</p>
    <a href="/login.php" class="es-cta-primary">
      <span data-i18n="cta.start">Start free assessment</span>
      <span style="font-size: 18px">→</span>
    </a>
  </div>
</section>

<!-- ── Splash removal: show once per session ── -->
<script>
(function() {
  const splash = document.getElementById('esSplash');
  if (!splash) return;
  try {
    if (sessionStorage.getItem('es_splash_seen')) {
      splash.remove();
      return;
    }
    sessionStorage.setItem('es_splash_seen', '1');
  } catch (_) { /* private mode — show splash anyway */ }
  setTimeout(() => splash.remove(), 2200);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
