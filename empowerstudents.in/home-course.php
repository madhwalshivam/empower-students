<?php
/**
 * home-course.php?id=N
 *
 * Main page for the 7-Day Home Environment Course.
 * Three states: active+day_pending / active+day_done / completed / failed.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/home_course_engine.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$course_id = (int)($_GET['id'] ?? 0);
$cst = db()->prepare("SELECT * FROM home_courses WHERE id = ? AND parent_id = ?");
$cst->execute([$course_id, $parent_id]);
$course = $cst->fetch();

if (!$course) {
    $page_title = 'Course not found — EmpowerStudents';
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="max-w-3xl mx-auto px-4 py-12">
      <div class="bg-white border border-slate-200 rounded-2xl p-8 text-center">
        <div class="text-5xl mb-4">🤔</div>
        <h1 class="text-xl font-bold text-slate-900 mb-2">Course not found</h1>
        <p class="text-slate-600 mb-6">We couldn't find that course. It may not belong to your account.</p>
        <a href="/dashboard.php" class="inline-block px-5 py-2.5 bg-indigo-600 text-white rounded-lg font-semibold">Go to dashboard</a>
      </div>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$progress = home_course_progress_data($course_id);

$today_task = null;
if ($progress['status'] === 'active' && $progress['today_day'] >= 1 && $progress['today_day'] <= 7) {
    $today_task = home_course_generate_daily_task($course_id, $progress['today_day']);
}

$daily_minutes = (int)$course['daily_minutes'];
$is_hindi = $course['language'] === 'hi';
$anchor_question = $is_hindi ? $course['anchor_question_hi'] : $course['anchor_question_en'];

$page_title = '7-Day Home Course — EmpowerStudents';
require __DIR__ . '/includes/header.php';
?>

<main class="max-w-3xl mx-auto px-4 py-6">

<!-- Header card -->
<div class="bg-gradient-to-br from-emerald-600 to-teal-600 text-white rounded-2xl p-5 mb-4 shadow-lg">
  <div class="flex items-start justify-between">
    <div>
      <div class="text-xs uppercase tracking-wider opacity-80 mb-1">
        7-Day Home Course · <?= $daily_minutes ?> min/day
      </div>
      <h1 class="text-2xl font-bold mb-1"><?= $is_hindi ? 'घर का माहौल बदलना' : 'Changing the home climate' ?></h1>
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

  <div class="flex gap-1.5 mt-4">
    <?php for ($d = 1; $d <= 7; $d++):
      $done = in_array($d, $progress['days'], true);
      $is_today = ($d === $progress['today_day'] && $progress['status'] === 'active');
      $cls = $done ? 'bg-amber-300' : ($is_today ? 'bg-white animate-pulse' : 'bg-white/30');
    ?>
      <div class="flex-1 h-2 rounded-full <?= $cls ?>" title="Day <?= $d ?>"></div>
    <?php endfor; ?>
  </div>
</div>

<?php if ($progress['status'] === 'failed'): ?>

<div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 mb-4">
  <h2 class="text-base font-bold text-rose-900 mb-2">😔 <?= $is_hindi ? 'यह course expire हो गया' : 'This course expired' ?></h2>
  <p class="text-sm text-rose-800 mb-3"><?= htmlspecialchars((string)$progress['failed_reason']) ?></p>
  <p class="text-xs text-rose-700 mb-4">
    <?= $is_hindi
      ? "हमारे 7-day courses में रोज़ का अभ्यास ज़रूरी है — आप ज़्यादा से ज़्यादा 1 दिन miss कर सकते हैं। कोई बात नहीं, आप नया course कभी भी शुरू कर सकते हैं।"
      : "Our 7-day courses need daily practice — you can miss at most 1 day. Don't worry, you can start a fresh course any time." ?>
  </p>
  <a href="/parent-reflect.php" class="inline-block px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg font-semibold text-sm">
    <?= $is_hindi ? 'नया reflection करें →' : 'Start a fresh reflection →' ?>
  </a>
</div>

<?php elseif ($progress['status'] === 'completed'): ?>

<div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-5 mb-4">
  <h2 class="text-lg font-bold text-emerald-900 mb-2">🌿 <?= $is_hindi ? '7 दिन पूरे!' : '7 days complete!' ?></h2>
  <p class="text-sm text-emerald-800">
    <?= $is_hindi
      ? "बहुत अच्छा काम। आप रोज़ आईं — यही consistency असली बदलाव लाती है। नीचे आपका progress देखिए, और हर axis पर Day 1 से Day 7 तक का सफर।"
      : "Wonderful work. You showed up every day — that consistency is what builds real change. Scroll down to see your progress + Day 1 → Day 7 movement on each axis." ?>
  </p>
</div>

<?php elseif ($today_task && $today_task['ok']): ?>

<!-- Today's task card -->
<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
  <div class="text-xs font-semibold uppercase tracking-wider text-emerald-600 mb-1">
    <?= $is_hindi ? "आज का अभ्यास · focus" : "Today's practice · focus" ?>: <?= htmlspecialchars($today_task['target_axis']) ?>
  </div>
  <h2 class="text-xl font-bold text-slate-900 mb-3">
    <?= htmlspecialchars($today_task['title'] ?: "Day {$progress['today_day']}") ?>
  </h2>
  <div class="md-content text-sm text-slate-700 leading-relaxed" id="taskBody"></div>
</div>

<!-- Daily anchor recording -->
<div class="bg-amber-50 border-2 border-amber-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-amber-900 mb-2">🎤 <?= $is_hindi ? 'दिन के अंत में एक छोटा सा reflection' : 'End-of-day reflection' ?></h3>
  <p class="text-xs text-amber-800 mb-3">
    <?= $is_hindi
      ? "आज की task पूरी करने के बाद, सिर्फ़ इस एक सवाल का जवाब voice में दें। हर दिन यही सवाल — ताकि हम Day 1 से Day 7 तक का बदलाव track कर सकें।"
      : "After completing today's task, just answer this ONE question in your voice. Same question every day — that's how we track the journey from Day 1 to Day 7." ?>
  </p>

  <div class="bg-white rounded-xl p-4 mb-3 border border-amber-300">
    <div class="text-xs text-amber-700 mb-1 font-semibold uppercase tracking-wider">
      <?= $is_hindi ? "आज का सवाल — हर दिन यही" : "Today's question — same every day" ?>
    </div>
    <p class="text-base font-bold text-slate-900 leading-relaxed" id="anchorText">
      <?= htmlspecialchars($anchor_question) ?>
    </p>
    <button type="button" id="ttsBtn" class="mt-2 text-xs text-emerald-700 hover:text-emerald-900 underline">
      🔊 <?= $is_hindi ? 'सुनें' : 'Hear it' ?>
    </button>
  </div>

  <div class="flex flex-col gap-2 items-stretch">
    <button type="button" id="recordBtn" class="bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-4 rounded-xl">
      🎙 <?= $is_hindi ? 'Tap करके बोलें' : 'Tap to record' ?>
    </button>
    <div id="recordStatus" class="text-xs text-amber-700 text-center min-h-[1rem]"></div>

    <div id="resultBox" class="hidden bg-emerald-50 border border-emerald-300 rounded-lg p-3 text-sm text-emerald-900">
      <div class="font-semibold mb-1">✓ <?= $is_hindi ? 'Recorded! आज का snapshot:' : "Recorded! Today's snapshot:" ?></div>
      <div id="snapshotBody" class="text-xs"></div>
    </div>
  </div>
</div>

<?php else: ?>

<div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-4">
  <p class="text-sm text-amber-900">
    Couldn't load today's task. Try refreshing.
  </p>
</div>

<?php endif; ?>

<!-- Progress chart -->
<?php if (!empty($progress['days'])): ?>
<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-slate-900 mb-3"><?= $is_hindi ? 'सप्ताह की प्रगति' : 'Week so far' ?></h3>
  <div class="relative" style="height: 260px;">
    <canvas id="progressChart"></canvas>
  </div>

  <?php
    // Compute Day 1 → today delta cards on the 3 series
    $deltas = [];
    foreach (['sentiment','energy','openness'] as $k) {
        $vals = $progress['series'][$k];
        if (count($vals) < 1) continue;
        $first = $vals[0]; $latest = end($vals);
        if ($first === null || $latest === null) continue;
        $deltas[$k] = ['first' => (int)$first, 'latest' => (int)$latest, 'delta' => (int)$latest - (int)$first];
    }
  ?>
  <?php if (count($deltas) > 0): ?>
    <div class="grid grid-cols-3 gap-2 mt-4">
      <?php
        $labels = $is_hindi
          ? ['sentiment' => '😊 Mood', 'energy' => '⚡ Energy', 'openness' => '🌸 खुलापन']
          : ['sentiment' => '😊 Mood', 'energy' => '⚡ Energy', 'openness' => '🌸 Openness'];
        foreach ($deltas as $k => $d):
          $up = $d['delta'] > 0; $down = $d['delta'] < 0;
          $arrow = $up ? '↑' : ($down ? '↓' : '→');
          $cls = $up ? 'text-emerald-700 bg-emerald-50 border-emerald-200'
                    : ($down ? 'text-rose-700 bg-rose-50 border-rose-200'
                            : 'text-slate-600 bg-slate-50 border-slate-200');
      ?>
        <div class="border <?= $cls ?> rounded-lg p-2 text-center">
          <div class="text-xs font-semibold mb-0.5"><?= $labels[$k] ?></div>
          <div class="text-sm font-bold"><?= $d['latest'] ?> <span class="text-xs"><?= $arrow ?> <?= abs($d['delta']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Completion upsell -->
<?php if ($progress['status'] === 'completed'): ?>
<div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-5 mb-4">
  <h3 class="text-base font-bold text-indigo-900 mb-2"><?= $is_hindi ? 'अगला क़दम?' : "What's next?" ?></h3>
  <p class="text-sm text-indigo-800 mb-4">
    <?= $is_hindi
      ? "एक नया reflection करें और देखिए पिछले हफ़्ते के अभ्यास का असर। नई clarity के साथ अगला course शुरू कर सकते हैं।"
      : "Do a fresh reflection to see what shifted this week. Start the next course with new clarity." ?>
  </p>
  <a href="/parent-reflect.php" class="inline-block px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold text-sm">
    <?= $is_hindi ? 'नया reflection (₹499)' : 'Fresh reflection (₹499)' ?>
  </a>
</div>
<?php endif; ?>

</main>

<script>
const COURSE_ID = <?= json_encode($course_id) ?>;
const CSRF = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
const TODAY_DAY = <?= (int)$progress['today_day'] ?>;
const ANCHOR_QUESTION = <?= json_encode($anchor_question) ?>;
const LANG = <?= json_encode($course['language']) ?>;
const PROGRESS = <?= json_encode($progress) ?>;
const TASK_MD = <?= json_encode($today_task && $today_task['ok'] ? ($today_task['task_md'] ?? '') : '') ?>;
const IS_HINDI = LANG === 'hi';
const $ = (id) => document.getElementById(id);

function renderMarkdown(md) {
  if (!md) return '';
  return String(md).split('\n').map(line => {
    line = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    line = line.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    if (/^### /.test(line))  return '<h3 class="font-bold text-slate-900 mt-3 mb-1">' + line.replace(/^### /, '') + '</h3>';
    if (/^## /.test(line))   return '<h2 class="font-bold text-slate-900 text-base mt-3 mb-1">' + line.replace(/^## /, '') + '</h2>';
    if (/^\d+\.\s+/.test(line)) return '<li class="ml-4 list-decimal">' + line.replace(/^\d+\.\s+/, '') + '</li>';
    if (/^[\-\*]\s+/.test(line)) return '<li class="ml-4 list-disc">' + line.replace(/^[\-\*]\s+/, '') + '</li>';
    if (line.trim() === '') return '<br>';
    return '<p class="my-1">' + line + '</p>';
  }).join('');
}
if ($('taskBody')) $('taskBody').innerHTML = renderMarkdown(TASK_MD);

// TTS for anchor question
$('ttsBtn')?.addEventListener('click', () => {
  if (!('speechSynthesis' in window)) {
    alert(IS_HINDI ? "TTS support नहीं है।" : "Your browser doesn't support text-to-speech.");
    return;
  }
  const u = new SpeechSynthesisUtterance(ANCHOR_QUESTION);
  u.lang = IS_HINDI ? 'hi-IN' : 'en-IN';
  u.rate = 0.9;
  window.speechSynthesis.cancel();
  window.speechSynthesis.speak(u);
});

// ── Recording ──
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
  $('recordStatus').textContent = IS_HINDI ? 'Microphone माँग रहे हैं…' : 'Requesting microphone…';
  let stream;
  try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
  catch (e) {
    $('recordStatus').textContent = IS_HINDI ? '❌ Microphone permission नहीं मिली।' : '❌ Microphone access denied.';
    return;
  }
  audioChunks = [];
  try { mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' }); }
  catch (e) { mediaRecorder = new MediaRecorder(stream); }
  mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunks.push(e.data); };
  mediaRecorder.start();

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SpeechRecognition) {
    recogObj = new SpeechRecognition();
    recogObj.continuous = true;
    recogObj.interimResults = true;
    recogObj.lang = IS_HINDI ? 'hi-IN' : 'en-IN';
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
      if (final.trim()) { bestTranscript = final.trim(); bestConfidence = bestConf; }
      const now = Date.now();
      if (lastPartialAt && (now - lastPartialAt) > 1500) pauseCount++;
      lastPartialAt = now;
      if (firstSpeechAt === null) firstSpeechAt = now;
    };
    recogObj.onerror = () => {};
    recogObj.start();
  }

  recordStartedAt = Date.now();
  recording = true;
  $('recordBtn').textContent = '⏹ ' + (IS_HINDI ? 'रोकें' : 'Stop recording');
  $('recordBtn').classList.replace('bg-amber-500', 'bg-rose-500');
  $('recordBtn').classList.replace('hover:bg-amber-600', 'hover:bg-rose-600');
  $('recordStatus').textContent = '🔴 ' + (IS_HINDI ? 'Recording… अब बोलिए।' : 'Recording… speak now.');
}

async function stopRecording() {
  if (!recording) return;
  recording = false;
  $('recordBtn').disabled = true;
  $('recordStatus').textContent = IS_HINDI ? 'Save हो रहा है…' : 'Processing…';

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
  $('recordStatus').textContent = IS_HINDI ? 'आज का snapshot save हो रहा है…' : 'Saving today\'s snapshot…';
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('course_id', COURSE_ID);
  fd.append('day_no', TODAY_DAY);
  fd.append('transcript', payload.transcript);
  for (const k of Object.keys(payload.acoustic)) fd.append(k, payload.acoustic[k]);
  if (payload.audio_blob) fd.append('audio', payload.audio_blob, 'd' + TODAY_DAY + '.webm');

  try {
    const r = await fetch('/home-course-day.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) {
      $('recordStatus').textContent = '❌ ' + (j.error || (IS_HINDI ? 'Save नहीं हुआ।' : 'Save failed.'));
      $('recordBtn').disabled = false;
      return;
    }
    if (j.snapshot) {
      const labels = IS_HINDI
        ? {sentiment:'😊 Mood', energy:'⚡ Energy', openness:'🌸 खुलापन'}
        : {sentiment:'😊 Mood', energy:'⚡ Energy', openness:'🌸 Openness'};
      const html = ['sentiment','energy','openness'].map(k =>
        '<div>' + labels[k] + ': <strong>' + (j.snapshot[k] ?? '—') + '/100</strong></div>'
      ).join('');
      $('snapshotBody').innerHTML = html;
      $('resultBox').classList.remove('hidden');
    }
    $('recordStatus').textContent = j.is_course_done
      ? '✓ ' + (IS_HINDI ? '7 दिन पूरे! Loading…' : 'Course complete! Loading…')
      : '✓ ' + (IS_HINDI ? `Day ${TODAY_DAY} पूरा। Chart load हो रहा है…` : `Day ${TODAY_DAY} done. Loading chart…`);
    setTimeout(() => { window.location.reload(); }, 2500);
  } catch (e) {
    $('recordStatus').textContent = '❌ ' + (IS_HINDI ? 'Network error।' : 'Network error.');
    $('recordBtn').disabled = false;
  }
}

if ($('recordBtn')) {
  $('recordBtn').addEventListener('click', () => recording ? stopRecording() : startRecording());
}

// Progress chart
<?php if (!empty($progress['days'])): ?>
(function loadChart() {
  if (typeof Chart === 'undefined') {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    s.onload = drawChart;
    document.head.appendChild(s);
  } else drawChart();
})();

function drawChart() {
  const ctx = document.getElementById('progressChart');
  if (!ctx) return;
  const days = PROGRESS.days.map(d => 'Day ' + d);
  const colors = { sentiment: '#10b981', energy: '#f59e0b', openness: '#8b5cf6' };
  const labels = IS_HINDI
    ? {sentiment: 'Mood', energy: 'Energy', openness: 'खुलापन'}
    : {sentiment: 'Mood', energy: 'Energy', openness: 'Openness'};
  const datasets = [];
  ['sentiment','energy','openness'].forEach(k => {
    if (PROGRESS.series[k] && PROGRESS.series[k].length > 0) {
      datasets.push({
        label: labels[k],
        data: PROGRESS.series[k],
        borderColor: colors[k],
        backgroundColor: colors[k] + '22',
        tension: 0.3,
        pointRadius: 5,
        pointHoverRadius: 7,
        borderWidth: 2,
      });
    }
  });
  new Chart(ctx, {
    type: 'line',
    data: { labels: days, datasets: datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, max: 100, ticks: { stepSize: 25 } } },
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
