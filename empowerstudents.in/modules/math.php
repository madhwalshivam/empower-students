<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'math', $band);

/**
 * Adaptive maths.
 * Level 1: counting / number recognition
 * Level 2: 1-digit add/sub
 * Level 3: 2-digit add/sub, easy multiplication
 * Level 4: multiplication tables, simple division, fractions intro
 * Level 5: percentages, decimals, multi-step word problems
 * Level 6: algebra (linear), squares/cubes
 * Level 7: simple equations, ratios, probability basics
 *
 * Generated questions client-side for variety.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $log = json_decode($_POST['log'] ?? '[]', true) ?: [];
    if (!$log) { header('Location: /modules/math.php?cid=' . (int)$child['id']); exit; }
    $correct = 0; $total = count($log); $max_level = 1; $sum_t = 0;
    foreach ($log as $row) {
        if (!empty($row['correct'])) $correct++;
        if (!empty($row['level']) && (int)$row['level'] > $max_level) $max_level = (int)$row['level'];
        $sum_t += (float)($row['t'] ?? 0);
    }
    $avg_t = $total ? round($sum_t / $total, 2) : 0;
    $score = $total ? round($correct * 100 / $total, 1) : 0;
    $sys = "You are a child education specialist for maths. Plain, encouraging language.";
    $user = "Adaptive maths quiz for " . $child['name'] . " (age " . round((float)$age, 1)
          . " yrs). " . $correct . "/" . $total . " correct. Highest level reached: L" . $max_level
          . ". Avg time/question: " . $avg_t . "s. "
          . "Levels: 1=count, 2=±1-digit, 3=±2-digit/×easy, 4=×÷ tables, 5=%/decimals, 6=algebra, 7=ratios/equations. "
          . "Write 4-5 sentences: estimated maths-level vs age, gaps to fix, and 2 home practice ideas.";
    $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 400, 0.5);
    if ($summary === '') $summary = 'Saved.';
    finalize_assessment($assessment['id'], $score, 'L' . $max_level, $summary, [], $log);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

// Suggested starting level by age
$start_level = ['toddler' => 1, 'child' => 2, 'preteen' => 4, 'teen' => 5][$band] ?? 1;

module_layout_open($child, 'Maths · adaptive');
?>
<p class="text-slate-600 mb-4 max-w-3xl es-bi"
   data-en="We start very easy and step up as <?= e($child['name']) ?> answers correctly and quickly. If a question is wrong, we step down. Aim is the comfortable base level &mdash; not perfection."
   data-hi="हम बहुत आसान शुरू करते हैं और जैसे-जैसे <?= e($child['name']) ?> सही और जल्दी जवाब देते हैं, स्तर बढ़ाते जाते हैं। ग़लत होने पर स्तर घटा देते हैं। लक्ष्य आरामदायक स्तर है — पूर्णता नहीं।">
  We start very easy and step up as <?= e($child['name']) ?> answers correctly and quickly. If a question is wrong, we step down. Aim is the comfortable base level &mdash; not perfection.
</p>

<div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm">
  <div class="flex items-center justify-between mb-4">
    <p class="text-sm text-slate-500">
      <span class="es-bi" data-en="Level" data-hi="स्तर">Level</span>
      <span id="lv" class="font-bold text-indigo-600">1</span> / 7
      &middot;
      <span class="es-bi" data-en="Q" data-hi="प्र">Q</span>
      <span id="qno">1</span> / 12
    </p>
    <p class="text-sm text-slate-500">
      <span class="es-bi" data-en="Time:" data-hi="समय:">Time:</span>
      <span id="tim">0.0</span>s
    </p>
  </div>
  <h2 id="qtxt" class="text-2xl font-bold mb-4 font-mono">…</h2>
  <div id="mcq" class="grid sm:grid-cols-2 gap-2"></div>
  <p id="fb" class="mt-4 text-sm h-5"></p>
</div>

<form id="form" method="post" class="hidden">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid" value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="log" id="logf">
</form>

<script>
const START_LEVEL = <?= (int)$start_level ?>;
const TOTAL_Q = 12;
const FAST = 6;     // seconds threshold for "fast"
let level = START_LEVEL;
let log = [];
let qStart = 0;
let timerHandle;
let currentAnswer = null;

const $lv = document.getElementById('lv');
const $qno = document.getElementById('qno');
const $q = document.getElementById('qtxt');
const $mcq = document.getElementById('mcq');
const $fb = document.getElementById('fb');
const $tim = document.getElementById('tim');

function rand(a,b){ return Math.floor(Math.random()*(b-a+1))+a; }
function gen(level) {
  let q='', ans=0;
  if (level === 1) {
    const a = rand(1,9);
    q = "How many? " + "🍎".repeat(a);
    ans = a;
  } else if (level === 2) {
    const a = rand(1,9), b = rand(1,9);
    if (Math.random() < 0.5) { q = a + " + " + b + " = ?"; ans = a+b; }
    else { const x = Math.max(a,b), y = Math.min(a,b); q = x + " − " + y + " = ?"; ans = x-y; }
  } else if (level === 3) {
    const a = rand(10,99), b = rand(10,99);
    if (Math.random() < 0.6) { q = a + " + " + b + " = ?"; ans = a+b; }
    else { const x = Math.max(a,b), y = Math.min(a,b); q = x + " − " + y + " = ?"; ans = x-y; }
  } else if (level === 4) {
    const a = rand(2,12), b = rand(2,12);
    if (Math.random() < 0.5) { q = a + " × " + b + " = ?"; ans = a*b; }
    else { const p = a*b; q = p + " ÷ " + a + " = ?"; ans = b; }
  } else if (level === 5) {
    const r = Math.random();
    if (r < 0.34) { const a = rand(20,500), p = [10,20,25,50][rand(0,3)]; q = p + "% of " + a + " = ?"; ans = +(a*p/100).toFixed(2); }
    else if (r < 0.67) { const a = (rand(10,99)/10).toFixed(1), b = (rand(10,99)/10).toFixed(1); q = a + " + " + b + " = ?"; ans = +(parseFloat(a)+parseFloat(b)).toFixed(1); }
    else { const items = rand(2,6), price = rand(10,40), disc = [10,20,25][rand(0,2)]; const total = items*price*(100-disc)/100; q = items + " items at ₹" + price + " each, " + disc + "% off. Total = ?"; ans = +total.toFixed(2); }
  } else if (level === 6) {
    const r = Math.random();
    if (r < 0.5) { const a = rand(2,9), x = rand(1,12), c = rand(1,30); const lhs = a*x + c; q = a + "x + " + c + " = " + lhs + ". x = ?"; ans = x; }
    else { const x = rand(2,12); if (Math.random() < 0.5) { q = x + "² = ?"; ans = x*x; } else { q = x + "³ = ?"; ans = x*x*x; } }
  } else { // 7
    const r = Math.random();
    if (r < 0.5) { const a = rand(1,9), b = rand(2,9), k = rand(2,5); q = "If " + a + ":" + b + " = " + (a*k) + ":?, find ?"; ans = b*k; }
    else { const total = rand(20,80), part = rand(1, total-1); q = "Bag has " + total + " balls, " + part + " red. P(red) as decimal? "; ans = +(part/total).toFixed(2); }
  }
  // Build 4 MCQ options
  const opts = new Set([ans]);
  while (opts.size < 4) {
    const noise = (typeof ans === 'number' && Math.abs(ans) > 1) ? rand(-Math.max(2, Math.round(Math.abs(ans)*0.3)), Math.max(2, Math.round(Math.abs(ans)*0.3))) : rand(-3,3);
    let cand = (typeof ans === 'number' && ans % 1 !== 0) ? +(parseFloat(ans) + noise/10).toFixed(2) : ans + noise;
    if (cand !== ans) opts.add(cand);
  }
  const arr = Array.from(opts).sort(()=>Math.random()-0.5);
  return { q, ans, opts: arr };
}

function render() {
  $lv.textContent = level;
  $qno.textContent = log.length + 1;
  const { q, ans, opts } = gen(level);
  currentAnswer = ans;
  $q.textContent = q;
  $mcq.innerHTML = '';
  $fb.textContent = '';
  opts.forEach(o => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'px-4 py-3 rounded-lg border border-slate-200 hover:border-indigo-400 hover:bg-indigo-50 text-lg font-mono';
    btn.textContent = o;
    btn.onclick = () => answer(o, ans, q);
    $mcq.appendChild(btn);
  });
  qStart = Date.now();
  if (timerHandle) clearInterval(timerHandle);
  timerHandle = setInterval(() => { $tim.textContent = ((Date.now()-qStart)/1000).toFixed(1); }, 100);
}

function answer(picked, ans, q) {
  const t = (Date.now() - qStart) / 1000;
  clearInterval(timerHandle);
  const correct = picked === ans;
  log.push({ q, picked, ans, correct, t: +t.toFixed(2), level });
  const isHi = (function(){ try { return localStorage.getItem('es_lang') === 'hi'; } catch(_){ return false; } })();
  $fb.textContent = correct
      ? (isHi ? '✓ सही' : '✓ Correct')
      : (isHi ? '✗ सही उत्तर था ' : '✗ Answer was ') + ans;
  $fb.className = 'mt-4 text-sm h-5 ' + (correct ? 'text-emerald-600' : 'text-rose-600');
  // Adapt
  if (correct && t < FAST && level < 7) level++;
  else if (!correct && level > 1) level--;
  if (log.length >= TOTAL_Q) { setTimeout(finish, 700); }
  else { setTimeout(render, 700); }
}

function finish() {
  document.getElementById('logf').value = JSON.stringify(log);
  document.getElementById('form').submit();
}
render();
</script>

<script>
(function () {
  function getLang() { try { return localStorage.getItem('es_lang') || 'en'; } catch(_){ return 'en'; } }
  function applyBi() {
    const lang = getLang();
    document.querySelectorAll('.es-bi').forEach(el => {
      const en = el.dataset.en, hi = el.dataset.hi;
      const target = (lang === 'hi' && hi) ? hi : (en || el.innerHTML);
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
</script>

<?php module_layout_close(); ?>
