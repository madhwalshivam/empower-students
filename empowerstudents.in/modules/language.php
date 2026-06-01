<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'language', $band);

if ($band === 'infant') {
    module_layout_open($child, 'Language');
    echo '<div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-amber-900">'
       . 'Use the <strong>Behaviour</strong> module for infants — it covers babbling and first-word milestones.'
       . '</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $payload = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    $word_correct  = (int)($payload['word_correct']  ?? 0);
    $word_total    = (int)($payload['word_total']    ?? 0);
    $word_level    = (int)($payload['word_level']    ?? 1);
    $comp_correct  = (int)($payload['comp_correct']  ?? 0);
    $comp_total    = (int)($payload['comp_total']    ?? 0);
    $comp_time_ms  = (int)($payload['comp_time_ms']  ?? 0);
    $passage_words = (int)($payload['passage_words'] ?? 0);
    $wpm           = ($comp_time_ms > 0) ? round($passage_words / max(1, $comp_time_ms / 60000), 1) : null;

    $word_pct = $word_total ? round($word_correct * 100 / $word_total, 1) : null;
    $comp_pct = $comp_total ? round($comp_correct * 100 / $comp_total, 1) : null;
    $overall  = ($word_pct !== null && $comp_pct !== null) ? round(($word_pct + $comp_pct) / 2, 1) : ($word_pct ?? $comp_pct);

    $sys = "You are a paediatric language and reading specialist. Brief, parent-friendly. Indian context.";
    $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs ($band).\n"
          . "Word-power MCQ: $word_correct / $word_total correct, top level reached: $word_level (1 easy → 5 advanced).\n"
          . "Reading comprehension: $comp_correct / $comp_total correct, reading speed " . ($wpm ?? 'n/a') . " words/min.\n"
          . "In 5-7 sentences: comment on vocabulary level vs age, reading speed vs age (Indian school benchmarks), comprehension strength, and 3 specific home activities to grow language. Plain text.";
    $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 600, 0.4);
    if ($summary === '') $summary = "Word-power: $word_pct%. Comprehension: $comp_pct%. Reading speed: " . ($wpm ?? 'n/a') . " wpm.";

    $flags = [];
    if ($word_pct !== null && $word_pct < 40) $flags[] = ['q' => 'Word power below age', 'a' => $word_pct];
    if ($comp_pct !== null && $comp_pct < 40) $flags[] = ['q' => 'Comprehension below age', 'a' => $comp_pct];

    finalize_assessment($assessment['id'], $overall, $band, $summary, $flags, [
        'word' => ['correct' => $word_correct, 'total' => $word_total, 'level' => $word_level],
        'comp' => ['correct' => $comp_correct, 'total' => $comp_total, 'time_ms' => $comp_time_ms, 'wpm' => $wpm],
    ]);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

// Word-power bank by level (1 easy → 5 advanced). Each item: word + 4 options + correctIndex.
// Adaptive: correct → next level; wrong → stay/down.
$word_bank = [
    1 => [
        ['w' => 'big',     'o' => ['large', 'small', 'cold', 'red'],         'c' => 0],
        ['w' => 'happy',   'o' => ['sad', 'glad', 'tired', 'angry'],         'c' => 1],
        ['w' => 'fast',    'o' => ['slow', 'quick', 'soft', 'late'],         'c' => 1],
        ['w' => 'begin',   'o' => ['stop', 'finish', 'start', 'wait'],       'c' => 2],
        ['w' => 'tiny',    'o' => ['huge', 'pretty', 'small', 'noisy'],      'c' => 2],
    ],
    2 => [
        ['w' => 'brave',     'o' => ['scared', 'bold', 'shy', 'silent'],         'c' => 1],
        ['w' => 'gloomy',    'o' => ['cheerful', 'dark and sad', 'sunny', 'fast'],'c' => 1],
        ['w' => 'curious',   'o' => ['bored', 'hungry', 'eager to know', 'angry'],'c' => 2],
        ['w' => 'enormous',  'o' => ['tiny', 'tasty', 'very large', 'noisy'],    'c' => 2],
        ['w' => 'whisper',   'o' => ['shout', 'speak softly', 'sing', 'cry'],    'c' => 1],
    ],
    3 => [
        ['w' => 'reluctant',  'o' => ['eager', 'unwilling', 'tired', 'silly'],         'c' => 1],
        ['w' => 'abundant',   'o' => ['rare', 'plenty', 'broken', 'small'],            'c' => 1],
        ['w' => 'ancient',    'o' => ['new', 'very old', 'noisy', 'kind'],             'c' => 1],
        ['w' => 'accomplish', 'o' => ['fail', 'achieve', 'forget', 'argue'],           'c' => 1],
        ['w' => 'fragile',    'o' => ['strong', 'easily broken', 'wet', 'fast'],       'c' => 1],
    ],
    4 => [
        ['w' => 'meticulous', 'o' => ['careless', 'very careful and precise', 'lazy', 'rude'], 'c' => 1],
        ['w' => 'benevolent', 'o' => ['cruel', 'kind and generous', 'tired', 'hungry'],        'c' => 1],
        ['w' => 'arduous',    'o' => ['easy', 'difficult and tiring', 'short', 'tasty'],      'c' => 1],
        ['w' => 'candid',     'o' => ['secretive', 'frank and honest', 'angry', 'foreign'],   'c' => 1],
        ['w' => 'lethargic',  'o' => ['energetic', 'slow and sluggish', 'happy', 'noisy'],    'c' => 1],
    ],
    5 => [
        ['w' => 'ephemeral',     'o' => ['lasting forever', 'short-lived', 'expensive', 'colourful'], 'c' => 1],
        ['w' => 'ubiquitous',    'o' => ['rare', 'present everywhere', 'broken', 'cold'],             'c' => 1],
        ['w' => 'pragmatic',     'o' => ['idealistic', 'practical and realistic', 'lazy', 'shy'],     'c' => 1],
        ['w' => 'cacophony',     'o' => ['silence', 'harsh mix of sounds', 'song', 'rhyme'],          'c' => 1],
        ['w' => 'serendipitous', 'o' => ['planned', 'happily by chance', 'painful', 'expensive'],     'c' => 1],
    ],
];
$start_level = ($band === 'toddler') ? 1 : (($band === 'child') ? 2 : (($band === 'preteen') ? 3 : 4));

// Comprehension passage by band
$passages = [
    'toddler' => [
        'text' => 'Mira has a small white kitten. The kitten loves to chase a red ball. Every morning, Mira gives it warm milk in a yellow bowl. At night, the kitten sleeps on Mira\'s pillow.',
        'q' => [
            ['q' => 'What colour is the kitten?',       'o' => ['Black', 'White', 'Yellow', 'Red'], 'c' => 1],
            ['q' => 'What does the kitten chase?',      'o' => ['A mouse', 'A red ball', 'A bird', 'A car'], 'c' => 1],
            ['q' => 'Where does the kitten sleep at night?', 'o' => ['On the floor', 'In the bowl', 'On Mira\'s pillow', 'Outside'], 'c' => 2],
        ],
    ],
    'child' => [
        'text' => 'Arjun was nervous before the school sports day. He had practised running every evening for a month. When the whistle blew, he forgot his fear and ran as fast as he could. He came second, not first, but his coach said his effort and improvement mattered more than the position. Arjun smiled — he agreed.',
        'q' => [
            ['q' => 'How long had Arjun practised?',  'o' => ['A week', 'A month', 'A year', 'Two days'], 'c' => 1],
            ['q' => 'What position did Arjun finish?', 'o' => ['First', 'Second', 'Third', 'Last'], 'c' => 1],
            ['q' => 'What did the coach value most?', 'o' => ['Winning', 'Effort and improvement', 'Speed', 'The trophy'], 'c' => 1],
            ['q' => 'How did Arjun feel at the end?', 'o' => ['Angry', 'Sad', 'Pleased', 'Confused'], 'c' => 2],
        ],
    ],
    'preteen' => [
        'text' => 'In the small town of Devnagar, the only library had been shut for years. Twelve-year-old Riya could not bear to see books gathering dust. With her friends, she swept the floors, repaired the shelves, and asked neighbours to donate books they no longer read. Within three months, the library was open again — not as grand as before, but full of life. The mayor called it a quiet revolution.',
        'q' => [
            ['q' => 'Why was the library important to Riya?',     'o' => ['She owned it', 'She loved books', 'She lived there', 'It was new'], 'c' => 1],
            ['q' => 'What did Riya and her friends do first?',     'o' => ['Bought new books', 'Cleaned and repaired', 'Built a new building', 'Asked the mayor'], 'c' => 1],
            ['q' => 'How long did the project take?',              'o' => ['One week', 'Three months', 'A year', 'Two years'], 'c' => 1],
            ['q' => 'What does "quiet revolution" mean here?',     'o' => ['A loud protest', 'A small but powerful change', 'A war', 'A school project'], 'c' => 1],
        ],
    ],
    'teen' => [
        'text' => 'The myth of the lone genius is comforting but misleading. Almost every breakthrough we now attribute to a single name — Newton, Curie, Ramanujan — was built on years of correspondence, criticism and shared notebooks. Even Darwin\'s theory of evolution was sharpened by his ten-year exchange with Wallace. Genius rarely works in silence; it argues, drafts, and revises in the company of peers. To celebrate only the final author is to misunderstand how knowledge is actually made.',
        'q' => [
            ['q' => 'The author\'s main argument is that genius:', 'o' => ['Is purely individual', 'Emerges through collaboration', 'Is rare today', 'Cannot be measured'], 'c' => 1],
            ['q' => 'Why is Wallace mentioned?',                    'o' => ['He worked alone', 'He sharpened Darwin\'s ideas', 'He opposed evolution', 'He taught Newton'], 'c' => 1],
            ['q' => 'The phrase "lone genius" is described as:',    'o' => ['Accurate', 'Comforting but misleading', 'Modern', 'Scientific'], 'c' => 1],
            ['q' => 'The author would most likely agree that:',     'o' => ['Solo work is best', 'Peer review hurts science', 'Shared dialogue makes knowledge', 'Old scientists were wrong'], 'c' => 2],
        ],
    ],
];
$passage = $passages[$band] ?? $passages['child'];
$passage_words = str_word_count($passage['text']);

module_layout_open($child, 'Language');
?>
<div id="step-intro" class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm">
  <h2 class="text-xl font-semibold mb-2 es-bi" data-en="Two short tasks" data-hi="दो छोटे कार्य">Two short tasks</h2>
  <ol class="list-decimal pl-6 text-slate-700 space-y-1 text-sm">
    <li class="es-bi"
        data-en="<strong>Word power</strong> — pick the closest meaning. Difficulty climbs as you get them right."
        data-hi="<strong>शब्द-शक्ति</strong> — सबसे क़रीबी अर्थ चुनें। सही जवाब पर कठिनाई बढ़ती है।">
      <strong>Word power</strong> — pick the closest meaning. Difficulty climbs as you get them right.
    </li>
    <li class="es-bi"
        data-en="<strong>Reading comprehension</strong> — read a short passage (timed), then answer a few questions."
        data-hi="<strong>पठन समझ</strong> — एक छोटा गद्यांश पढ़ें (समय के साथ), फिर कुछ प्रश्नों के उत्तर दें।">
      <strong>Reading comprehension</strong> — read a short passage (timed), then answer a few questions.
    </li>
  </ol>
  <button id="startWP" class="brand-grad text-white px-5 py-2.5 rounded-lg mt-4 es-bi"
          data-en="Start word power" data-hi="शब्द-शक्ति शुरू करें">Start word power</button>
</div>

<div id="step-word" class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm hidden">
  <div class="flex items-center justify-between mb-3 text-xs text-slate-500">
    <span><span class="es-bi" data-en="Word power · Level" data-hi="शब्द-शक्ति · स्तर">Word power · Level</span> <span id="lvl">1</span></span>
    <span><span id="wpQ">0</span> / 8</span>
  </div>
  <p id="wpWord" class="text-2xl font-bold mb-4">—</p>
  <div id="wpOpts" class="grid sm:grid-cols-2 gap-2"></div>
</div>

<div id="step-passage" class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm hidden">
  <div class="flex items-center justify-between mb-3 text-xs text-slate-500">
    <span class="es-bi" data-en="Reading passage" data-hi="पठन गद्यांश">Reading passage</span>
    <span>
      <span class="es-bi" data-en="Timer:" data-hi="समय:">Timer:</span>
      <span id="readTimer">0.0</span>s
    </span>
  </div>
  <p class="text-slate-800 leading-relaxed text-base"><?= e($passage['text']) ?></p>
  <button id="doneRead" class="brand-grad text-white px-5 py-2.5 rounded-lg mt-5 es-bi"
          data-en="Done reading" data-hi="पढ़ना पूरा">Done reading</button>
</div>

<div id="step-comp" class="hidden space-y-4"></div>

<form method="post" id="endForm" class="hidden mt-6">
  <input type="hidden" name="csrf"    value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid"     value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="payload" id="payload">
  <button class="brand-grad text-white px-5 py-2.5 rounded-lg es-bi"
          data-en="Save &amp; analyse" data-hi="सहेजें और विश्लेषण">Save &amp; analyse</button>
</form>

<script>
const wordBank   = <?= json_encode($word_bank) ?>;
const startLevel = <?= (int)$start_level ?>;
const compQs     = <?= json_encode($passage['q']) ?>;
const passWords  = <?= (int)$passage_words ?>;

let lvl = startLevel, wpAsked = 0, wpCorrect = 0, wpMaxLvl = startLevel, used = {};
const TOTAL_WP = 8;

function $(id){ return document.getElementById(id); }
function show(id){ ['intro','word','passage','comp'].forEach(s => $('step-' + s).classList.add('hidden')); $('step-' + id).classList.remove('hidden'); }

$('startWP').onclick = () => { show('word'); nextWord(); };

function nextWord() {
  if (wpAsked >= TOTAL_WP) return startReading();
  $('lvl').textContent = lvl;
  $('wpQ').textContent = wpAsked;
  const pool = wordBank[lvl] || wordBank[1];
  used[lvl] = used[lvl] || new Set();
  let pick;
  for (const it of pool) if (!used[lvl].has(it.w)) { pick = it; break; }
  if (!pick) { used[lvl].clear(); pick = pool[0]; }
  used[lvl].add(pick.w);
  $('wpWord').textContent = pick.w;
  const opts = $('wpOpts'); opts.innerHTML = '';
  pick.o.forEach((opt, idx) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'text-left bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg px-4 py-3';
    b.textContent = opt;
    b.onclick = () => answer(idx === pick.c);
    opts.appendChild(b);
  });
}

function answer(correct) {
  wpAsked++;
  if (correct) { wpCorrect++; lvl = Math.min(5, lvl + 1); wpMaxLvl = Math.max(wpMaxLvl, lvl); }
  else         { lvl = Math.max(1, lvl - 1); }
  setTimeout(nextWord, 150);
}

let readStart = 0, readTimer;
function startReading() {
  show('passage');
  readStart = Date.now();
  readTimer = setInterval(() => {
    $('readTimer').textContent = ((Date.now() - readStart) / 1000).toFixed(1);
  }, 100);
}

$('doneRead').onclick = () => {
  clearInterval(readTimer);
  const dur = Date.now() - readStart;
  $('payload').value = JSON.stringify({reading_time_ms: dur});
  renderComp(dur);
};

function renderComp(readMs) {
  show('comp');
  const wrap = $('step-comp'); wrap.innerHTML = '';
  const answers = Array(compQs.length).fill(null);
  compQs.forEach((q, i) => {
    const card = document.createElement('div');
    card.className = 'bg-white rounded-2xl border border-slate-100 p-5 shadow-sm';
    card.innerHTML = '<p class="font-medium mb-3">' + (i + 1) + '. ' + q.q + '</p>';
    const opts = document.createElement('div'); opts.className = 'grid sm:grid-cols-2 gap-2';
    q.o.forEach((opt, idx) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'text-left bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg px-4 py-3';
      b.textContent = opt;
      b.onclick = () => {
        answers[i] = idx;
        opts.querySelectorAll('button').forEach(x => x.classList.remove('ring-2','ring-indigo-500'));
        b.classList.add('ring-2','ring-indigo-500');
        if (answers.every(a => a !== null)) $('endForm').classList.remove('hidden');
      };
      opts.appendChild(b);
    });
    card.appendChild(opts);
    wrap.appendChild(card);
  });

  $('endForm').addEventListener('submit', () => {
    const compCorrect = answers.reduce((s, a, i) => s + (a === compQs[i].c ? 1 : 0), 0);
    $('payload').value = JSON.stringify({
      word_correct:  wpCorrect,
      word_total:    TOTAL_WP,
      word_level:    wpMaxLvl,
      comp_correct:  compCorrect,
      comp_total:    compQs.length,
      comp_time_ms:  readMs,
      passage_words: passWords,
    });
  });
}
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
      el.innerHTML = ta.value;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
