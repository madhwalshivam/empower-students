<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'general_awareness', $band);

/**
 * Adaptive principle:
 *   Levels 1..5 (easiest -> hardest).
 *   Start at level 1.
 *   Correct + fast (<6s) -> level up.
 *   Correct slow (>=6s) -> stay.
 *   Wrong -> level down by one (floor 1).
 *   2 minutes total. End when timer ends OR 5 questions at the same level pass.
 */

$bank = [
    1 => [
        ['q' => 'Which one is a fruit?',           'opts' => ['Apple','Chair','Car','Sun'],          'a' => 0],
        ['q' => 'Which animal says "meow"?',        'opts' => ['Cow','Dog','Cat','Goat'],             'a' => 2],
        ['q' => 'What colour is the sky on a clear day?', 'opts' => ['Green','Blue','Red','Black'], 'a' => 1],
        ['q' => 'How many legs does a dog have?',  'opts' => ['2','4','6','8'],                      'a' => 1],
        ['q' => 'Which one do we drink?',           'opts' => ['Bread','Water','Stone','Plate'],     'a' => 1],
    ],
    2 => [
        ['q' => 'Capital of India?',                'opts' => ['Mumbai','Delhi','Chennai','Kolkata'],  'a' => 1],
        ['q' => 'Sun rises in the…',                 'opts' => ['North','South','East','West'],         'a' => 2],
        ['q' => 'Which is the largest planet?',      'opts' => ['Earth','Mars','Jupiter','Mercury'],     'a' => 2],
        ['q' => 'How many days are in a week?',      'opts' => ['5','6','7','8'],                       'a' => 2],
        ['q' => 'Which is the National animal of India?', 'opts' => ['Lion','Tiger','Elephant','Peacock'], 'a' => 1],
    ],
    3 => [
        ['q' => 'Which gas do we breathe in?',       'opts' => ['Carbon dioxide','Oxygen','Nitrogen','Hydrogen'], 'a' => 1],
        ['q' => 'Who wrote the Indian National Anthem?', 'opts' => ['Tagore','Gandhi','Nehru','Bose'], 'a' => 0],
        ['q' => 'What is H₂O?',                     'opts' => ['Salt','Water','Sugar','Sand'], 'a' => 1],
        ['q' => 'How many continents are there?',    'opts' => ['5','6','7','8'], 'a' => 2],
        ['q' => 'Currency of Japan?',                'opts' => ['Yen','Won','Yuan','Rupee'], 'a' => 0],
    ],
    4 => [
        ['q' => 'Speed of light is approximately…', 'opts' => ['3 × 10⁸ m/s','3 × 10⁵ m/s','3 × 10¹⁰ m/s','3 × 10⁶ m/s'], 'a' => 0],
        ['q' => 'Who proposed the theory of relativity?', 'opts' => ['Newton','Einstein','Bohr','Tesla'], 'a' => 1],
        ['q' => 'Which is the deepest ocean trench?',     'opts' => ['Java','Mariana','Puerto Rico','Tonga'], 'a' => 1],
        ['q' => 'Year India became a republic?',           'opts' => ['1947','1948','1950','1952'], 'a' => 2],
        ['q' => 'Smallest unit of life?',                  'opts' => ['Atom','Cell','Tissue','Organ'], 'a' => 1],
    ],
    5 => [
        ['q' => 'pH of pure water at 25°C?',               'opts' => ['5','6','7','8'], 'a' => 2],
        ['q' => 'GDP stands for?',                         'opts' => ['General Domestic Product','Gross Domestic Product','Government Direct Pay','Gross Direct Pay'], 'a' => 1],
        ['q' => 'Which Indian PM gave the slogan "Jai Jawan Jai Kisan"?', 'opts' => ['Nehru','Shastri','Indira','Vajpayee'], 'a' => 1],
        ['q' => 'Which is NOT a noble gas?',                'opts' => ['Argon','Neon','Nitrogen','Helium'], 'a' => 2],
        ['q' => 'Author of "A Brief History of Time"?',     'opts' => ['Hawking','Sagan','Dawkins','Greene'], 'a' => 0],
    ],
];

// Starting level by age band
$start_level = ['toddler' => 1, 'child' => 2, 'preteen' => 3, 'teen' => 4][$band] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $log = json_decode($_POST['log'] ?? '[]', true) ?: [];
    if (empty($log)) {
        header('Location: /modules/general_awareness.php?cid=' . (int)$child['id']);
        exit;
    }
    $correct = 0; $total = count($log); $max_level = 1; $sum_t = 0;
    foreach ($log as $row) {
        if (!empty($row['correct'])) $correct++;
        if (!empty($row['level']) && (int)$row['level'] > $max_level) $max_level = (int)$row['level'];
        $sum_t += (float)($row['t'] ?? 0);
    }
    $avg_t = $total ? round($sum_t / $total, 2) : 0;
    $score = $total ? round($correct * 100 / $total, 1) : 0;

    $sys = "You are a child education specialist. Plain language, encouraging, never sarcastic.";
    $user = "Adaptive general-awareness quiz for " . $child['name'] . " (age " . round((float)$age, 1)
          . " yrs, band " . $band . "): "
          . $correct . "/" . $total . " correct, max level reached: " . $max_level . "/5, avg response time: " . $avg_t . "s. "
          . "Write 4-5 sentences: estimate the child's general-awareness level vs age, note speed-vs-accuracy pattern, "
          . "and suggest 2 concrete enrichment activities (books / games / talk-prompts) parents can do this week.";
    $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 400, 0.5);
    if ($summary === '') $summary = 'Saved.';

    finalize_assessment($assessment['id'], $score, 'L' . $max_level, $summary, [], $log);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'General awareness · adaptive');
?>
<p class="text-slate-600 mb-4 max-w-3xl es-bi"
   data-en="A quick 2-minute quiz that adapts to <?= e($child['name']) ?>&rsquo;s level. Easy questions first &mdash; we step up only when answers are correct and quick."
   data-hi="2 मिनट की एक त्वरित प्रश्नोत्तरी जो <?= e($child['name']) ?> के स्तर के अनुसार बदलती है। पहले आसान प्रश्न — सही और जल्दी उत्तर पर ही स्तर बढ़ता है।">
A quick 2-minute quiz that adapts to <?= e($child['name']) ?>&rsquo;s level.
Easy questions first &mdash; we step up only when answers are correct and quick.</p>

<div id="ga-app" class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm">
  <div class="flex items-center justify-between mb-4">
    <p class="text-sm text-slate-500">
      <span class="es-bi" data-en="Level" data-hi="स्तर">Level</span>
      <span id="ga-level" class="font-bold text-indigo-600">1</span> / 5
      &middot;
      <span class="es-bi" data-en="Q" data-hi="प्र">Q</span>
      <span id="ga-qno">1</span>
    </p>
    <p class="text-sm text-slate-500">
      <span class="es-bi" data-en="Time left:" data-hi="शेष समय:">Time left:</span>
      <span id="ga-timer" class="font-bold text-rose-600">2:00</span>
    </p>
  </div>
  <h2 id="ga-q" class="text-lg font-semibold mb-4">…</h2>
  <div id="ga-opts" class="grid sm:grid-cols-2 gap-2"></div>
  <p id="ga-feedback" class="mt-4 text-sm h-5"></p>
</div>

<form id="ga-form" method="post" class="hidden">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid" value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="log" id="ga-log">
</form>

<script>
const BANK = <?= json_encode($bank) ?>;
const START_LEVEL = <?= (int)$start_level ?>;
const TIME_BUDGET = 120;          // seconds
const FAST_THRESHOLD = 6;         // s — level-up if correct under this
const SAME_LEVEL_PASS = 5;        // questions at same level allowed before forced end
let level = START_LEVEL;
let log = [];
let qStart = 0;
let used = {1:[],2:[],3:[],4:[],5:[]};
let levelStreak = 0;

const $level = document.getElementById('ga-level');
const $qno   = document.getElementById('ga-qno');
const $q     = document.getElementById('ga-q');
const $opts  = document.getElementById('ga-opts');
const $fb    = document.getElementById('ga-feedback');
const $timer = document.getElementById('ga-timer');

function pickQuestion() {
  // Try this level, then descend
  for (let l = level; l >= 1; l--) {
    const pool = BANK[l].filter((_, i) => !used[l].includes(i));
    if (pool.length) {
      const realIdx = BANK[l].findIndex((q, i) => !used[l].includes(i));
      used[l].push(realIdx);
      return { item: BANK[l][realIdx], lvl: l };
    }
  }
  // Try ascending
  for (let l = level + 1; l <= 5; l++) {
    const pool = BANK[l].filter((_, i) => !used[l].includes(i));
    if (pool.length) {
      const realIdx = BANK[l].findIndex((q, i) => !used[l].includes(i));
      used[l].push(realIdx);
      return { item: BANK[l][realIdx], lvl: l };
    }
  }
  return null;
}

function render() {
  const picked = pickQuestion();
  if (!picked) { finish('done'); return; }
  level = picked.lvl;
  $level.textContent = level;
  $qno.textContent = log.length + 1;
  $q.textContent = picked.item.q;
  $opts.innerHTML = '';
  $fb.textContent = '';
  picked.item.opts.forEach((opt, i) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'text-left px-4 py-3 rounded-lg border border-slate-200 hover:border-indigo-400 hover:bg-indigo-50';
    btn.textContent = opt;
    btn.onclick = () => answer(i, picked);
    $opts.appendChild(btn);
  });
  qStart = Date.now();
}

function answer(i, picked) {
  const t = (Date.now() - qStart) / 1000;
  const correct = i === picked.item.a;
  log.push({ q: picked.item.q, lvl: picked.lvl, a: i, correct, t: +t.toFixed(2), level: picked.lvl });
  const isHi = (function(){ try { return localStorage.getItem('es_lang') === 'hi'; } catch(_){ return false; } })();
  $fb.textContent = correct
      ? (isHi ? '✓ सही' : '✓ Correct')
      : (isHi ? '✗ सही उत्तर: ' : '✗ Correct answer: ') + picked.item.opts[picked.item.a];
  $fb.className = 'mt-4 text-sm h-5 ' + (correct ? 'text-emerald-600' : 'text-rose-600');
  // Adapt
  if (correct && t < FAST_THRESHOLD && level < 5) { level++; levelStreak = 0; }
  else if (!correct && level > 1) { level--; levelStreak = 0; }
  else { levelStreak++; if (levelStreak >= SAME_LEVEL_PASS) { /* same level too long, push down */ levelStreak = 0; if (level > 1) level--; } }
  setTimeout(render, 700);
}

function finish() {
  document.getElementById('ga-log').value = JSON.stringify(log);
  document.getElementById('ga-form').submit();
}

let remaining = TIME_BUDGET;
function tick() {
  const m = Math.floor(remaining/60), s = remaining % 60;
  $timer.textContent = m + ':' + s.toString().padStart(2,'0');
  if (remaining <= 0) { finish(); return; }
  remaining--;
  setTimeout(tick, 1000);
}
render(); tick();
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
