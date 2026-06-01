<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/referral.php';
require_once __DIR__ . '/includes/expert_report.php';
require_parent();

$child = child_for_parent((int)($_GET['id'] ?? 0));
if (!$child) { header('Location: /dashboard.php'); exit; }
$age = calc_age_years($child['dob']);
$band = age_band($age);
$parent = current_parent();

ensure_expert_report_text_columns();

// pull all completed assessments
$st = db()->prepare("SELECT * FROM assessments WHERE child_id = ? AND status = 'done' ORDER BY module, completed_at DESC");
$st->execute([$child['id']]);
$rows = $st->fetchAll();

$latest = [];
foreach ($rows as $r) if (!isset($latest[$r['module']])) $latest[$r['module']] = $r;

$module_titles = [
    'health'              => 'Health',
    'pulse_check'         => 'Pulse / breath',
    'mind_power'          => 'Mind power',
    'emotions'            => 'Emotions',
    'behavior'            => 'Behaviour',
    'general_awareness'   => 'General awareness',
    'special_talent'      => 'Special talent',
    'speech'              => 'Speech',
    'spontaneous'         => 'Spontaneous speech',
    'math'                => 'Maths',
    'language'            => 'Language',
    'parent_index'        => 'Parent index',
    'diet'                => 'Diet',
];

$expert            = expert_report_status((int)$parent['id'], (int)$child['id']);
$delivered_report  = get_delivered_expert_report((int)$parent['id'], (int)$child['id']);

$page_title = 'Report — ' . $child['name'];
require __DIR__ . '/includes/header.php';
?>
<a href="/child.php?id=<?= (int)$child['id'] ?>" class="text-sm text-indigo-600 hover:underline">&larr; Back to <?= e($child['name']) ?></a>
<h1 class="text-2xl sm:text-3xl font-bold mt-3 mb-1 es-bi" data-en="Comprehensive report" data-hi="व्यापक रिपोर्ट">Comprehensive report</h1>
<p class="text-slate-600 mb-6"><?= e($child['name']) ?> · <?= round($age, 1) ?> yrs · <?= ucfirst($band) ?></p>

<?php if (!$latest): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-amber-900 es-bi"
       data-en="No completed assessments yet. Finish at least one module from <a href='/child.php?id=<?= (int)$child['id'] ?>' class='underline'>the assessment hub</a>, then come back."
       data-hi="अभी तक कोई मूल्यांकन पूरा नहीं हुआ है। <a href='/child.php?id=<?= (int)$child['id'] ?>' class='underline'>मूल्यांकन हब</a> से कम से कम एक मॉड्यूल पूरा करें, फिर वापस आएँ।">
    No completed assessments yet. Finish at least one module from <a href="/child.php?id=<?= (int)$child['id'] ?>" class="underline">the assessment hub</a>, then come back.
  </div>
<?php else: ?>

  <!-- Modules summary -->
  <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm mb-5">
    <h2 class="font-semibold mb-3 es-bi"
        data-en="Modules completed (<?= count($latest) ?>)"
        data-hi="पूरे किए गए मॉड्यूल (<?= count($latest) ?>)">Modules completed (<?= count($latest) ?>)</h2>
    <ul class="grid sm:grid-cols-2 gap-2 text-sm">
      <?php foreach ($latest as $mod => $r): ?>
        <li class="flex justify-between border border-slate-100 rounded-lg px-3 py-2">
          <span><?= e($module_titles[$mod] ?? $mod) ?></span>
          <span class="text-slate-500">
            <?= $r['score'] !== null ? 'Score ' . round((float)$r['score'], 1) : 'Done' ?>
            <?= !empty(json_decode($r['flags'] ?? '[]', true)) ? ' · <span class="text-amber-600">flags</span>' : '' ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($delivered_report): ?>

    <!-- ✅ Delivered report — show the full content -->
    <div class="bg-gradient-to-br from-emerald-50 to-cyan-50 border border-emerald-200 rounded-2xl p-6 mb-5">
      <div class="flex items-start gap-4 mb-4">
        <div class="text-4xl">📋</div>
        <div>
          <h2 class="text-xl font-bold text-emerald-900 es-bi"
              data-en="Detailed Expert Report" data-hi="विस्तृत विशेषज्ञ रिपोर्ट">Detailed Expert Report</h2>
          <p class="text-xs text-emerald-700 mt-1">
            <span class="es-bi" data-en="Delivered on" data-hi="वितरित">Delivered on</span>
            <?= e(date('d M Y', strtotime($delivered_report['report_delivered_at'] ?? $delivered_report['delivered_at']))) ?>
            ·
            <span class="es-bi" data-en="From Dr. P. K. Jha&rsquo;s team" data-hi="डॉ. पी. के. झा की टीम से">From Dr. P. K. Jha&rsquo;s team</span>
          </p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-emerald-100 p-5 leading-relaxed text-slate-800"
           id="reportContent" style="white-space: pre-wrap; font-family: ui-sans-serif, system-ui;">
<?= e($delivered_report['report_text']) ?>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <button onclick="window.print()" class="bg-slate-200 text-slate-800 px-4 py-2 rounded-lg text-sm">🖨 Print / save as PDF</button>
        <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/','',SITE_SUPPORT_WA)) ?>?text=<?= rawurlencode('Hello, I have read the detailed expert report for ' . $child['name'] . ' and would like to discuss.') ?>"
           target="_blank" class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm es-bi"
           data-en="💬 Discuss with our team" data-hi="💬 हमारी टीम से बात करें">💬 Discuss with our team</a>
      </div>
    </div>

  <?php elseif ($expert['id'] && $expert['status'] === 'pending'): ?>

    <!-- Order placed but report not delivered yet -->
    <div class="bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200 rounded-2xl p-6">
      <div class="flex items-start gap-4">
        <div class="text-4xl animate-pulse">⏳</div>
        <div>
          <h2 class="text-xl font-bold text-amber-900 mb-2 es-bi"
              data-en="Detailed Expert Report — In progress"
              data-hi="विस्तृत विशेषज्ञ रिपोर्ट — प्रगति में">Detailed Expert Report — In progress</h2>
          <p class="text-sm text-amber-800 mb-3 es-bi"
             data-en="Our team is studying <?= e($child['name']) ?>&rsquo;s evaluation. You&rsquo;ll receive the detailed report and a call within 24 hours."
             data-hi="हमारी टीम <?= e($child['name']) ?> के मूल्यांकन का अध्ययन कर रही है। आपको 24 घंटों के भीतर विस्तृत रिपोर्ट और कॉल मिलेगी।">
            Our team is studying <?= e($child['name']) ?>&rsquo;s evaluation. You&rsquo;ll receive the detailed report and a call within 24 hours.
          </p>
          <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/','',SITE_SUPPORT_WA)) ?>?text=<?= rawurlencode('Hello, regarding the detailed report for ' . $child['name']) ?>"
             target="_blank" class="inline-block bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700 es-bi"
             data-en="💬 Reach our team on WhatsApp" data-hi="💬 WhatsApp पर हमारी टीम से संपर्क करें">
            💬 Reach our team on WhatsApp
          </a>
        </div>
      </div>
    </div>

  <?php else: ?>

    <!-- Not yet ordered — point to child.php -->
    <div class="bg-gradient-to-br from-amber-50 to-rose-50 border-2 border-amber-200 rounded-2xl p-6 text-center">
      <div class="text-4xl mb-3">🌟</div>
      <h2 class="text-xl font-bold text-amber-900 mb-2 es-bi"
          data-en="Get the Detailed Expert Report" data-hi="विस्तृत विशेषज्ञ रिपोर्ट पाएँ">Get the Detailed Expert Report</h2>
      <p class="text-sm text-amber-800 mb-4 max-w-xl mx-auto es-bi"
         data-en="Our team will study <?= e($child['name']) ?>&rsquo;s full evaluation and deliver a detailed report with status and personalised recommendations &mdash; plus a call within 24 hours."
         data-hi="हमारी टीम <?= e($child['name']) ?> के पूरे मूल्यांकन का अध्ययन करेगी और स्थिति तथा व्यक्तिगत सिफ़ारिशों के साथ विस्तृत रिपोर्ट देगी — साथ में 24 घंटों के भीतर एक कॉल।">
        Our team will study <?= e($child['name']) ?>&rsquo;s full evaluation and deliver a detailed report with status and personalised recommendations &mdash; plus a call within 24 hours.
      </p>
      <a href="/child.php?id=<?= (int)$child['id'] ?>"
         class="inline-block brand-grad text-white font-bold px-6 py-3 rounded-lg hover:opacity-90 es-bi"
         data-en="▶ See options (1000 cr or free via referral)"
         data-hi="▶ विकल्प देखें (1000 cr या रेफ़रल से मुफ़्त)">
        ▶ See options
      </a>
    </div>

  <?php endif; ?>

<?php endif; ?>

<script>
(function () {
  // Make CAPS-on-own-line section headings render bold in the report content
  const el = document.getElementById('reportContent');
  if (el) {
    el.innerHTML = el.innerHTML.replace(
      /(^|\n)([A-Z][A-Z &\-/']{4,})(\n)/g,
      '$1<strong style="display:block;margin-top:1em;color:#0f766e;font-size:1.05em;">$2</strong>$3'
    );
  }

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
