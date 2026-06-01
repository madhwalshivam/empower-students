<?php
/**
 * child-eval.php?cid=N&module=mind_power
 *
 * Single parent-facing page that runs the adaptive evaluation for any of:
 *   speech | mind_power | behavior | general_awareness
 *
 * For speech, it redirects to /eval-speech.php (existing).
 * For the other 3, it runs the adaptive turn-by-turn engine.
 *
 * Flow:
 *   1. Pre-flight screen — "We're about to assess Aarav's Mind Power. Press Begin."
 *   2. Turn loop — show Q, child answers, score, encourage, next Q. 8-12 turns.
 *   3. Completion — show per-module mini-report inline + link to full report.
 *
 * Age-aware: for <8 it shows extra "hint_for_parent" lines + larger fonts.
 * For 8+ it's more game-like.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/child_eval_engine.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$cid = (int)($_GET['cid'] ?? 0);
$module = preg_replace('/[^a-z_]/', '', (string)($_GET['module'] ?? ''));
if (!$cid || !$module) {
    header('Location: /child-learn.php'); exit;
}

// Redirect speech to its dedicated page
if ($module === 'speech') {
    header('Location: /eval-speech.php?cid=' . $cid);
    exit;
}

// Verify child belongs to parent
$cst = db()->prepare("SELECT * FROM children WHERE id = ? AND parent_id = ?");
$cst->execute([$cid, $parent_id]);
$child = $cst->fetch();
if (!$child) { header('Location: /dashboard.php'); exit; }

$age = function_exists('calc_age_years') ? calc_age_years($child['dob']) : 7.0;
$is_child_led = $age >= 8;

// Module display info
$module_info = [
    'mind_power' => ['emoji' => '🧠', 'label' => 'Mind Power', 'label_hi' => 'दिमाग़ी ताक़त', 'color' => 'violet'],
    'behavior'   => ['emoji' => '💗', 'label' => 'Behavior',   'label_hi' => 'व्यवहार',       'color' => 'rose'],
    'general_awareness' => ['emoji' => '🌏', 'label' => 'General Knowledge', 'label_hi' => 'सामान्य ज्ञान', 'color' => 'amber'],
][$module] ?? ['emoji' => '✨', 'label' => ucfirst($module), 'label_hi' => '', 'color' => 'slate'];

// Start or resume session
$session_id = ce_start_session($cid, $module);
$session = ce_load_session($session_id);
$is_completed = ($session['status'] ?? '') === 'completed';

$color_map = [
    'violet' => ['from' => 'from-violet-600', 'to' => 'to-purple-600', 'bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'border' => 'border-violet-200', 'btn' => 'bg-violet-600 hover:bg-violet-700'],
    'rose'   => ['from' => 'from-rose-600',   'to' => 'to-pink-600',   'bg' => 'bg-rose-50',   'text' => 'text-rose-700',   'border' => 'border-rose-200',   'btn' => 'bg-rose-600 hover:bg-rose-700'],
    'amber'  => ['from' => 'from-amber-600',  'to' => 'to-orange-600', 'bg' => 'bg-amber-50',  'text' => 'text-amber-700',  'border' => 'border-amber-200',  'btn' => 'bg-amber-600 hover:bg-amber-700'],
    'slate'  => ['from' => 'from-slate-600',  'to' => 'to-slate-700',  'bg' => 'bg-slate-50',  'text' => 'text-slate-700',  'border' => 'border-slate-200',  'btn' => 'bg-slate-600 hover:bg-slate-700'],
];
$cl = $color_map[$module_info['color']] ?? $color_map['slate'];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($module_info['label']) ?> · <?= htmlspecialchars($child['name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  .hi { font-family: 'Noto Sans Devanagari', 'DM Sans', sans-serif; }
  @keyframes pulse { 0%, 100% { opacity: 1 } 50% { opacity: 0.4 } }
  .thinking { animation: pulse 1.4s ease-in-out infinite; }
  @keyframes fadein { from { opacity: 0; transform: translateY(8px) } to { opacity: 1; transform: translateY(0) } }
  .fade-in { animation: fadein 0.45s ease-out; }
  @keyframes confetti {
    0% { transform: translateY(-10px) rotate(0deg); opacity: 1 }
    100% { transform: translateY(80px) rotate(360deg); opacity: 0 }
  }
  .confetti span { display: inline-block; animation: confetti 0.9s ease-out; }
  details > summary::-webkit-details-marker { display: none; }
</style>
</head>
<body class="bg-slate-50 min-h-screen">

<header class="bg-white border-b border-slate-200 sticky top-0 z-10 shadow-sm">
  <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="/child-learn.php?cid=<?= $cid ?>" class="flex items-center gap-2 text-sm">
      <span class="text-slate-500">←</span>
      <span class="text-slate-700 font-medium">Back to Hub</span>
    </a>
    <div class="text-xs text-slate-500"><?= htmlspecialchars($child['name']) ?> · <?= round($age, 1) ?> yrs</div>
  </div>
</header>

<main class="max-w-2xl mx-auto px-4 py-6 space-y-4">

  <!-- Header card -->
  <section class="bg-gradient-to-br <?= $cl['from'] ?> <?= $cl['to'] ?> text-white rounded-2xl p-5 shadow-lg">
    <div class="flex items-center gap-4">
      <div class="text-5xl"><?= $module_info['emoji'] ?></div>
      <div class="flex-1">
        <h1 class="text-2xl font-bold leading-tight"><?= htmlspecialchars($module_info['label']) ?></h1>
        <p class="text-sm opacity-90 hi"><?= htmlspecialchars($module_info['label_hi']) ?></p>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
      <div class="bg-white/15 rounded-lg p-2 text-center">
        <div class="font-bold" id="progressTurn">—</div>
        <div class="opacity-85">turn</div>
      </div>
      <div class="bg-white/15 rounded-lg p-2 text-center">
        <div class="font-bold" id="progressAxis">—</div>
        <div class="opacity-85">checking</div>
      </div>
      <div class="bg-white/15 rounded-lg p-2 text-center">
        <div class="font-bold"><?= $is_child_led ? 'Child' : 'With parent' ?></div>
        <div class="opacity-85">mode</div>
      </div>
    </div>
  </section>

  <!-- Preflight (shown before first turn) -->
  <section id="screenPreflight" class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 <?= $is_completed ? 'hidden' : '' ?>">
    <h2 class="text-lg font-bold text-slate-900 mb-2">Ready to begin?</h2>
    <p class="text-sm text-slate-700 mb-4 leading-relaxed">
      We're going to ask <?= htmlspecialchars($child['name']) ?> about <?= 10 ?> short questions, calibrated to age <?= round($age, 1) ?>.
      The AI adapts difficulty based on each answer. Takes ~8-10 minutes.
    </p>
    <?php if (!$is_child_led): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-xs text-amber-900">
        👨‍👩‍👧 <strong>Parent-guided mode</strong> — read questions to <?= htmlspecialchars($child['name']) ?> and type their answers. We'll show you a hint with each question.
      </div>
    <?php else: ?>
      <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 mb-4 text-xs text-emerald-900">
        🌟 <strong>Self-led mode</strong> — <?= htmlspecialchars($child['name']) ?> can do this on their own. Stay nearby for encouragement!
      </div>
    <?php endif; ?>

    <button id="btnBegin" class="w-full py-4 px-6 <?= $cl['btn'] ?> text-white font-bold rounded-xl shadow-lg transition text-lg">
      ▶ Begin <?= htmlspecialchars($module_info['label']) ?> evaluation
    </button>
  </section>

  <!-- Question screen -->
  <section id="screenQuestion" class="hidden bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
    <div id="thinking" class="hidden text-center py-10">
      <div class="text-5xl thinking">🤔</div>
      <p class="text-sm text-slate-500 mt-3">Thinking of the next question…</p>
    </div>

    <div id="questionBlock" class="space-y-4">
      <div class="text-xs uppercase tracking-wider <?= $cl['text'] ?> font-bold" id="qLabel">Question 1</div>
      <div class="text-xl font-semibold text-slate-900 leading-relaxed" id="qPrompt"></div>
      <div id="qStimulus" class="<?= $cl['bg'] ?> <?= $cl['border'] ?> border-2 rounded-xl p-4 text-2xl font-bold text-slate-900 text-center hidden"></div>

      <div id="qHint" class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 italic hidden"></div>

      <!-- Answer input — varies by question type -->
      <div id="qAnswer">
        <!-- Either text input or options buttons inserted dynamically -->
      </div>

      <div id="qMsg" class="hidden text-sm rounded-lg p-3"></div>

      <button id="btnSubmit" class="w-full py-3 px-6 <?= $cl['btn'] ?> text-white font-bold rounded-xl transition">
        Submit answer
      </button>
    </div>
  </section>

  <!-- Between-turn feedback -->
  <section id="screenFeedback" class="hidden bg-white rounded-2xl p-6 shadow-sm border border-slate-200 fade-in">
    <div class="text-center">
      <div id="fbEmoji" class="text-6xl mb-3"></div>
      <div id="fbText" class="text-lg font-semibold text-slate-900 mb-4"></div>
      <button id="btnNext" class="py-3 px-6 <?= $cl['btn'] ?> text-white font-bold rounded-xl transition">
        Next question →
      </button>
    </div>
  </section>

  <!-- Completion -->
  <section id="screenDone" class="hidden bg-white rounded-2xl p-6 shadow-sm border border-slate-200 fade-in text-center">
    <div class="text-6xl mb-3">🎉</div>
    <h2 class="text-2xl font-bold text-slate-900 mb-1">All done, <?= htmlspecialchars($child['name']) ?>!</h2>
    <p class="text-sm text-slate-600 mb-5">Generating your evaluation report…</p>

    <div id="doneReport" class="hidden text-left space-y-4">
      <div class="<?= $cl['bg'] ?> <?= $cl['border'] ?> border-2 rounded-xl p-4">
        <div class="flex items-baseline justify-between mb-2">
          <span class="text-xs uppercase tracking-wider <?= $cl['text'] ?> font-bold">Overall</span>
          <span class="text-3xl font-bold <?= $cl['text'] ?>" id="doneScore">—</span>
        </div>
        <div class="text-sm font-bold text-slate-900" id="doneLevel">—</div>
        <p class="text-sm text-slate-700 mt-2 leading-relaxed hi" id="doneSummary">—</p>
      </div>

      <div>
        <h3 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">Strengths</h3>
        <ul id="doneStrengths" class="space-y-1 text-sm"></ul>
      </div>

      <div>
        <h3 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2">Areas to grow</h3>
        <ul id="doneGaps" class="space-y-2 text-sm"></ul>
      </div>

      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm">
        <strong class="text-amber-900">💡 This week's focus:</strong>
        <p class="text-amber-800 mt-1" id="doneFocus">—</p>
      </div>

      <a href="/child-learn.php?cid=<?= $cid ?>" class="block w-full text-center py-3 px-6 <?= $cl['btn'] ?> text-white font-bold rounded-xl">
        Back to Learning Hub →
      </a>
    </div>
  </section>

</main>

<script>
(function () {
  const SESSION_ID = <?= (int)$session_id ?>;
  const IS_COMPLETED = <?= $is_completed ? 'true' : 'false' ?>;
  const $ = id => document.getElementById(id);

  function show(id) {
    ['screenPreflight','screenQuestion','screenFeedback','screenDone'].forEach(s => {
      $(s).classList.toggle('hidden', s !== id);
    });
  }

  async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('session_id', SESSION_ID);
    for (const k in data) {
      const v = data[k];
      fd.append(k, typeof v === 'string' ? v : JSON.stringify(v));
    }
    const r = await fetch('/child-eval-api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  let currentQuestion = null;
  let currentAnswer = '';
  let questionShownAt = 0;   // ms timestamp when child can first see the answer input

  async function loadNextQuestion() {
    show('screenQuestion');
    $('thinking').classList.remove('hidden');
    $('questionBlock').classList.add('hidden');

    const r = await api('next', {});
    $('thinking').classList.add('hidden');
    $('questionBlock').classList.remove('hidden');

    if (!r.ok) {
      alert(r.error || 'Could not load next question');
      return;
    }
    if (r.done) {
      finalise();
      return;
    }

    currentQuestion = r.question || {};
    $('progressTurn').textContent = (r.turn_no || '?') + ' / ' + (r.total || '?');
    $('progressAxis').textContent = (r.axis || '?').replace(/_/g, ' ');
    $('qLabel').textContent = 'Question ' + (r.turn_no || '?');
    $('qPrompt').textContent = currentQuestion.prompt || '';

    // Stimulus (digits, words to remember, etc.)
    const hasStim = currentQuestion.stimulus && currentQuestion.stimulus !== currentQuestion.prompt;
    const isMemory = currentQuestion.memory_mode === true || currentQuestion.memory_mode === 'true';

    if (hasStim) {
      $('qStimulus').textContent = currentQuestion.stimulus;
      $('qStimulus').classList.remove('hidden');
    } else {
      $('qStimulus').classList.add('hidden');
    }

    // Hint for parent
    if (currentQuestion.hint_for_parent && <?= $is_child_led ? 'false' : 'true' ?>) {
      $('qHint').textContent = '💡 ' + currentQuestion.hint_for_parent;
      $('qHint').classList.remove('hidden');
    } else {
      $('qHint').classList.add('hidden');
    }

    // ── Memory-mode handling ──
    // For digit_span, word_recall, follow_instruction the stimulus must be HIDDEN
    // before the child can answer (true working-memory test).
    // For reasoning tasks (find_pattern, mental_math, etc.) the stimulus stays visible.
    if (hasStim && isMemory) {
      const displaySecs = Math.max(2, Math.min(15, parseInt(currentQuestion.display_seconds || 5, 10)));
      $('qAnswer').innerHTML = '';
      $('btnSubmit').disabled = true;
      $('btnSubmit').classList.add('opacity-50', 'cursor-not-allowed');

      // Show countdown overlay
      const countdownEl = document.createElement('div');
      countdownEl.id = 'qCountdown';
      countdownEl.className = 'text-center mt-3 text-xs text-slate-500 font-semibold';
      countdownEl.textContent = '👀 Look carefully · disappearing in ' + displaySecs + 's';
      $('qStimulus').after(countdownEl);

      let remaining = displaySecs;
      const tick = setInterval(() => {
        remaining--;
        if (remaining > 0) {
          countdownEl.textContent = '👀 Look carefully · disappearing in ' + remaining + 's';
        } else {
          clearInterval(tick);
          // Hide stimulus
          $('qStimulus').style.transition = 'opacity 0.5s';
          $('qStimulus').style.opacity = '0';
          setTimeout(() => {
            $('qStimulus').classList.add('hidden');
            $('qStimulus').style.opacity = '1';
            countdownEl.remove();
            // Reveal answer input
            buildAnswerInput(currentQuestion);
            $('btnSubmit').disabled = false;
            $('btnSubmit').classList.remove('opacity-50', 'cursor-not-allowed');
            questionShownAt = Date.now();   // start the response-time clock NOW
          }, 500);
        }
      }, 1000);
    } else {
      // Normal flow — stimulus stays visible, answer input shown immediately
      buildAnswerInput(currentQuestion);
      $('btnSubmit').disabled = false;
      $('btnSubmit').classList.remove('opacity-50', 'cursor-not-allowed');
      questionShownAt = Date.now();   // start clock immediately
    }

    $('qMsg').classList.add('hidden');
  }

  function buildAnswerInput(q) {
    const wrap = $('qAnswer');
    wrap.innerHTML = '';
    currentAnswer = '';

    if (Array.isArray(q.options) && q.options.length > 0) {
      // Choice buttons
      q.options.forEach((opt, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'w-full p-3 mb-2 text-left border-2 border-slate-200 rounded-lg hover:border-violet-400 hover:bg-violet-50 text-sm font-medium text-slate-800';
        b.textContent = String.fromCharCode(65 + i) + '. ' + opt;
        b.onclick = () => {
          wrap.querySelectorAll('button').forEach(x => x.classList.remove('border-violet-500', 'bg-violet-50'));
          b.classList.add('border-violet-500', 'bg-violet-50');
          currentAnswer = opt;
        };
        wrap.appendChild(b);
      });
    } else {
      // Text input
      const input = document.createElement('textarea');
      input.id = 'answerInput';
      input.rows = (q.expected_format === 'list' || q.expected_format === 'sequence') ? 3 : 2;
      input.className = 'w-full p-3 border-2 border-slate-300 rounded-lg text-base focus:border-violet-500 focus:ring-2 focus:ring-violet-100 focus:outline-none';
      input.placeholder = 'Type the answer here…';
      input.oninput = () => { currentAnswer = input.value; };
      wrap.appendChild(input);
      setTimeout(() => input.focus(), 100);
    }
  }

  $('btnSubmit').addEventListener('click', async () => {
    if (!currentAnswer || (typeof currentAnswer === 'string' && currentAnswer.trim() === '')) {
      const msg = $('qMsg');
      msg.textContent = 'Please answer first.';
      msg.classList.remove('hidden');
      msg.className = 'mt-2 text-sm rounded-lg p-3 bg-rose-50 border border-rose-200 text-rose-800';
      return;
    }

    const btn = $('btnSubmit');
    btn.disabled = true;
    btn.textContent = 'Scoring…';

    // Compute response time
    const responseSecs = questionShownAt > 0 ? Math.round((Date.now() - questionShownAt) / 100) / 10 : 0;
    const answerPayload = JSON.stringify({
      text: typeof currentAnswer === 'string' ? currentAnswer : String(currentAnswer),
      response_seconds: responseSecs,
    });

    const r = await api('answer', { answer: answerPayload });
    btn.disabled = false;
    btn.textContent = 'Submit answer';

    if (!r.ok) {
      alert(r.error || 'Submit failed');
      return;
    }

    // Show feedback screen
    $('fbEmoji').textContent = r.is_correct ? '✨' : '💪';
    $('fbText').textContent = r.feedback || (r.is_correct ? 'Great job!' : 'Good try!');
    show('screenFeedback');
  });

  $('btnNext').addEventListener('click', () => {
    loadNextQuestion();
  });

  async function finalise() {
    show('screenDone');
    const r = await api('finalise', {});
    if (!r.ok || !r.report) {
      alert('Could not generate report');
      return;
    }
    $('doneReport').classList.remove('hidden');
    const rep = r.report;
    $('doneScore').textContent = (rep.overall_score || 0) + '/100';
    $('doneLevel').textContent = (rep.level || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    $('doneSummary').textContent = rep.summary || '';

    const strengths = $('doneStrengths');
    (rep.strengths || []).forEach(s => {
      const li = document.createElement('li');
      li.innerHTML = '<span class="text-emerald-600 font-bold">✓</span> ' + s;
      strengths.appendChild(li);
    });
    if ((rep.strengths || []).length === 0) {
      strengths.innerHTML = '<li class="text-slate-500 italic">Continuing to develop.</li>';
    }

    const gaps = $('doneGaps');
    (rep.gaps || []).forEach(g => {
      const li = document.createElement('li');
      li.className = '<?= $cl['bg'] ?> <?= $cl['border'] ?> border rounded-lg p-2.5';
      li.innerHTML = '<div class="font-semibold text-slate-900 text-sm">' + (g.label || g.axis || '') + '</div><div class="text-xs text-slate-700 mt-0.5">' + (g.description || '') + '</div>';
      gaps.appendChild(li);
    });
    if ((rep.gaps || []).length === 0) {
      gaps.innerHTML = '<li class="text-slate-500 italic">No major gaps identified.</li>';
    }

    $('doneFocus').textContent = rep.recommended_focus || '';
  }

  $('btnBegin').addEventListener('click', () => loadNextQuestion());

  // If session was already completed, jump straight to done view
  if (IS_COMPLETED) {
    finalise();
  }
})();
</script>
</body>
</html>
