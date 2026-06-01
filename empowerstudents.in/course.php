<?php
/**
 * course.php?id=N
 *
 * Main parent-facing page for the 7-day speech improvement course.
 *
 * Page shows different states based on course status:
 *   - active + day pending: Today's task + record anchor button + progress chart so far
 *   - active + day completed: "Come back tomorrow" + progress chart so far
 *   - completed: Final progress chart + Day 1 vs Day 7 delta + Week 2 upsell
 *   - failed: "Course expired (missed too many days)" + Buy again CTA
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/course_engine.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$course_id = (int)($_GET['id'] ?? 0);
$cst = db()->prepare("SELECT c.*, ch.name AS child_name, ch.dob AS child_dob, ch.mother_tongue AS child_mt
                      FROM eval_courses c JOIN children ch ON ch.id = c.child_id
                      WHERE c.id = ? AND c.parent_id = ?");
$cst->execute([$course_id, $parent_id]);
$course = $cst->fetch();

if (!$course) {
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="max-w-3xl mx-auto px-4 py-12">
      <div class="bg-white border border-slate-200 rounded-2xl p-8 text-center">
        <div class="text-5xl mb-4">🤔</div>
        <h1 class="text-xl font-bold text-slate-900 mb-2">Course not found</h1>
        <p class="text-slate-600 mb-6">We couldn't find that course. It may have been removed or doesn't belong to your account.</p>
        <a href="/dashboard.php" class="inline-block px-5 py-2.5 bg-indigo-600 text-white rounded-lg font-semibold">Go to dashboard</a>
      </div>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Pull progress data
$progress = course_progress_data($course_id);

// If today is a day they haven't yet completed, generate (or fetch cached) task
$today_task = null;
if ($progress['status'] === 'active' && $progress['today_day'] >= 1 && $progress['today_day'] <= 7) {
    $today_task = course_generate_daily_task($course_id, $progress['today_day']);
}

$child_name = $course['child_name'];
$daily_minutes = (int)$course['daily_minutes'];
$is_hindi = $course['anchor_lang'] === 'hi';
$days_completed = count($progress['days']);

require __DIR__ . '/includes/header.php';
?>
<main class="max-w-3xl mx-auto px-4 py-6">

<!-- ── Header card ─────────────────────────────────────────── -->
<div class="bg-gradient-to-br from-indigo-500 to-purple-600 text-white rounded-2xl p-5 mb-4">
  <div class="flex items-start justify-between">
    <div>
      <div class="text-xs uppercase tracking-wider opacity-80 mb-1">
        7-Day Speech Course · <?= $daily_minutes ?> min/day
      </div>
      <h1 class="text-2xl font-bold mb-1"><?= htmlspecialchars($child_name) ?>'s journey</h1>
      <div class="text-sm opacity-90">
        Day <?= $progress['today_day'] ?: '—' ?> of 7
        <?php if ($progress['status'] === 'completed'): ?>
          · ✓ Completed
        <?php elseif ($progress['status'] === 'failed'): ?>
          · ✗ Expired
        <?php endif; ?>
      </div>
    </div>
    <a href="/dashboard.php" class="text-xs underline opacity-80 hover:opacity-100">Dashboard</a>
  </div>

  <!-- Day dots -->
  <div class="flex gap-1.5 mt-4">
    <?php for ($d = 1; $d <= 7; $d++):
      $done = in_array($d, $progress['days'], true);
      $is_today = ($d === $progress['today_day'] && $progress['status'] === 'active');
      $cls = $done ? 'bg-green-400'
                  : ($is_today ? 'bg-white animate-pulse' : 'bg-white/30');
    ?>
      <div class="flex-1 h-2 rounded-full <?= $cls ?>"></div>
    <?php endfor; ?>
  </div>
</div>

<?php if ($progress['status'] === 'failed'): ?>

<!-- ── FAILED state ────────────────────────────────────────── -->
<div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 mb-4">
  <h2 class="text-base font-bold text-rose-900 mb-2">😔 This course expired</h2>
  <p class="text-sm text-rose-800 mb-3"><?= htmlspecialchars($progress['failed_reason']) ?></p>
  <p class="text-xs text-rose-700 mb-4">
    Our 7-day courses require consistent daily practice — you can miss at most 1 day.
    Don't worry, you can start a new course any time.
  </p>
  <a href="/eval-speech.php" class="inline-block px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg font-semibold text-sm">
    Start a fresh evaluation →
  </a>
</div>

<?php elseif ($progress['status'] === 'completed'): ?>

<!-- ── COMPLETED state ─────────────────────────────────────── -->
<div class="bg-green-50 border border-green-200 rounded-2xl p-5 mb-4">
  <h2 class="text-lg font-bold text-green-900 mb-2">🎉 7 days complete!</h2>
  <p class="text-sm text-green-800 mb-3">
    Wonderful work. <?= htmlspecialchars($child_name) ?> showed up every day —
    that consistency is what builds real change. Scroll down to see the progress
    chart and Day 1 → Day 7 changes across all 5 areas.
  </p>
</div>

<?php elseif ($today_task && $today_task['ok']): ?>

<!-- ── ACTIVE state with today's task ──────────────────────── -->
<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
  <div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 mb-1">
    Today's task · focuses on <?= htmlspecialchars($today_task['target_axis']) ?>
  </div>
  <h2 class="text-xl font-bold text-slate-900 mb-3">
    <?= htmlspecialchars($today_task['title'] ?? 'Day ' . $progress['today_day']) ?>
  </h2>
  <div class="md-content text-sm text-slate-700 mb-4" id="taskBody"></div>
</div>

<!-- Record the anchor sentence -->
<div class="bg-amber-50 border-2 border-amber-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-amber-900 mb-2">🎤 End-of-session recording</h3>
  <p class="text-xs text-amber-800 mb-3">
    After you finish the task, have <?= htmlspecialchars($child_name) ?> say this sentence
    aloud. We record it every day so you can see real improvement over 7 days.
  </p>

  <div class="bg-white rounded-xl p-4 mb-3 border border-amber-300">
    <div class="text-xs text-amber-700 mb-1 font-semibold uppercase tracking-wider">
      Today's sentence — same every day
    </div>
    <p class="text-base font-bold text-slate-900 leading-relaxed" id="anchorText">
      <?= htmlspecialchars($course['anchor_sentence']) ?>
    </p>
    <button type="button" id="ttsBtn" class="mt-2 text-xs text-indigo-600 hover:text-indigo-800 underline">
      🔊 Hear it
    </button>
  </div>

  <div class="flex flex-col gap-2 items-stretch">
    <button type="button" id="recordBtn" class="bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-4 rounded-xl">
      🎙 Tap to record
    </button>
    <div id="recordStatus" class="text-xs text-amber-700 text-center min-h-[1rem]"></div>

    <div id="resultBox" class="hidden bg-green-50 border border-green-300 rounded-lg p-3 text-sm text-green-900">
      <div class="font-semibold mb-1">✓ Recorded! Today's snapshot:</div>
      <div id="snapshotBody" class="text-xs"></div>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── Some unexpected state — show a generic ────────────── -->
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-4">
  <p class="text-sm text-amber-900">
    Couldn't load today's task. Try refreshing — if it persists, reach out via WhatsApp.
  </p>
</div>

<?php endif; ?>

<!-- ── Progress chart (always visible if any days complete) ── -->
<?php if (!empty($progress['days'])): ?>
<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-slate-900 mb-3">Progress so far</h3>
  <div class="relative" style="height: 280px;">
    <canvas id="progressChart"></canvas>
  </div>

  <!-- Day 1 → Today delta cards -->
  <?php
    $deltas = [];
    foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $ax) {
      $vals = $progress['axes'][$ax];
      if (count($vals) < 1) continue;
      $first = $vals[0]; $latest = end($vals);
      if ($first === null || $latest === null) continue;
      $deltas[$ax] = ['first' => (int)$first, 'latest' => (int)$latest, 'delta' => (int)$latest - (int)$first];
    }
  ?>
  <?php if (count($deltas) > 0): ?>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mt-4">
      <?php
        $labels = ['articulation'=>'🗣️ Artic.', 'fluency'=>'🌊 Fluency',
                   'vocabulary'=>'📚 Vocab', 'grammar'=>'🧩 Grammar', 'narrative'=>'📖 Narrative'];
        foreach ($deltas as $ax => $d):
          $up = $d['delta'] > 0; $down = $d['delta'] < 0;
          $arrow = $up ? '↑' : ($down ? '↓' : '→');
          $cls = $up ? 'text-green-700 bg-green-50 border-green-200'
                    : ($down ? 'text-rose-700 bg-rose-50 border-rose-200'
                            : 'text-slate-600 bg-slate-50 border-slate-200');
      ?>
        <div class="border <?= $cls ?> rounded-lg p-2 text-center">
          <div class="text-xs font-semibold mb-0.5"><?= $labels[$ax] ?></div>
          <div class="text-sm font-bold"><?= $d['latest'] ?> <span class="text-xs"><?= $arrow ?> <?= abs($d['delta']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Day 8 upsell (only when completed) ──────────────────── -->
<?php if ($progress['status'] === 'completed'): ?>
<div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-indigo-900 mb-2">What's next for <?= htmlspecialchars($child_name) ?>?</h3>
  <p class="text-sm text-indigo-800 mb-4">
    The skills built this week need reinforcement. Most parents continue with a fresh evaluation
    + new 7-day course to keep momentum.
  </p>
  <div class="flex gap-2">
    <a href="/eval-speech.php" class="flex-1 text-center px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold text-sm">
      Re-evaluate (₹59)
    </a>
    <a href="/wallet.php" class="px-4 py-2.5 bg-white border border-indigo-300 text-indigo-700 rounded-lg font-semibold text-sm">
      Wallet
    </a>
  </div>
</div>
<?php endif; ?>

</main>

<!-- ── JS section ───────────────────────────────────────────── -->
<script>
const COURSE_ID = <?= json_encode($course_id) ?>;
const CSRF = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
const TODAY_DAY = <?= (int)$progress['today_day'] ?>;
const ANCHOR_SENTENCE = <?= json_encode($course['anchor_sentence'] ?? '') ?>;
const ANCHOR_LANG = <?= json_encode($course['anchor_lang']) ?>;
const PROGRESS = <?= json_encode($progress) ?>;
const COURSE_STATUS = <?= json_encode($progress['status']) ?>;
const TASK_MD = <?= json_encode($today_task && $today_task['ok'] ? ($today_task['task_md'] ?? '') : '') ?>;
const $ = (id) => document.getElementById(id);

// ── Render task markdown (very minimal converter — supports ### headers, * lists, numbered lists) ──
function renderMarkdown(md) {
  if (!md) return '';
  let html = String(md).split('\n').map(line => {
    line = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    line = line.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    if (/^### /.test(line))  return '<h3 class="font-bold text-slate-900 mt-3 mb-1">' + line.replace(/^### /, '') + '</h3>';
    if (/^## /.test(line))   return '<h2 class="font-bold text-slate-900 text-base mt-3 mb-1">' + line.replace(/^## /, '') + '</h2>';
    if (/^\d+\.\s+/.test(line)) return '<li class="ml-4 list-decimal">' + line.replace(/^\d+\.\s+/, '') + '</li>';
    if (/^[\-\*]\s+/.test(line)) return '<li class="ml-4 list-disc">' + line.replace(/^[\-\*]\s+/, '') + '</li>';
    if (line.trim() === '') return '<br>';
    return '<p class="my-1">' + line + '</p>';
  }).join('');
  return html;
}
if ($('taskBody')) $('taskBody').innerHTML = renderMarkdown(TASK_MD);

// ── TTS: speak the anchor sentence ──
$('ttsBtn')?.addEventListener('click', () => {
  if (!('speechSynthesis' in window)) {
    alert('Your browser doesn\'t support text-to-speech. Please read the sentence to your child.');
    return;
  }
  const u = new SpeechSynthesisUtterance(ANCHOR_SENTENCE);
  u.lang = ANCHOR_LANG === 'hi' ? 'hi-IN' : 'en-IN';
  u.rate = 0.85;
  window.speechSynthesis.cancel();
  window.speechSynthesis.speak(u);
});

// ── Recording: Web Speech recognition + MediaRecorder ──
let mediaRecorder = null;
let audioChunks = [];
let recogObj = null;
let recordStartedAt = 0;
let firstSpeechAt = null;
let pauseCount = 0;
let lastPartialAt = 0;
let bestTranscript = '';
let bestConfidence = 0;
let recording = false;

async function startRecording() {
  if (recording) return;
  $('recordStatus').textContent = 'Requesting microphone…';
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch (e) {
    $('recordStatus').textContent = '❌ Microphone access denied. Allow it in your browser settings.';
    return;
  }
  // MediaRecorder for audio blob
  audioChunks = [];
  try {
    mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
  } catch (e) {
    mediaRecorder = new MediaRecorder(stream);
  }
  mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunks.push(e.data); };
  mediaRecorder.start();

  // Web Speech Recognition for transcript + confidence
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SpeechRecognition) {
    recogObj = new SpeechRecognition();
    recogObj.continuous = true;
    recogObj.interimResults = true;
    recogObj.lang = ANCHOR_LANG === 'hi' ? 'hi-IN' : 'en-IN';
    recogObj.onresult = (event) => {
      let final = '';
      let bestConf = 0;
      for (let i = 0; i < event.results.length; i++) {
        const r = event.results[i][0];
        if (event.results[i].isFinal) {
          final += r.transcript + ' ';
          if (r.confidence > bestConf) bestConf = r.confidence;
        } else {
          if (firstSpeechAt === null) firstSpeechAt = Date.now();
        }
      }
      if (final.trim()) {
        bestTranscript = final.trim();
        bestConfidence = bestConf;
      }
      // Detect pauses (gap > 1.5s between partials)
      const now = Date.now();
      if (lastPartialAt && (now - lastPartialAt) > 1500) pauseCount++;
      lastPartialAt = now;
      if (firstSpeechAt === null) firstSpeechAt = now;
    };
    recogObj.onerror = (e) => { /* silent — we still have audio */ };
    recogObj.start();
  }

  recordStartedAt = Date.now();
  recording = true;
  $('recordBtn').textContent = '⏹ Stop recording';
  $('recordBtn').classList.replace('bg-amber-500', 'bg-rose-500');
  $('recordBtn').classList.replace('hover:bg-amber-600', 'hover:bg-rose-600');
  $('recordStatus').textContent = '🔴 Recording… ' + (ANCHOR_LANG === 'hi'
    ? 'अब बच्चे को वाक्य कहने को कहो।'
    : 'Have your child say the sentence now.');
}

async function stopRecording() {
  if (!recording) return;
  recording = false;
  $('recordBtn').disabled = true;
  $('recordStatus').textContent = 'Processing recording…';

  if (recogObj) { try { recogObj.stop(); } catch (e) {} }

  await new Promise(r => {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.onstop = r;
      mediaRecorder.stop();
    } else { r(); }
  });

  const durationSec = Math.max(1, Math.round((Date.now() - recordStartedAt) / 1000));
  const blob = audioChunks.length ? new Blob(audioChunks, { type: 'audio/webm' }) : null;
  const wordCount = bestTranscript.split(/\s+/).filter(w => w.length > 0).length;
  const wpm = wordCount > 0 ? Math.round((wordCount / durationSec) * 60) : 0;
  const ttfs = firstSpeechAt ? Math.max(0, (firstSpeechAt - recordStartedAt) / 1000) : 1.0;
  const silenceRatio = ttfs > 0 && durationSec > 0 ? Math.min(1, ttfs / durationSec) : 0.2;

  await submitDay({
    transcript: bestTranscript || '(no response)',
    audio_blob: blob,
    acoustic: {
      transcript_confidence: bestConfidence,
      duration_sec: durationSec,
      wpm: wpm,
      silence_ratio: silenceRatio,
      pause_count: pauseCount,
      time_to_first_speech_sec: ttfs,
    }
  });
}

async function submitDay(payload) {
  $('recordStatus').textContent = 'Saving your snapshot…';
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('course_id', COURSE_ID);
  fd.append('day_no', TODAY_DAY);
  fd.append('transcript', payload.transcript);
  for (const k of Object.keys(payload.acoustic)) fd.append(k, payload.acoustic[k]);
  if (payload.audio_blob) fd.append('audio', payload.audio_blob, 'd' + TODAY_DAY + '.webm');

  try {
    const r = await fetch('/course-day.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) {
      $('recordStatus').textContent = '❌ ' + (j.error || 'Could not save. Please try again.');
      $('recordBtn').disabled = false;
      return;
    }
    // Show snapshot inline + reload after 4s
    if (j.snapshot) {
      const order = ['articulation','fluency','vocabulary','grammar','narrative'];
      const labels = {articulation:'🗣️ Articulation', fluency:'🌊 Fluency', vocabulary:'📚 Vocabulary', grammar:'🧩 Grammar', narrative:'📖 Narrative'};
      const html = order.map(k => '<div>' + labels[k] + ': <strong>' + (j.snapshot[k] ?? '—') + '</strong></div>').join('');
      $('snapshotBody').innerHTML = html;
      $('resultBox').classList.remove('hidden');
    }
    $('recordStatus').textContent = j.is_course_done
      ? '✓ Course complete! Loading final progress…'
      : '✓ Day ' + TODAY_DAY + ' done. Loading your chart…';
    setTimeout(() => { window.location.reload(); }, 2500);
  } catch (e) {
    $('recordStatus').textContent = '❌ Network error. Please try again.';
    $('recordBtn').disabled = false;
  }
}

if ($('recordBtn')) {
  $('recordBtn').addEventListener('click', () => {
    recording ? stopRecording() : startRecording();
  });
}

// ── Progress chart (Chart.js) ──
<?php if (!empty($progress['days'])): ?>
(function loadChart() {
  if (typeof Chart === 'undefined') {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    s.onload = drawChart;
    document.head.appendChild(s);
  } else {
    drawChart();
  }
})();

function drawChart() {
  const ctx = document.getElementById('progressChart');
  if (!ctx) return;
  const days = PROGRESS.days.map(d => 'Day ' + d);
  const axisColors = {
    articulation: '#3b82f6',  // blue
    fluency:      '#10b981',  // green
    vocabulary:   '#8b5cf6',  // purple
    grammar:      '#f59e0b',  // amber
    narrative:    '#ec4899',  // pink
  };
  const datasets = [];
  ['articulation','fluency','vocabulary','grammar','narrative'].forEach(ax => {
    if (PROGRESS.axes[ax] && PROGRESS.axes[ax].length > 0) {
      datasets.push({
        label: ax.charAt(0).toUpperCase() + ax.slice(1),
        data: PROGRESS.axes[ax],
        borderColor: axisColors[ax],
        backgroundColor: axisColors[ax] + '20',
        tension: 0.3,
        pointRadius: 4,
        pointHoverRadius: 6,
        borderWidth: 2,
      });
    }
  });
  new Chart(ctx, {
    type: 'line',
    data: { labels: days, datasets: datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true, max: 100, ticks: { stepSize: 25 } }
      },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { mode: 'index', intersect: false }
      },
      interaction: { mode: 'nearest', axis: 'x', intersect: false }
    }
  });
}
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
