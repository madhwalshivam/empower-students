<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);

/* ── Question definitions (bilingual) ── */
$qs = [
    ['key'=>'height',  'q_en'=>'Height',                             'q_hi'=>'ऊँचाई',                              'type'=>'height_ftin'],
    ['key'=>'weight',  'q_en'=>'Weight (kg)',                        'q_hi'=>'वज़न (किलोग्राम)',                    'type'=>'number', 'unit_en'=>'kg', 'unit_hi'=>'किग्रा', 'min'=>1, 'max'=>200, 'step'=>'0.01'],
    ['key'=>'sleep',   'q_en'=>'Hours of sleep at night',            'q_hi'=>'रात की नींद के घंटे',                 'type'=>'number', 'unit_en'=>'hrs','unit_hi'=>'घंटे','min'=>0,'max'=>16, 'step'=>'0.5', 'concern_if'=>'<=7'],
    ['key'=>'appetite','q_en'=>'How is appetite generally? (0 very poor – 10 excellent)', 'q_hi'=>'भूख आमतौर पर कैसी है? (0 बहुत कम – 10 बहुत अच्छी)', 'type'=>'likert','min'=>0,'max'=>10,'concern_if'=>'<=3'],
    ['key'=>'energy',  'q_en'=>'How is general energy/activity? (0 lethargic – 10 very active)', 'q_hi'=>'सामान्य ऊर्जा कैसी है? (0 सुस्त – 10 बहुत सक्रिय)', 'type'=>'likert','min'=>0,'max'=>10,'concern_if'=>'<=3'],
    ['key'=>'illness', 'q_en'=>'Recurring illness in last 3 months (>3 episodes)?',     'q_hi'=>'पिछले 3 महीनों में बार-बार बीमारी (3 से अधिक बार)?',  'type'=>'yesno','concern_if'=>'yes'],
    ['key'=>'chronic', 'q_en'=>'Any chronic condition (asthma, allergies, diabetes, epilepsy, etc.)?', 'q_hi'=>'कोई पुरानी बीमारी (दमा, एलर्जी, डायबिटीज़, मिर्गी, आदि)?', 'type'=>'yesno','concern_if'=>'yes'],
    ['key'=>'sensory', 'q_en'=>'Vision or hearing concerns?',         'q_hi'=>'देखने या सुनने की कोई समस्या?',          'type'=>'yesno','concern_if'=>'yes'],
    ['key'=>'bowel',   'q_en'=>'Bowel pattern normal?',               'q_hi'=>'मल त्याग सामान्य है?',                  'type'=>'yesno','concern_if'=>'no'],
    ['key'=>'vacc',    'q_en'=>'Vaccinations up-to-date?',            'q_hi'=>'टीकाकरण पूरा है?',                      'type'=>'yesno','concern_if'=>'no'],
];
if ($band !== 'infant') {
    $qs[] = ['key'=>'screen','q_en'=>'Daily screen time (hours)',   'q_hi'=>'रोज़ का स्क्रीन समय (घंटों में)','type'=>'number','unit_en'=>'hrs','unit_hi'=>'घंटे','min'=>0,'max'=>16,'step'=>'0.5','concern_if'=>'>=4'];
    $qs[] = ['key'=>'play',  'q_en'=>'Daily outdoor active play (hours)', 'q_hi'=>'रोज़ का बाहर का सक्रिय खेल (घंटों में)','type'=>'number','unit_en'=>'hrs','unit_hi'=>'घंटे','min'=>0,'max'=>12,'step'=>'0.5','concern_if'=>'<=0'];
}

/* ── Submit handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $a = start_or_resume_assessment($child['id'], 'health', $band);
    $items = []; $flags = []; $score = 0; $considered = 0;
    foreach ($qs as $i => $q) {
        $val = $_POST['q'][$i] ?? '';
        $is_concern = false;
        if (isset($q['concern_if'])) {
            $c = $q['concern_if'];
            if ($c === 'yes' && $val === 'yes') $is_concern = true;
            elseif ($c === 'no'  && $val === 'no')  $is_concern = true;
            elseif (preg_match('/^>=(\d+(?:\.\d+)?)$/', $c, $m) && is_numeric($val) && $val >= (float)$m[1]) $is_concern = true;
            elseif (preg_match('/^<=(\d+(?:\.\d+)?)$/', $c, $m) && is_numeric($val) && $val <= (float)$m[1]) $is_concern = true;
        }
        $items[] = ['q'=>$q['q_en'], 'type'=>$q['type'], 'a'=>$val, 'concern'=>$is_concern];
        if ($is_concern) $flags[] = ['q'=>$q['q_en'], 'a'=>$val];
        if (in_array($q['type'], ['likert','number','height_ftin'], true) && is_numeric($val)) {
            $score += (float)$val; $considered++;
        } elseif ($q['type'] === 'yesno') {
            if ($val === 'yes' && (($q['concern_if'] ?? null) !== 'yes')) $score++;
            if ($val === 'no'  && (($q['concern_if'] ?? null) !== 'no'))  $score++;
            $considered++;
        }
    }
    $pct = $considered ? round($score * 100 / max(1, $considered), 1) : null;
    $sys = "You are a paediatrician. Plain, warm tone. Comment on growth/BMI relative to age, sleep, nutrition, activity, screen-time and any red-flags. Suggest one practical lifestyle change and one clinical step if warranted. 5-7 sentences.";
    $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs, band " . $band . ".\nResponses:\n" . json_encode($items, JSON_UNESCAPED_UNICODE) . "\nWrite the parent-facing summary now.";
    $summary = claude_chat($sys, [['role'=>'user','content'=>$user]], 600, 0.4);
    if ($summary === '') $summary = 'Saved.';
    finalize_assessment($a['id'], $pct, $band, $summary, $flags, $items);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Health screening');
?>
<p class="text-slate-600 mb-6 max-w-3xl es-bi"
   data-en="Quick health check-in for <?= e($child['name']) ?>. Answers help us flag anything worth a paediatric visit."
   data-hi="<?= e($child['name']) ?> के लिए एक त्वरित स्वास्थ्य जाँच। उत्तर हमें ऐसी बातें पहचानने में मदद करते हैं जिनके लिए बाल रोग विशेषज्ञ की सलाह उपयोगी हो।">
  Quick health check-in for <?= e($child['name']) ?>. Answers help us flag anything worth a paediatric visit.
</p>

<form method="post" class="space-y-4">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid"  value="<?= (int)$child['id'] ?>">

  <?php foreach ($qs as $i => $q): ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <p class="font-medium mb-3">
        <?= ($i + 1) ?>.
        <span class="es-bi" data-en="<?= e($q['q_en']) ?>" data-hi="<?= e($q['q_hi']) ?>"><?= e($q['q_en']) ?></span>
      </p>

      <?php if ($q['type'] === 'height_ftin'): ?>
        <div class="flex flex-wrap items-center gap-3" data-ftin-row>
          <div class="flex items-center gap-2">
            <input type="number" min="1" max="8" step="1" data-ftin="ft"
                   class="w-20 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            <span class="text-sm text-slate-500 es-bi" data-en="ft" data-hi="फ़ुट">ft</span>
          </div>
          <div class="flex items-center gap-2">
            <input type="number" min="0" max="11.99" step="0.01" data-ftin="in"
                   class="w-20 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            <span class="text-sm text-slate-500 es-bi" data-en="in" data-hi="इंच">in</span>
          </div>
          <span class="text-slate-300 select-none">↔</span>
          <div class="flex items-center gap-2">
            <input type="number" name="q[<?= $i ?>]" required min="30" max="220" step="0.01" data-ftin="cm"
                   class="w-28 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            <span class="text-sm text-slate-500">cm</span>
          </div>
        </div>
        <p class="text-xs text-slate-400 mt-2 es-bi"
           data-en="Type either ft+in or cm — the other will fill in automatically."
           data-hi="फ़ुट+इंच या सेमी में से कोई भी डालें — दूसरा अपने आप भर जाएगा।">
          Type either ft+in or cm — the other will fill in automatically.
        </p>

      <?php elseif ($q['type'] === 'likert'): ?>
        <div class="flex flex-wrap gap-2">
          <?php for ($v = $q['min']; $v <= $q['max']; $v++): ?>
            <label class="cursor-pointer">
              <input type="radio" name="q[<?= $i ?>]" value="<?= $v ?>" class="peer sr-only" required>
              <span class="block w-10 h-10 leading-10 text-center rounded-lg bg-slate-100 peer-checked:brand-grad peer-checked:text-white"><?= $v ?></span>
            </label>
          <?php endfor; ?>
        </div>

      <?php elseif ($q['type'] === 'yesno'): ?>
        <div class="flex gap-2">
          <?php
          $opts = [
            ['v'=>'yes',    'en'=>'Yes',      'hi'=>'हाँ',     'c'=>'emerald-500'],
            ['v'=>'no',     'en'=>'No',       'hi'=>'नहीं',     'c'=>'rose-500'],
            ['v'=>'unsure', 'en'=>'Not sure', 'hi'=>'पता नहीं', 'c'=>'slate-500'],
          ];
          foreach ($opts as $o): ?>
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="q[<?= $i ?>]" value="<?= $o['v'] ?>" class="peer sr-only" required>
              <span class="block text-center py-2 rounded-lg bg-slate-100 peer-checked:bg-<?= $o['c'] ?> peer-checked:text-white es-bi" data-en="<?= e($o['en']) ?>" data-hi="<?= e($o['hi']) ?>"><?= e($o['en']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($q['type'] === 'number'): ?>
        <div class="flex items-center gap-2">
          <input type="number" name="q[<?= $i ?>]" required
                 <?php if (isset($q['min'])):  ?>min="<?= (float)$q['min'] ?>"<?php endif; ?>
                 <?php if (isset($q['max'])):  ?>max="<?= (float)$q['max'] ?>"<?php endif; ?>
                 <?php if (isset($q['step'])): ?>step="<?= e($q['step']) ?>"<?php else: ?>step="0.01"<?php endif; ?>
                 class="w-32 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
          <?php if (!empty($q['unit_en'])): ?>
            <span class="text-sm text-slate-500 es-bi" data-en="<?= e($q['unit_en']) ?>" data-hi="<?= e($q['unit_hi'] ?? $q['unit_en']) ?>"><?= e($q['unit_en']) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <button class="w-full brand-grad text-white font-semibold py-3 rounded-xl hover:opacity-90 mt-4 es-bi"
          data-en="Submit &amp; analyse" data-hi="जमा करें और विश्लेषण करें">
    Submit &amp; analyse
  </button>
</form>

<script>
/* Bilingual swap */
(function () {
  function getLang() { try { return localStorage.getItem('es_lang') || 'en'; } catch(_){ return 'en'; } }
  function applyBi() {
    const lang = getLang();
    document.querySelectorAll('.es-bi').forEach(el => {
      const en = el.dataset.en, hi = el.dataset.hi;
      const target = (lang === 'hi' && hi) ? hi : en;
      const ta = document.createElement('textarea');
      ta.innerHTML = target;
      el.textContent = ta.value;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();

/* ft+in <-> cm conversion */
(function () {
  document.querySelectorAll('[data-ftin-row]').forEach(row => {
    const ft  = row.querySelector('input[data-ftin="ft"]');
    const ins = row.querySelector('input[data-ftin="in"]');
    const cm  = row.querySelector('input[data-ftin="cm"]');
    if (!ft || !ins || !cm) return;

    function fromImperial() {
      const f = parseFloat(ft.value)  || 0;
      const i = parseFloat(ins.value) || 0;
      if (f === 0 && i === 0) { cm.value = ''; return; }
      cm.value = ((f * 30.48) + (i * 2.54)).toFixed(2);
    }
    function fromMetric() {
      const c = parseFloat(cm.value) || 0;
      if (c === 0) { ft.value = ''; ins.value = ''; return; }
      const totalIn = c / 2.54;
      const f = Math.floor(totalIn / 12);
      const i = totalIn - f * 12;
      ft.value  = f;
      ins.value = i.toFixed(2).replace(/\.?0+$/, '');
    }
    ft.addEventListener('input',  fromImperial);
    ins.addEventListener('input', fromImperial);
    cm.addEventListener('input',  fromMetric);
  });
})();
</script>
<?php module_layout_close(); ?>
