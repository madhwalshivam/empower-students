<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'About — EmpowerStudents.in';
require __DIR__ . '/includes/header.php';

$wa_digits = preg_replace('/\D/', '', SITE_SUPPORT_WA);
$ph_digits = preg_replace('/\D/', '', SITE_SUPPORT_PH);
?>
<!-- Brand fonts already loaded on homepage; loading again here is a no-op -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Caveat:wght@600&display=swap" rel="stylesheet">

<style>
  :root {
    --m-navy:    #0E2A5E;
    --m-coral:   #F26B5E;
    --m-yellow:  #F5C242;
    --m-green:   #2E8B57;
    --m-paper:   #FFFBF5;
    --m-cream:   #FFF4E0;
    --m-soft:    #F5F7FA;
    --m-ink:     #1F2937;
    --m-muted:   #5C6577;
  }
  body { background: var(--m-paper) !important; color: var(--m-ink); }
  .ab-display { font-family: 'Fredoka', sans-serif; }
  .ab-script  { font-family: 'Caveat', cursive; line-height: 1; }

  .ab-wrap { max-width: 980px; margin: 0 auto; padding: 0 20px; }

  /* Hero */
  .ab-hero {
    background: linear-gradient(135deg, #FFF4E0 0%, #FFFBF5 50%, #EEF2FF 100%);
    border-radius: 32px;
    padding: 40px 28px;
    text-align: center;
    margin-bottom: 32px;
    border: 2px solid rgba(14,42,94,0.06);
  }
  @media (min-width: 768px) {
    .ab-hero { padding: 56px 40px; }
  }
  .ab-eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    background: white; padding: 8px 16px; border-radius: 999px;
    font-size: 13px; font-weight: 600; color: var(--m-navy);
    border: 1px solid rgba(14,42,94,0.10);
    margin-bottom: 18px;
  }
  .ab-h1 {
    font-family: 'Fredoka', sans-serif; font-weight: 700;
    font-size: 32px; color: var(--m-navy); line-height: 1.15;
    margin-bottom: 16px;
  }
  @media (min-width: 768px) { .ab-h1 { font-size: 44px; } }
  .ab-h1 .accent {
    background: linear-gradient(120deg, transparent 50%, rgba(245,194,66,0.45) 50%);
  }
  .ab-lead {
    font-size: 17px; line-height: 1.65; color: var(--m-muted);
    max-width: 680px; margin: 0 auto;
  }
  @media (min-width: 768px) { .ab-lead { font-size: 18px; } }

  /* Section title */
  .ab-section-title {
    text-align: center; margin: 44px 0 22px;
  }
  .ab-section-title h2 {
    font-family: 'Fredoka', sans-serif; font-weight: 700;
    font-size: 26px; color: var(--m-navy);
  }
  @media (min-width: 768px) { .ab-section-title h2 { font-size: 32px; } }
  .ab-section-title p { color: var(--m-muted); margin-top: 6px; max-width: 580px; margin-left: auto; margin-right: auto; }
  .ab-script-eyebrow {
    color: var(--m-coral); font-family: 'Caveat', cursive;
    font-size: 22px; display: inline-block; margin-bottom: 4px;
  }

  /* Why we built this — quote card */
  .ab-mission {
    background: white; border-radius: 24px;
    padding: 28px 26px;
    border: 2px solid rgba(14,42,94,0.06);
    box-shadow: 0 16px 32px -20px rgba(14,42,94,0.18);
    position: relative;
  }
  .ab-mission::before {
    content: '"';
    position: absolute; top: -18px; left: 22px;
    font-family: Georgia, serif; font-size: 80px; line-height: 1;
    color: var(--m-coral); opacity: 0.85;
  }
  .ab-mission p {
    font-size: 16.5px; line-height: 1.65; color: var(--m-ink);
    margin: 8px 0;
  }

  /* Differentiator grid */
  .ab-grid {
    display: grid; grid-template-columns: 1fr; gap: 16px;
  }
  @media (min-width: 768px) {
    .ab-grid { grid-template-columns: repeat(2, 1fr); gap: 18px; }
  }
  @media (min-width: 1024px) {
    .ab-grid { grid-template-columns: repeat(3, 1fr); }
  }
  .ab-card {
    background: white; border-radius: 22px;
    padding: 22px 22px;
    border: 2px solid rgba(14,42,94,0.06);
    transition: transform .15s ease, box-shadow .15s ease;
  }
  .ab-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 28px -16px rgba(14,42,94,0.18);
  }
  .ab-card-icon {
    width: 52px; height: 52px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: white;
    margin-bottom: 14px;
  }
  .ab-ic-1 { background: linear-gradient(135deg, #F26B5E, #DA4537); }   /* coral - age */
  .ab-ic-2 { background: linear-gradient(135deg, #6366F1, #4F46E5); }   /* indigo - adaptive */
  .ab-ic-3 { background: linear-gradient(135deg, #B19AFF, #8B6FE0); }   /* purple - speech */
  .ab-ic-4 { background: linear-gradient(135deg, #43B5A0, #2E8B7E); }   /* teal - parent */
  .ab-ic-5 { background: linear-gradient(135deg, #F5C242, #E0A82C); }   /* yellow - bilingual */
  .ab-ic-6 { background: linear-gradient(135deg, #5DADE2, #3B85B8); }   /* sky - pay per use */
  .ab-card h3 {
    font-family: 'Fredoka', sans-serif; font-weight: 700;
    font-size: 17px; color: var(--m-navy); margin-bottom: 6px;
  }
  .ab-card p { font-size: 14.5px; line-height: 1.55; color: var(--m-muted); }

  /* Founder card */
  .ab-founder {
    background: white; border-radius: 26px;
    padding: 28px 24px;
    border: 2px solid rgba(14,42,94,0.06);
    box-shadow: 0 18px 36px -22px rgba(14,42,94,0.18);
    display: grid; grid-template-columns: 1fr; gap: 22px;
    align-items: center;
  }
  @media (min-width: 768px) {
    .ab-founder { grid-template-columns: 200px 1fr; padding: 32px 32px; gap: 32px; }
  }
  .ab-founder-photo {
    width: 160px; height: 160px; border-radius: 50%;
    background: linear-gradient(135deg, #FFF4E0, #FFFBF5);
    border: 4px solid white;
    box-shadow: 0 12px 24px -12px rgba(14,42,94,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 72px;
    margin: 0 auto;
  }
  @media (min-width: 768px) {
    .ab-founder-photo { width: 200px; height: 200px; font-size: 92px; }
  }
  .ab-founder h3 {
    font-family: 'Fredoka', sans-serif; font-weight: 700;
    font-size: 22px; color: var(--m-navy); margin-bottom: 4px;
  }
  .ab-founder .ab-role {
    font-size: 13px; font-weight: 600; color: var(--m-coral);
    text-transform: uppercase; letter-spacing: 1.2px;
    margin-bottom: 10px;
  }
  .ab-founder p { font-size: 15.5px; line-height: 1.6; color: var(--m-ink); margin-bottom: 10px; }
  .ab-credentials {
    display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;
  }
  .ab-credentials span {
    background: var(--m-cream); color: #7A5B0B;
    font-size: 12px; font-weight: 600;
    padding: 5px 12px; border-radius: 999px;
    border: 1px solid rgba(245,194,66,0.4);
  }

  /* Catalogue CTA strip */
  .ab-cta-strip {
    background: linear-gradient(135deg, #FFF5F3 0%, #EEF2FF 100%);
    border: 1px solid rgba(99, 102, 241, 0.18);
    border-radius: 24px;
    padding: 28px 24px;
    text-align: center;
    margin: 36px 0;
  }
  .ab-cta-strip h3 {
    font-family: 'Fredoka', sans-serif; font-weight: 700;
    font-size: 22px; color: var(--m-navy); margin-bottom: 6px;
  }
  .ab-cta-strip p { color: var(--m-muted); margin-bottom: 18px; }
  .ab-cta-strip .btn-row {
    display: inline-flex; flex-wrap: wrap; justify-content: center; gap: 10px;
  }
  .ab-btn-primary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px; border-radius: 999px;
    background: var(--m-navy); color: white;
    font-weight: 700; font-size: 14px;
    text-decoration: none;
    transition: transform .12s ease;
  }
  .ab-btn-primary:hover { transform: translateY(-1px); }
  .ab-btn-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px; border-radius: 999px;
    background: white; color: var(--m-navy);
    border: 2px solid rgba(14,42,94,0.10);
    font-weight: 700; font-size: 14px;
    text-decoration: none;
    transition: transform .12s ease, border-color .12s ease;
  }
  .ab-btn-secondary:hover {
    transform: translateY(-1px);
    border-color: var(--m-navy);
  }

  /* Contact card */
  .ab-contact {
    background: white; border-radius: 22px;
    padding: 26px 24px;
    border: 2px solid rgba(14,42,94,0.06);
    text-align: center;
  }
  .ab-contact-grid {
    display: grid; grid-template-columns: 1fr; gap: 14px;
    margin-top: 20px;
  }
  @media (min-width: 640px) {
    .ab-contact-grid { grid-template-columns: repeat(3, 1fr); }
  }
  .ab-contact-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: var(--m-soft);
    border-radius: 14px;
    text-decoration: none; color: var(--m-ink);
    transition: background .12s ease;
  }
  .ab-contact-item:hover { background: var(--m-cream); }
  .ab-contact-item .ico {
    font-size: 22px; flex: 0 0 22px;
  }
  .ab-contact-item .meta { text-align: left; line-height: 1.3; min-width: 0; }
  .ab-contact-item .label {
    font-size: 11px; color: var(--m-muted); font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .ab-contact-item .value {
    font-weight: 700; color: var(--m-navy); font-size: 14px;
    word-break: break-all;
  }
</style>

<div class="ab-wrap py-8">

  <!-- ───── Hero ───── -->
  <section class="ab-hero">
    <span class="ab-eyebrow">
      <span style="color: var(--m-coral)">★</span>
      <span data-i18n="ab.eyebrow">In Collaboration with Global Autism Learning School</span>
    </span>
    <h1 class="ab-h1">
      <span data-i18n="ab.h1.1">A 360° view of your child —</span>
      <span class="accent" data-i18n="ab.h1.2">taken early, updated through the years.</span>
    </h1>
    <p class="ab-lead" data-i18n="ab.lead">
      EmpowerStudents.in brings together a multi-disciplinary specialist panel
      and modern AI tools to help you understand your child — their strengths,
      struggles, and how to nurture them at home.
    </p>
  </section>

  <!-- ───── Why we built this ───── -->
  <div class="ab-section-title">
    <span class="ab-script-eyebrow" data-i18n="ab.mission.eyebrow">Why we built this</span>
    <h2 data-i18n="ab.mission.h">A whole picture, not just one snapshot.</h2>
  </div>
  <div class="ab-mission">
    <p data-i18n="ab.mission.b1">
      Most children are evaluated only when something is already going wrong —
      and even then, only on a single dimension at a time.
    </p>
    <p data-i18n="ab.mission.b2">
      We believe a 360° picture, taken early and updated through the years,
      is the kindest gift we can give a child and their parent.
      Whether your concern is speech, behaviour, learning, or simply <em>"is my child on track?"</em>,
      our platform helps you see clearly — without panic, without pressure.
    </p>
  </div>

  <!-- ───── What makes us different ───── -->
  <div class="ab-section-title">
    <span class="ab-script-eyebrow" data-i18n="ab.diff.eyebrow">What makes us different</span>
    <h2 data-i18n="ab.diff.h">Six things parents tell us they value most.</h2>
  </div>
  <div class="ab-grid">

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-1">🎯</div>
      <h3 data-i18n="ab.diff.1.h">Age-calibrated</h3>
      <p data-i18n="ab.diff.1.b">
        Every module adapts to your child's age. For under-2s we focus on subtle
        behaviour markers so early ASD or learning concerns are not missed.
      </p>
    </article>

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-2">📊</div>
      <h3 data-i18n="ab.diff.2.h">Adaptive testing</h3>
      <p data-i18n="ab.diff.2.b">
        Math, general awareness and language quizzes start very easy and step up only
        when answers are accurate and quick — we find the comfortable base level, never overwhelm.
      </p>
    </article>

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-3">🎤</div>
      <h3 data-i18n="ab.diff.3.h">Speech that listens</h3>
      <p data-i18n="ab.diff.3.b">
        Your child reads short sentences and answers an open-ended question.
        AI scores fluency, articulation, confidence and stuttering — far more
        useful than a yes/no checklist.
      </p>
    </article>

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-4">🤝</div>
      <h3 data-i18n="ab.diff.4.h">Parent-friendly, judgment-free</h3>
      <p data-i18n="ab.diff.4.b">
        A gentle measure of how you are currently understanding and nurturing your child —
        with concrete next steps. No grades for parents, just clarity and support.
      </p>
    </article>

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-5">🇮🇳</div>
      <h3 data-i18n="ab.diff.5.h">Bilingual — English &amp; हिंदी</h3>
      <p data-i18n="ab.diff.5.b">
        Reports, plans and AI consults — all available in Hindi too,
        so the whole family (including grandparents) can follow along.
      </p>
    </article>

    <article class="ab-card">
      <div class="ab-card-icon ab-ic-6">💳</div>
      <h3 data-i18n="ab.diff.6.h">Pay only for what you need</h3>
      <p data-i18n="ab.diff.6.b">
        Pick individual modules from ₹199. Bundle for up to 35% off.
        First evaluation is always free. No subscriptions, no hidden fees.
      </p>
    </article>

  </div>

  <!-- ───── Catalogue CTA ───── -->
  <section class="ab-cta-strip">
    <h3 data-i18n="ab.cta.h">Ready to see what fits your child?</h3>
    <p data-i18n="ab.cta.sub">Browse the full module catalogue, or start with a free evaluation.</p>
    <div class="btn-row">
      <a href="/catalogue.php" class="ab-btn-primary">
        <span style="font-size: 16px">📦</span>
        <span data-i18n="ab.cta.modules">Browse modules</span>
      </a>
      <a href="/#lead-form" class="ab-btn-secondary">
        <span data-i18n="ab.cta.eval">Get a FREE evaluation</span>
        <span style="font-size: 16px">→</span>
      </a>
    </div>
  </section>

  <!-- ───── Founder ───── -->
  <div class="ab-section-title">
    <span class="ab-script-eyebrow" data-i18n="ab.story.eyebrow">Our story</span>
    <h2 data-i18n="ab.story.h">Built by a clinician, for parents.</h2>
  </div>
  <div class="ab-founder">
    <div class="ab-founder-photo">👨‍⚕️</div>
    <div>
      <h3 data-i18n="ab.story.name">Dr. P. K. Jha</h3>
      <p class="ab-role" data-i18n="ab.story.role">Founder · Director · Neurosurgeon</p>
      <p data-i18n="ab.story.b1">
        AIIMS-trained neurosurgeon with 30+ years of clinical experience across
        neurology, child development, and family care. After decades of seeing
        parents arrive too late — when problems had already grown — Dr. Jha
        founded EmpowerStudents.in to bring early, holistic, affordable assessment
        to every Indian family.
      </p>
      <p data-i18n="ab.story.b2">
        The platform combines what he has learned in clinic with the patience and
        consistency only AI can offer at scale — so every parent gets the same
        depth of attention, regardless of where they live.
      </p>
      <div class="ab-credentials">
        <span data-i18n="ab.cred.1">M.Ch (AIIMS)</span>
        <span data-i18n="ab.cred.2">30+ years</span>
        <span data-i18n="ab.cred.3">Neuro Care India</span>
        <span data-i18n="ab.cred.4">Greater Noida</span>
      </div>
    </div>
  </div>

  <!-- ───── Contact ───── -->
  <div class="ab-section-title">
    <span class="ab-script-eyebrow" data-i18n="ab.contact.eyebrow">Get in touch</span>
    <h2 data-i18n="ab.contact.h">We're a message away.</h2>
  </div>
  <div class="ab-contact">
    <p style="color: var(--m-muted); max-width: 560px; margin: 0 auto;" data-i18n="ab.contact.b">
      For partnerships, parent enquiries, or to book a free evaluation —
      reach us on any of the channels below.
    </p>
    <div class="ab-contact-grid">
      <a href="tel:<?= e($ph_digits) ?>" class="ab-contact-item">
        <span class="ico">📞</span>
        <div class="meta">
          <div class="label" data-i18n="ab.contact.call">Call</div>
          <div class="value"><?= e(SITE_SUPPORT_PH) ?></div>
        </div>
      </a>
      <a href="https://wa.me/<?= e($wa_digits) ?>" target="_blank" rel="noopener" class="ab-contact-item">
        <span class="ico">💬</span>
        <div class="meta">
          <div class="label" data-i18n="ab.contact.wa">WhatsApp</div>
          <div class="value"><?= e(SITE_SUPPORT_WA) ?></div>
        </div>
      </a>
      <a href="mailto:<?= e(SITE_SUPPORT_EMAIL) ?>" class="ab-contact-item">
        <span class="ico">✉️</span>
        <div class="meta">
          <div class="label" data-i18n="ab.contact.email">Email</div>
          <div class="value"><?= e(SITE_SUPPORT_EMAIL) ?></div>
        </div>
      </a>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
