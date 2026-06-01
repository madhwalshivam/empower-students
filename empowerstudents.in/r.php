<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/referral.php';

$code     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $_GET['c'] ?? ''));
$referrer = $code ? lookup_referrer_by_code($code) : null;
$referrer_name = '';

if ($referrer) {
    $st = db()->prepare("SELECT name FROM parents WHERE id = ?");
    $st->execute([$referrer]);
    $referrer_name = (string)($st->fetchColumn() ?: '');
    // Stash in session so login.php can pick it up after OTP verify
    $_SESSION['referral_code']        = $code;
    $_SESSION['referral_referrer_id'] = $referrer;
}

$page_title = 'You were invited to Empower Students';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-10 text-center">

  <?php if ($referrer): ?>
    <div class="text-6xl mb-4">🎁</div>
    <h1 class="text-3xl font-extrabold mb-3 es-bi"
        data-en="<?= e($referrer_name ?: 'A friend') ?> invited you to Empower Students"
        data-hi="<?= e($referrer_name ?: 'एक दोस्त') ?> ने आपको Empower Students पर आमंत्रित किया है">
      <?= e($referrer_name ?: 'A friend') ?> invited you to Empower Students
    </h1>
    <p class="text-slate-700 mb-6 leading-relaxed es-bi"
       data-en="Get a comprehensive 13-module child assessment covering health, mind, emotions, behaviour, language, math and more &mdash; led by Dr. P. K. Jha (AIIMS-trained neurosurgeon, 30+ yrs)."
       data-hi="13 मॉड्यूल का व्यापक बाल मूल्यांकन — स्वास्थ्य, मन, भावनाएँ, व्यवहार, भाषा, गणित और भी बहुत कुछ। डॉ. पी. के. झा (AIIMS से प्रशिक्षित न्यूरोसर्जन, 30+ वर्ष) के नेतृत्व में।">
      Get a comprehensive 13-module child assessment.
    </p>

    <div class="bg-gradient-to-br from-indigo-50 to-cyan-50 border border-indigo-100 rounded-2xl p-6 mb-6 text-left">
      <h2 class="font-semibold text-indigo-900 mb-3 es-bi"
          data-en="What you get free with this invite:"
          data-hi="इस आमंत्रण से आपको मुफ़्त मिलेगा:">What you get free with this invite:</h2>
      <ul class="text-sm text-slate-700 space-y-1.5 list-disc pl-5">
        <li class="es-bi" data-en="100 free credits on signup &mdash; enough for several modules" data-hi="साइन-अप पर 100 मुफ़्त क्रेडिट — कई मॉड्यूल के लिए पर्याप्त">100 free credits on signup</li>
        <li class="es-bi" data-en="Add 1 child profile and run their first evaluation free" data-hi="1 बच्चे का प्रोफ़ाइल जोड़ें और पहला मूल्यांकन मुफ़्त चलाएँ">Add 1 child profile and run their first evaluation free</li>
        <li class="es-bi" data-en="Bilingual interface (English / हिंदी)" data-hi="दो-भाषी इंटरफ़ेस (English / हिंदी)">Bilingual interface (English / हिंदी)</li>
      </ul>
    </div>

    <a href="/login.php?ref=<?= e($code) ?>"
       class="inline-block brand-grad text-white font-bold px-8 py-4 rounded-xl text-lg shadow-lg hover:opacity-90 es-bi"
       data-en="▶ Sign up to start" data-hi="▶ शुरू करने के लिए साइन-अप करें">
      ▶ Sign up to start
    </a>
    <p class="text-xs text-slate-500 mt-4 es-bi"
       data-en="One-time WhatsApp OTP. Stays signed in for 60 days."
       data-hi="WhatsApp पर एक बार OTP। 60 दिन तक लॉग-इन रहेगा।">
      One-time WhatsApp OTP. Stays signed in for 60 days.
    </p>

  <?php else: ?>
    <div class="text-5xl mb-4">🤔</div>
    <h1 class="text-2xl font-bold mb-3 es-bi"
        data-en="That referral link doesn&rsquo;t look right" data-hi="यह रेफ़रल लिंक सही नहीं लगता">That referral link doesn&rsquo;t look right</h1>
    <p class="text-slate-600 mb-6 es-bi"
       data-en="The code is missing or invalid. You can still join Empower Students directly:"
       data-hi="कोड या तो ग़ायब है या अमान्य है। आप सीधे Empower Students से जुड़ सकते हैं:">
      The code is missing or invalid. You can still join Empower Students directly:
    </p>
    <a href="/login.php" class="inline-block brand-grad text-white font-bold px-6 py-3 rounded-lg es-bi"
       data-en="Sign up" data-hi="साइन-अप करें">Sign up</a>
  <?php endif; ?>
</div>

<script>
(function () {
  function getLang() { try { return localStorage.getItem('es_lang') || 'en'; } catch(_){ return 'en'; } }
  function applyBi() {
    const lang = getLang();
    document.querySelectorAll('.es-bi').forEach(el => {
      const en = el.dataset.en, hi = el.dataset.hi;
      const target = (lang === 'hi' && hi) ? hi : (en || el.innerHTML);
      const ta = document.createElement('textarea');
      ta.innerHTML = target; el.innerHTML = ta.value;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
