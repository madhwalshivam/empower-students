<?php
/**
 * eval-speech.php
 *
 * Voice-interview adaptive speech & language evaluation. Single-page app:
 *   - Pre-eval gate (pick child + Start)
 *   - Browser support gate (mic + speech recognition required)
 *   - Live conversation: TTS speaks question → mic listens → AI scores → next
 *   - Inline report at the end
 *
 * All session state is server-side (eval_sessions table). The page calls
 * /eval-speech-api.php for state transitions.
 *
 * Mic + Web Speech Recognition are HARD REQUIREMENTS. Browsers that don't
 * support them get an "unsupported" message and cannot proceed.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/eval_engine.php';

require_parent();
$parent  = current_parent();
$parent_id = (int)$parent['id'];

// List children
$cs = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC");
$cs->execute([$parent_id]);
$kids = $cs->fetchAll();

$is_free_eligible = eval_free_eligible($parent_id);
$bal = wallet_balance($parent_id);

// Look for a recent COMPLETED session within the last 24 hours.
// If one exists, show its report instead of forcing a new ₹59 eval.
// Parent can still tap "Start a new evaluation" if they want a fresh one.
$recent_complete = null;
$rs = db()->prepare("SELECT s.*, c.name AS child_name, c.dob AS child_dob,
                            c.mother_tongue AS child_mt, c.gender AS child_gender,
                            c.id AS child_id
                     FROM eval_sessions s
                     JOIN children c ON c.id = s.child_id
                     WHERE s.parent_id = ?
                       AND s.module = 'mod_speech_basic'
                       AND s.status = 'completed'
                       AND s.completed_at IS NOT NULL
                     ORDER BY s.completed_at DESC LIMIT 1");
$rs->execute([$parent_id]);
$row = $rs->fetch();
if ($row) {
    $age_hours = (time() - strtotime((string)$row['completed_at'] . ' UTC')) / 3600;
    if ($age_hours < 24) {
        $recent_complete = $row;
    }
}

// Prevent browser caching so JS updates reach users without manual refresh.
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Speech & Language Evaluation — EmpowerStudents';
require __DIR__ . '/includes/header.php';
?>

<main class="max-w-2xl mx-auto px-4 py-6" id="evalRoot">

  <!-- ──────────────────────────────────────────────────────────
       SCREEN 1: BROWSER SUPPORT CHECK (shown if SR/mic missing)
       ────────────────────────────────────────────────────────── -->
  <div id="screenUnsupported" class="hidden bg-amber-50 border-2 border-amber-300 rounded-2xl p-6 text-center">
    <div class="text-5xl mb-3">🎙️</div>
    <h1 class="text-xl font-bold text-amber-900 mb-2">Voice evaluation needs a different browser</h1>
    <p class="text-sm text-amber-800 mb-4">
      This evaluation listens to your child speaking. Your current browser doesn't support live voice recognition.
    </p>
    <div class="bg-white border border-amber-200 rounded-lg p-4 text-left text-sm text-slate-700 space-y-2 mb-4">
      <p><strong>Please open this page in:</strong></p>
      <ul class="list-disc list-inside space-y-1 text-xs">
        <li><strong>Google Chrome</strong> (Android, iOS, Windows, Mac) — best</li>
        <li><strong>Microsoft Edge</strong></li>
        <li><strong>Brave</strong></li>
      </ul>
      <p class="text-xs text-slate-500 mt-2">Firefox and older Safari versions don't yet support this feature.</p>
    </div>
    <a href="/dashboard.php" class="text-indigo-600 hover:underline text-sm">← Back to dashboard</a>
  </div>

  <!-- ──────────────────────────────────────────────────────────
       SCREEN 2: PRE-EVAL GATE — pick child + Start
       ────────────────────────────────────────────────────────── -->
  <div id="screenStart" class="hidden bg-white border border-slate-200 rounded-2xl p-6 md:p-8">
    <h1 class="text-2xl font-bold text-slate-900 mb-2">🎤 Speech & Language Evaluation</h1>
    <p class="text-slate-600 text-sm mb-4">
      A short, adaptive voice conversation (5-12 questions, ~5 minutes). Your child listens to each question and speaks the answer aloud. At the end you'll get a clinical report and a sample exercise.
    </p>

    <div id="flashStart" class="hidden bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4"></div>

    <!-- Pricing -->
    <div class="<?= $is_free_eligible ? 'bg-emerald-50 border-emerald-200' : 'bg-indigo-50 border-indigo-200' ?> border rounded-xl p-4 mb-5">
      <?php if ($is_free_eligible): ?>
        <p class="text-sm font-semibold text-emerald-900 mb-1">🎁 Your first evaluation is <strong>FREE</strong></p>
        <p class="text-xs text-emerald-800">One free evaluation included with your account. After this, evaluations are ₹59 each.</p>
      <?php else: ?>
        <p class="text-sm font-semibold text-indigo-900 mb-1">
          Price: <span class="line-through text-slate-400">₹199</span>
          <span class="text-2xl font-bold text-indigo-700 ml-2">₹59</span>
        </p>
        <p class="text-xs text-indigo-700">
          Wallet balance: <strong>₹<?= (int)$bal ?></strong>
          <?php if ((int)$bal < 59): ?>
            · <a href="/wallet.php?need=59" class="underline">Top up</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>

    <?php if (empty($kids)): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
        <p class="text-sm text-amber-900 mb-2">You haven't added a child yet.</p>
        <a href="/add_child.php?return=/eval-speech.php" class="brand-grad text-white font-semibold px-5 py-2 rounded-lg hover:opacity-90 inline-block">
          + Add a child
        </a>
      </div>
    <?php else: ?>
      <div class="space-y-3">
        <label class="block text-sm font-semibold text-slate-700">Choose your child</label>
        <div class="space-y-2" id="childPicker">
          <?php foreach ($kids as $k):
              $age = round((float)calc_age_years($k['dob']), 1);
          ?>
            <label class="block border border-slate-300 rounded-lg p-3 cursor-pointer hover:border-indigo-400 hover:bg-indigo-50 transition">
              <input type="radio" name="child_id" value="<?= (int)$k['id'] ?>" required class="mr-2">
              <span class="font-semibold text-slate-900"><?= e($k['name']) ?></span>
              <span class="text-xs text-slate-500 ml-1">
                <?= $age ?> yrs · <?= e($k['gender'] ?: '—') ?> · <?= e($k['mother_tongue'] ?: 'English') ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="text-xs text-slate-500">
          <a href="/add_child.php?return=/eval-speech.php" class="text-indigo-600 hover:underline">+ Add another child</a>
        </p>

        <button id="startBtn" type="button" disabled
                class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full md:w-auto disabled:opacity-50 disabled:cursor-not-allowed mt-2">
          ▶ <?= $is_free_eligible ? 'Start free voice evaluation' : 'Start evaluation (₹59)' ?>
        </button>
      </div>
    <?php endif; ?>

    <details class="mt-6 text-sm text-slate-600">
      <summary class="cursor-pointer font-semibold hover:text-indigo-600">How does this work?</summary>
      <div class="mt-3 space-y-2 text-xs">
        <p>This is an adaptive voice evaluation. The questions adjust to your child's level in real time.</p>
        <p>Your child will <strong>hear each question read aloud</strong> by our voice. Then the microphone will listen to their answer. You don't need to type anything — just have your child speak naturally.</p>
        <p>Best in a quiet room. The whole thing takes about 5 minutes. You'll get a clear report at the end with one sample exercise to try today.</p>
      </div>
    </details>
  </div>

  <!-- ──────────────────────────────────────────────────────────
       SCREEN 3: MIC PERMISSION CHECK (requesting access)
       ────────────────────────────────────────────────────────── -->
  <div id="screenMicCheck" class="hidden bg-white border border-slate-200 rounded-2xl p-6 md:p-8 text-center">
    <div class="text-5xl mb-3">🎙️</div>
    <h2 class="text-xl font-bold text-slate-900 mb-2">Allow microphone access</h2>
    <p class="text-sm text-slate-600 mb-4">
      Your browser will ask for permission to use the microphone. Please tap <strong>Allow</strong> so we can listen to your child's answers.
    </p>
    <div id="micCheckStatus" class="text-sm text-slate-700 mb-4 italic">Waiting for permission…</div>
    <button id="micRetryBtn" type="button" class="hidden bg-indigo-600 text-white font-semibold px-5 py-2 rounded-lg hover:bg-indigo-700">
      Retry
    </button>
  </div>

  <!-- ──────────────────────────────────────────────────────────
       SCREEN 4: LIVE INTERVIEW
       ────────────────────────────────────────────────────────── -->
  <div id="screenInterview" class="hidden">
    <!-- Header strip -->
    <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4">
      <div class="flex items-baseline justify-between gap-3 mb-2 flex-wrap">
        <p class="text-sm font-bold text-slate-900" id="iChildLabel">Speech Evaluation</p>
        <p class="text-xs text-slate-500"><span id="iSeqNo">Q1</span> · <span id="iLevelLabel">Level L3</span></p>
      </div>
      <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
        <div class="h-full brand-grad transition-all" id="iProgressBar" style="width: 8%;"></div>
      </div>
    </div>

    <!-- Big conversation card -->
    <div class="bg-white border-2 border-indigo-200 rounded-2xl p-6 md:p-8 text-center min-h-[420px] flex flex-col items-center justify-center">

      <!-- State icon (changes per state) -->
      <div id="iStateIcon" class="text-7xl mb-4 transition-all">🎤</div>

      <!-- Question text (visible during all states for parent reference) -->
      <p id="iQuestionText" class="text-base text-slate-900 leading-relaxed font-medium mb-4 px-2">
        Loading the first question…
      </p>

      <!-- Live transcript (during listening) -->
      <div id="iTranscriptBox" class="hidden w-full bg-rose-50 border border-rose-200 rounded-lg p-3 mb-3 text-left">
        <div class="flex items-baseline justify-between mb-1">
          <p class="text-xs uppercase tracking-wider text-rose-700 font-semibold">Heard so far</p>
          <!-- Mic-level visualizer: 5 bars driven by analyser -->
          <div id="iMicLevel" class="flex items-end gap-0.5" style="height: 16px;">
            <span class="block bg-rose-400 rounded-sm" style="width:3px; height:20%;"></span>
            <span class="block bg-rose-400 rounded-sm" style="width:3px; height:20%;"></span>
            <span class="block bg-rose-400 rounded-sm" style="width:3px; height:20%;"></span>
            <span class="block bg-rose-400 rounded-sm" style="width:3px; height:20%;"></span>
            <span class="block bg-rose-400 rounded-sm" style="width:3px; height:20%;"></span>
          </div>
        </div>
        <p id="iLiveTranscript" class="text-sm text-slate-800 italic min-h-[1.5em]"></p>
      </div>

      <!-- Status text -->
      <p id="iStatusText" class="text-sm text-slate-500 italic">Connecting…</p>

      <!-- Action buttons during listening -->
      <div id="iActionRow" class="hidden flex items-center justify-center gap-2 mt-4 flex-wrap">
        <button id="iRetryBtn" type="button"
                class="bg-amber-100 text-amber-800 font-semibold px-4 py-2 rounded-xl hover:bg-amber-200 text-sm">
          🔄 Listen again
        </button>
        <button id="iDoneBtn" type="button"
                class="bg-emerald-600 text-white font-semibold px-5 py-2 rounded-xl hover:bg-emerald-700">
          ✓ Done speaking
        </button>
      </div>
    </div>

    <!-- Cancel / End evaluation -->
    <div class="text-center mt-4">
      <button id="iCancelBtn" type="button" class="text-xs text-slate-400 hover:text-rose-600 underline">
        End evaluation
      </button>
    </div>
  </div>

  <!-- ──────────────────────────────────────────────────────────
       SCREEN 5: REPORT
       ────────────────────────────────────────────────────────── -->
  <div id="screenReport" class="hidden">
    <!-- Recent-report banner (visible when shown on reload from cached completion) -->
    <div id="rRecentBanner" class="hidden bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4 text-sm text-blue-900">
      <p class="font-semibold mb-1">📋 Showing your recent evaluation</p>
      <p class="text-xs text-blue-800">
        This evaluation was completed on <span id="rCompletedAt">—</span>.
        You don't need to pay again to view it.
      </p>
    </div>

    <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap">
      <h1 class="text-2xl font-bold text-slate-900">Speech Evaluation Report</h1>
      <a href="/dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Dashboard</a>
    </div>

    <!-- Hero summary -->
    <div class="bg-gradient-to-br from-indigo-50 to-purple-50 border-2 border-indigo-200 rounded-2xl p-6 mb-4">
      <p class="text-xs uppercase tracking-wider text-indigo-700 font-semibold mb-1">Child</p>
      <p class="font-bold text-slate-900 text-xl" id="rChildName">—</p>
      <div class="grid grid-cols-2 gap-4 mt-5">
        <div>
          <p class="text-xs uppercase tracking-wider text-indigo-700 font-semibold mb-1">Current level</p>
          <p class="text-3xl font-bold text-indigo-900" id="rLevel">—</p>
          <p class="text-xs text-slate-600" id="rLevelName">—</p>
        </div>
        <div>
          <p class="text-xs uppercase tracking-wider text-indigo-700 font-semibold mb-1">Accuracy</p>
          <p class="text-3xl font-bold text-indigo-900" id="rPct">—</p>
          <p class="text-xs text-slate-600">across <span id="rQCount">—</span> questions</p>
        </div>
      </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
      <div class="md-content text-sm text-slate-700" id="rReportMd"></div>
    </div>

    <div class="bg-amber-50 border border-amber-300 rounded-2xl p-5 mb-4">
      <div class="md-content text-sm text-amber-900" id="rExerciseMd"></div>
    </div>

    <div class="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-6 text-white mb-4 shadow-lg">
      <h2 class="text-xl font-bold mb-2">Want a personalised 1-Week Speech Plan?</h2>
      <p class="text-sm opacity-95 mb-3">
        Daily 10-minute exercises tailored to <span id="rChildPlanName">your child</span>'s level (<span id="rPlanLevel">—</span>),
        plus 2 video check-ins from our speech therapists. Track progress day by day.
      </p>
      <p class="mb-4">
        <span class="line-through opacity-70 text-base">₹299</span>
        <span class="text-3xl font-bold ml-2">₹99</span>
        <span class="text-xs opacity-80">/ week</span>
      </p>
      <a id="rUpsellLink" href="#" class="bg-white text-emerald-700 font-bold px-6 py-3 rounded-xl hover:bg-amber-50 inline-block">
        Start the 1-Week Plan →
      </a>
    </div>

    <!-- Start fresh button (visible only on recent-report view) -->
    <div id="rStartFreshWrap" class="hidden text-center">
      <button id="rStartFreshBtn" type="button"
              class="text-sm text-slate-500 hover:text-rose-600 underline">
        Start a new evaluation instead
      </button>
    </div>
  </div>

</main>

<style>
@keyframes mic-pulse {
  0%,100% { transform: scale(1); }
  50%     { transform: scale(1.15); }
}
.mic-pulsing { animation: mic-pulse 0.9s ease-in-out infinite; }

@keyframes wave-bounce {
  0%,100% { transform: scaleY(0.4); }
  50%     { transform: scaleY(1); }
}
.wave-bar {
  display: inline-block; width: 4px; height: 28px; background: #4f46e5;
  margin: 0 2px; border-radius: 2px; transform-origin: bottom;
  animation: wave-bounce 0.8s ease-in-out infinite;
}
.wave-bar:nth-child(2) { animation-delay: 0.1s; }
.wave-bar:nth-child(3) { animation-delay: 0.2s; }
.wave-bar:nth-child(4) { animation-delay: 0.3s; }
.wave-bar:nth-child(5) { animation-delay: 0.4s; }

.md-content h2 { font-size: 1rem; font-weight: 700; color: #312e81; margin-top: 1.25rem; margin-bottom: 0.5rem; }
.md-content h3 { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 1rem; margin-bottom: 0.35rem; }
.md-content p  { margin-bottom: 0.75rem; }
.md-content ul, .md-content ol { padding-left: 1.5rem; margin-bottom: 0.75rem; }
.md-content ul li, .md-content ol li { margin-bottom: 0.4rem; }
.md-content strong { color: #0f172a; }
</style>

<script>
// ════════════════════════════════════════════════════════════════
// VOICE INTERVIEW STATE MACHINE — build 2026-05-02-r7 (transcript-based pause, 60s cap)
// ════════════════════════════════════════════════════════════════
console.log('eval-speech.php build: 2026-05-02-r7');

const CSRF = <?= json_encode(csrf_token()) ?>;
const KIDS = <?= json_encode(array_values(array_map(function($k){
    return [
        'id'   => (int)$k['id'],
        'name' => (string)$k['name'],
        'mt'   => (string)($k['mother_tongue'] ?: 'English'),
        'dob'  => (string)$k['dob'],
    ];
}, $kids)), JSON_UNESCAPED_UNICODE) ?>;

// Recent completed evaluation (within last 24h) — shown on reload so parent
// doesn't accidentally start a new ₹59 eval. They can still start fresh via button.
const RECENT_REPORT = <?= $recent_complete ? json_encode([
    'session_id'         => (int)$recent_complete['id'],
    'child_id'           => (int)$recent_complete['child_id'],
    'child_name'         => (string)$recent_complete['child_name'],
    'child_mt'           => (string)$recent_complete['child_mt'],
    'final_level'        => (int)$recent_complete['final_level'],
    'final_pct'          => (int)$recent_complete['final_pct'],
    'questions_asked'    => (int)$recent_complete['questions_asked'],
    'report_md'          => (string)$recent_complete['report_md'],
    'sample_exercise_md' => (string)$recent_complete['sample_exercise_md'],
    'final_level_name'   => eval_speech_level_desc((int)$recent_complete['final_level'])['name'],
    'completed_at'       => (string)$recent_complete['completed_at'],
], JSON_UNESCAPED_UNICODE) : 'null' ?>;

let currentChild = null;
let sessionId = null;
let currentQuestion = null;
let questionStartTs = 0;
let interviewStartTs = 0;

// Recording state
let mediaRecorder = null, audioChunks = [], audioStream = null;
let audioCtx = null, analyser = null;
let recognition = null;
let recordingStartTs = 0, firstSpeechTs = 0;
let liveTranscript = '', finalTranscript = '';
let acFeatures = {};
let volumeSamples = []; let silentTickCount = 0; let totalTicks = 0; let pauseCount = 0; let lastWasSilent = false;
let vizInterval = null; let pauseSilenceMs = 0; let sessionTimerInterval = null;
let _ttsActive = false; let _currentUtterance = null;

// ─── DOM helpers ──────────────────────────────────────────────
const $ = (id) => document.getElementById(id);
function show(id) {
  ['screenUnsupported','screenStart','screenMicCheck','screenInterview','screenReport']
    .forEach(s => { const el = $(s); if (el) el.classList.toggle('hidden', s !== id); });
}
function setStatus(text)   { const e = $('iStatusText'); if (e) e.textContent = text; }
function setStateIcon(emoji, pulse) {
  const e = $('iStateIcon'); if (!e) return;
  e.textContent = emoji;
  e.classList.toggle('mic-pulsing', !!pulse);
}

// ─── Browser support gate ─────────────────────────────────────
function browserSupports() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  return !!(SR && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
            && window.AudioContext || window.webkitAudioContext)
         && 'speechSynthesis' in window;
}

// ─── Language detection ──────────────────────────────────────
// We pick the language based on the QUESTION TEXT (not just mother tongue):
//   - Question contains Devanagari → Hindi (TTS reads beautifully, SR uses hi-IN)
//   - Otherwise → English (en-IN for both)
// This way, a Hindi-mother-tongue child gets Hindi questions in Devanagari
// (so TTS reads naturally), while an English-mother-tongue child gets English.
function isHindiText(text) {
  return /[\u0900-\u097F]/.test(text || '');
}
function langForQuestion(text) {
  return isHindiText(text) ? 'hi-IN' : 'en-IN';
}

// ─── TTS ──────────────────────────────────────────────────────
function speakText(text, lang, onDone) {
  if (!('speechSynthesis' in window)) { onDone && onDone(); return; }
  // Cancel any in-progress TTS
  try { window.speechSynthesis.cancel(); } catch(e) {}
  const u = new SpeechSynthesisUtterance(text);
  u.lang = lang || 'en-IN';
  u.rate = 0.92;
  u.pitch = 1.05;  // slightly child-friendly
  const voices = window.speechSynthesis.getVoices();
  const match = voices.find(v => v.lang === u.lang) || voices.find(v => v.lang.startsWith(u.lang.slice(0,2)));
  if (match) u.voice = match;
  u.onend   = function() { _ttsActive = false; _currentUtterance = null; onDone && onDone(); };
  u.onerror = u.onend;
  _ttsActive = true;
  _currentUtterance = u;
  window.speechSynthesis.speak(u);
}
function stopTts() {
  try { window.speechSynthesis.cancel(); } catch(e){}
  _ttsActive = false; _currentUtterance = null;
}

// ─── Mic permission check ─────────────────────────────────────
async function requestMicPermission() {
  try {
    const s = await navigator.mediaDevices.getUserMedia({ audio: true });
    s.getTracks().forEach(t => t.stop());
    return true;
  } catch (e) {
    return false;
  }
}

// ─── Start recording (after TTS finishes) ─────────────────────
async function startListening() {
  setStateIcon('👂', true);
  setStatus('Listening — your child can speak now…');
  $('iTranscriptBox').classList.remove('hidden');
  $('iActionRow').classList.remove('hidden');
  $('iActionRow').classList.add('flex');

  // Reset
  audioChunks = [];
  liveTranscript = '';
  finalTranscript = '';
  acFeatures = {};
  volumeSamples = []; silentTickCount = 0; totalTicks = 0; pauseCount = 0; lastWasSilent = false;
  firstSpeechTs = 0; pauseSilenceMs = 0;
  $('iLiveTranscript').textContent = '';
  recordingStartTs = Date.now();

  try {
    audioStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true }
    });
  } catch (e) {
    // Permission revoked mid-session
    abortToStart('Microphone access lost. Please reload and allow mic access.');
    return;
  }

  // MediaRecorder
  const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus'
              : MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
  try {
    mediaRecorder = mime ? new MediaRecorder(audioStream, { mimeType: mime }) : new MediaRecorder(audioStream);
  } catch (e) {
    abortToStart('Audio recording not supported.');
    return;
  }
  mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunks.push(e.data); };
  mediaRecorder.onstop = onStopRecording;
  mediaRecorder.start(250);

  // Web Audio analyser → acoustic features + auto-pause detection
  audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  const src = audioCtx.createMediaStreamSource(audioStream);
  analyser = audioCtx.createAnalyser();
  analyser.fftSize = 512;
  src.connect(analyser);
  const buf = new Uint8Array(analyser.frequencyBinCount);

  let lastTranscriptUpdateTs = Date.now();  // when the transcript last grew
  vizInterval = setInterval(() => {
    analyser.getByteFrequencyData(buf);
    let sum = 0; for (let i = 0; i < buf.length; i++) sum += buf[i];
    const avg = sum / buf.length / 255;
    volumeSamples.push(avg);
    totalTicks++;
    const isSilent = avg < 0.04;
    if (isSilent) silentTickCount++;
    else if (firstSpeechTs === 0) firstSpeechTs = Date.now();
    if (!lastWasSilent && isSilent) pauseCount++;
    lastWasSilent = isSilent;

    // Update mic-level visualizer bars
    const bars = $('iMicLevel') ? $('iMicLevel').children : null;
    if (bars && bars.length === 5) {
      const baseHeight = Math.min(100, Math.max(10, avg * 250));
      for (let i = 0; i < 5; i++) {
        const variation = 0.7 + (Math.random() * 0.6);
        bars[i].style.height = Math.min(100, baseHeight * variation) + '%';
      }
    }

    // ── Transcript-based pause detection (NOT volume-based) ──
    // Why: volume-based detection fails in noisy rooms (fan, AC, traffic) — the
    // mic always picks up SOMETHING, so isSilent is rarely true. The recognition
    // engine has built-in noise filtering, so we trust IT to decide what's speech.
    // Logic: if we have meaningful transcript AND it hasn't grown in N seconds,
    // the child has stopped speaking. The N depends on question type — short
    // answers get cut at 2s, narrative (describe) gets 4s to think mid-sentence.
    if (liveTranscript.trim().length >= 1) {
      const stableMs = Date.now() - lastTranscriptUpdateTs;
      const isDescribe = currentQuestion && currentQuestion.type === 'describe';
      const stableThresholdMs = isDescribe ? 4000 : 2000;
      if (stableMs > stableThresholdMs) {
        stopListening();
      }
    }
  }, 100);

  // Web Speech Recognition
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  recognition = new SR();
  recognition.continuous = true;
  recognition.interimResults = true;
  recognition.lang = langForQuestion(currentQuestion.prompt);
  recognition.onresult = function(e) {
    let interim = '', finalNew = '';
    let confSum = 0, confCount = 0;
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const t = e.results[i][0].transcript;
      if (e.results[i].isFinal) finalNew += t + ' ';
      else interim += t;
      if (e.results[i][0].confidence) {
        confSum += e.results[i][0].confidence;
        confCount++;
      }
    }
    if (finalNew) finalTranscript += finalNew;
    // liveTranscript captures whatever is currently visible (final OR interim).
    // Critical: keep interim text as the answer even if recognition gets cut off
    // before it converts to final. Otherwise a clearly-spoken word like "पानी"
    // gets lost when stopListening() fires before isFinal arrives.
    const combined = (finalTranscript + interim).trim();
    if (combined.length > 0 && combined !== liveTranscript) {
      liveTranscript = combined;
      $('iLiveTranscript').textContent = liveTranscript;
      // Reset stable-transcript clock — child is still speaking
      lastTranscriptUpdateTs = Date.now();
    }
    if (confCount > 0) acFeatures.transcript_confidence = confSum / confCount;
  };
  recognition.onerror = function(e) {
    // Show errors visibly so parent can troubleshoot
    if (e.error === 'no-speech') {
      // Common — silence detected. Don't alarm.
      $('iLiveTranscript').textContent = '(no speech detected yet — speak louder or closer to mic)';
    } else if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
      $('iLiveTranscript').innerHTML = '<span style="color:#dc2626">⚠ Microphone access blocked. Please allow in browser settings.</span>';
    } else if (e.error === 'audio-capture') {
      $('iLiveTranscript').innerHTML = '<span style="color:#dc2626">⚠ No microphone found.</span>';
    } else if (e.error === 'language-not-supported') {
      $('iLiveTranscript').innerHTML = '<span style="color:#dc2626">⚠ Speech recognition not available for this language. Switching to English.</span>';
    } else if (e.error !== 'aborted') {
      $('iLiveTranscript').innerHTML = '<span style="color:#dc2626">⚠ Recognition error: ' + e.error + '</span>';
    }
  };
  recognition.onend = function() {
    // Auto-restart if recording is still in progress (some browsers stop after each utterance)
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      try { recognition.start(); } catch(e) {}
    }
  };
  try { recognition.start(); } catch(e){}

  // Hard cap: 60s per answer (safety net only — auto-pause-from-transcript
  // should fire well before this for any normal answer).
  setTimeout(() => {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') stopListening();
  }, 60000);
}

function stopListening() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    try { mediaRecorder.stop(); } catch(e){}
  }
  if (recognition) {
    // Disable auto-restart before stopping
    recognition.onend = null;
    try { recognition.stop(); } catch(e){}
    try { recognition.abort(); } catch(e){}
  }
  if (audioStream) audioStream.getTracks().forEach(t => t.stop());
  if (vizInterval) { clearInterval(vizInterval); vizInterval = null; }
  $('iActionRow').classList.add('hidden');
  $('iActionRow').classList.remove('flex');
}

function onStopRecording() {
  // Salvage transcript from the live display element. The variable might have
  // been reset by a late recognition.onresult firing during stop, but the DOM
  // shows the user-visible text. Whatever the user saw, treat as their answer.
  const domShown = $('iLiveTranscript') ? ($('iLiveTranscript').textContent || '').trim() : '';
  const looksValid = domShown && !domShown.startsWith('(') && !domShown.startsWith('⚠');
  if (looksValid && (!liveTranscript || liveTranscript.length < domShown.length)) {
    liveTranscript = domShown;
  }

  // Compute acoustic features
  const dur = (Date.now() - recordingStartTs) / 1000;
  const wordCount = liveTranscript.split(/\s+/).filter(Boolean).length;
  acFeatures.duration_sec = dur;
  acFeatures.wpm = (dur > 0 && wordCount > 0) ? Math.round((wordCount / dur) * 60) : 0;
  acFeatures.silence_ratio = totalTicks > 0 ? (silentTickCount / totalTicks) : 0;
  acFeatures.pause_count = pauseCount;
  if (volumeSamples.length > 0) {
    const mean = volumeSamples.reduce((a,b)=>a+b,0) / volumeSamples.length;
    const variance = volumeSamples.reduce((a,b)=>a + (b-mean)*(b-mean), 0) / volumeSamples.length;
    acFeatures.volume_variance = variance;
  }
  acFeatures.time_to_first_speech_sec = firstSpeechTs > 0 ? (firstSpeechTs - recordingStartTs)/1000 : dur;

  // Build blob
  const blob = audioChunks.length > 0
    ? new Blob(audioChunks, { type: (mediaRecorder.mimeType || 'audio/webm') })
    : null;

  // If transcript empty, don't submit — let parent retry or skip
  if (!liveTranscript.trim()) {
    setStateIcon('🎙️', false);
    setStatus('Nothing was heard. Tap "Listen again" to retry, or "End" to give up on this question.');
    $('iLiveTranscript').textContent = '(silence)';
    $('iActionRow').classList.remove('hidden');
    $('iActionRow').classList.add('flex');
    // Replace Done button label with Skip-and-submit
    $('iDoneBtn').textContent = '⏭ Skip this question';
    $('iDoneBtn').onclick = function() { submitAnswer(blob); };
    $('iRetryBtn').onclick = function() {
      $('iDoneBtn').textContent = '✓ Done speaking';
      $('iDoneBtn').onclick = stopListening;
      startListening();
    };
    return;
  }

  submitAnswer(blob);
}

// ─── Submit answer to server, get next q or report ────────────
async function submitAnswer(audioBlob) {
  setStateIcon('🤔', false);
  setStatus('Listening to what your child said…');
  $('iTranscriptBox').classList.add('hidden');
  $('iActionRow').classList.add('hidden');
  $('iActionRow').classList.remove('flex');

  // Cycle through progress messages so user knows it's not frozen
  const progressMessages = [
    'Listening to what your child said…',
    'Understanding the answer…',
    'Choosing the next question for ' + (currentChild ? currentChild.name : 'your child') + '…',
    'Almost there…',
  ];
  let msgIdx = 0;
  const progressInterval = setInterval(function() {
    msgIdx = (msgIdx + 1) % progressMessages.length;
    setStatus(progressMessages[msgIdx]);
  }, 4000);
  // Stash so we can clear it after fetch returns
  window._evalProgressInterval = progressInterval;

  const sec = Math.max(1, Math.round((Date.now() - questionStartTs) / 1000));

  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', 'answer');
  fd.append('session_id', sessionId);
  fd.append('question_id', currentQuestion.question_id);
  fd.append('transcript', liveTranscript);
  fd.append('time_seconds', sec);
  ['transcript_confidence','duration_sec','wpm','volume_variance','silence_ratio','pause_count','time_to_first_speech_sec']
    .forEach(k => { if (acFeatures[k] !== undefined) fd.append(k, acFeatures[k]); });
  // NOTE: We deliberately do NOT upload the audio blob anymore. Each blob is
  // 50-200KB which on slow Indian mobile connections adds 5-10s to the round
  // trip. Transcript + acoustic features are what Claude needs to score; the
  // audio is just for QA. We can add an async post-flight upload later if needed.

  let data;
  // 30-second client-side abort so we never hang forever
  const abortCtrl = new AbortController();
  const abortTimer = setTimeout(function() {
    try { abortCtrl.abort(); } catch(e) {}
  }, 30000);
  console.log('[eval] submitting answer, transcript:', liveTranscript);
  const submitStart = Date.now();
  try {
    const r = await fetch('/eval-speech-api.php', { method: 'POST', body: fd, signal: abortCtrl.signal });
    clearTimeout(abortTimer);
    console.log('[eval] server responded in ' + ((Date.now() - submitStart) / 1000).toFixed(1) + 's, status: ' + r.status);
    const text = await r.text();
    try { data = JSON.parse(text); }
    catch (parseErr) {
      if (window._evalProgressInterval) { clearInterval(window._evalProgressInterval); window._evalProgressInterval = null; }
      setStateIcon('⚠️', false);
      setStatus('Server error');
      alert('Server returned non-JSON (status ' + r.status + '):\n\n' + text.substring(0, 300));
      // Offer recovery
      $('iActionRow').classList.remove('hidden');
      $('iActionRow').classList.add('flex');
      $('iRetryBtn').textContent = '🔄 Try again';
      $('iRetryBtn').onclick = function() {
        $('iRetryBtn').textContent = '🔄 Listen again';
        presentQuestion();
      };
      $('iDoneBtn').textContent = 'End evaluation';
      $('iDoneBtn').onclick = cancelInterview;
      return;
    }
  } catch (e) {
    clearTimeout(abortTimer);
    if (window._evalProgressInterval) { clearInterval(window._evalProgressInterval); window._evalProgressInterval = null; }
    const wasAbort = (e && e.name === 'AbortError');
    console.error('[eval] fetch failed after ' + ((Date.now() - submitStart) / 1000).toFixed(1) + 's:', e);
    setStateIcon('⚠️', false);
    setStatus(wasAbort
      ? 'Server is taking too long. Tap retry to send again.'
      : 'Network error. Tap retry below.');
    $('iActionRow').classList.remove('hidden');
    $('iActionRow').classList.add('flex');
    $('iRetryBtn').textContent = '🔄 Try again';
    $('iRetryBtn').onclick = function() { submitAnswer(audioBlob); };
    $('iDoneBtn').textContent = 'End evaluation';
    $('iDoneBtn').onclick = cancelInterview;
    return;
  }

  if (window._evalProgressInterval) { clearInterval(window._evalProgressInterval); window._evalProgressInterval = null; }

  if (data && data._timings) {
    console.log('[eval] server timings (ms):', data._timings);
  }

  if (data.error) {
    setStateIcon('⚠️', false);
    setStatus(data.error);
    // Offer recovery: retry or end
    $('iActionRow').classList.remove('hidden');
    $('iActionRow').classList.add('flex');
    $('iRetryBtn').textContent = '🔄 Try this question again';
    $('iRetryBtn').onclick = function() {
      $('iRetryBtn').textContent = '🔄 Listen again';
      $('iDoneBtn').textContent = '✓ Done speaking';
      $('iDoneBtn').onclick = stopListening;
      presentQuestion();
    };
    $('iDoneBtn').textContent = '⏭ Skip this question';
    $('iDoneBtn').onclick = function() {
      // Submit empty to advance to next q
      liveTranscript = '(error - skipped)';
      submitAnswer(null);
    };
    return;
  }

  if (data.should_stop) {
    showReport(data.report);
    return;
  }

  // Receive next question
  currentQuestion = data.question;
  presentQuestion();
}

// ─── Present question: speak it then start listening ──────────
function presentQuestion() {
  $('iSeqNo').textContent = 'Q' + currentQuestion.seq_no;
  $('iLevelLabel').textContent = 'Level L' + currentQuestion.level;
  $('iQuestionText').textContent = currentQuestion.prompt;
  // Progress bar: scale 1-8 to 100%
  const pct = Math.min(100, Math.round(currentQuestion.seq_no * 100 / 10));
  $('iProgressBar').style.width = pct + '%';

  setStateIcon('🔊', true);
  setStatus('Listen — speaking the question now…');
  $('iTranscriptBox').classList.add('hidden');
  $('iActionRow').classList.add('hidden');
  $('iActionRow').classList.remove('flex');

  // Reset Done button to default state in case it was repurposed as Skip
  $('iDoneBtn').textContent = '✓ Done speaking';
  $('iDoneBtn').onclick = stopListening;

  questionStartTs = Date.now();
  speakText(currentQuestion.prompt, langForQuestion(currentQuestion.prompt), function() {
    // After TTS ends, start listening
    setTimeout(startListening, 300);
  });
}

// ─── Show inline report ───────────────────────────────────────
function showReport(rep) {
  $('rChildName').textContent = currentChild.name;
  $('rChildPlanName').textContent = currentChild.name;
  $('rLevel').textContent = 'L' + rep.final_level;
  $('rPlanLevel').textContent = 'L' + rep.final_level;
  $('rLevelName').textContent = rep.final_level_name;
  $('rPct').textContent = rep.final_pct + '%';
  $('rQCount').textContent = rep.questions_asked;
  $('rReportMd').innerHTML = mdRender(rep.report_md || '');
  $('rExerciseMd').innerHTML = mdRender(rep.sample_exercise_md || '');
  $('rUpsellLink').href = '/module.php?key=plan_speech_week1&cid=' + (rep.child_id || currentChild.id);
  show('screenReport');
  if (sessionTimerInterval) clearInterval(sessionTimerInterval);
}

// Render a previously-completed evaluation (loaded on page render).
// Same as showReport but uses report fields directly (no currentChild dep)
// and shows the "recent" banner + "Start fresh" button.
function showRecentReport(rep) {
  $('rChildName').textContent = rep.child_name;
  $('rChildPlanName').textContent = rep.child_name;
  $('rLevel').textContent = 'L' + rep.final_level;
  $('rPlanLevel').textContent = 'L' + rep.final_level;
  $('rLevelName').textContent = rep.final_level_name;
  $('rPct').textContent = rep.final_pct + '%';
  $('rQCount').textContent = rep.questions_asked;
  $('rReportMd').innerHTML = mdRender(rep.report_md || '');
  $('rExerciseMd').innerHTML = mdRender(rep.sample_exercise_md || '');
  $('rUpsellLink').href = '/module.php?key=plan_speech_week1&cid=' + rep.child_id;

  // Format completed_at for display
  try {
    const d = new Date(rep.completed_at + 'Z');  // server gives UTC
    $('rCompletedAt').textContent = d.toLocaleString('en-IN', {
      day: 'numeric', month: 'short', year: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
  } catch (e) {
    $('rCompletedAt').textContent = rep.completed_at;
  }

  $('rRecentBanner').classList.remove('hidden');
  $('rStartFreshWrap').classList.remove('hidden');
  show('screenReport');
}

// Tiny markdown renderer (headings, bold, lists, paragraphs)
function mdRender(md) {
  if (!md) return '';
  let html = md.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
  html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  // Lists: lines starting with -
  html = html.replace(/(^|\n)(- .+(?:\n- .+)*)/g, function(_, pre, block) {
    const items = block.split(/\n/).map(l => '<li>' + l.replace(/^- /, '') + '</li>').join('');
    return pre + '<ul>' + items + '</ul>';
  });
  // Paragraphs from remaining text
  html = html.split(/\n{2,}/).map(b => {
    if (/^<(h\d|ul|ol)/.test(b.trim())) return b;
    return '<p>' + b.replace(/\n/g, '<br>') + '</p>';
  }).join('');
  return html;
}

// ─── Cancel handler ───────────────────────────────────────────
async function cancelInterview() {
  if (!confirm('End this evaluation? Your free slot (or ₹59) will not be refunded.')) return;
  stopListening();
  stopTts();
  if (sessionId) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'cancel');
    fd.append('session_id', sessionId);
    try { await fetch('/eval-speech-api.php', { method: 'POST', body: fd }); } catch(e){}
  }
  window.location.href = '/dashboard.php';
}

function abortToStart(msg) {
  stopListening();
  stopTts();
  alert(msg || 'Evaluation aborted.');
  window.location.reload();
}

// ─── Start the flow ───────────────────────────────────────────
async function startEvaluation() {
  const radio = document.querySelector('input[name=child_id]:checked');
  if (!radio) return;
  const cid = parseInt(radio.value, 10);
  currentChild = KIDS.find(k => k.id === cid);
  if (!currentChild) return;

  show('screenMicCheck');
  $('micCheckStatus').textContent = 'Asking your browser for microphone permission…';
  const ok = await requestMicPermission();
  if (!ok) {
    $('micCheckStatus').innerHTML = '<span class="text-rose-600">Microphone access was blocked. Please grant permission and try again.</span>';
    $('micRetryBtn').classList.remove('hidden');
    return;
  }
  $('micCheckStatus').textContent = 'Permission granted. Starting…';

  // Hit /start
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('action', 'start');
  fd.append('child_id', cid);

  let data;
  try {
    const r = await fetch('/eval-speech-api.php', { method: 'POST', body: fd });
    const text = await r.text();
    try { data = JSON.parse(text); }
    catch (parseErr) {
      // Server returned HTML/garbage — show first 300 chars for diagnosis
      alert('Server returned non-JSON (status ' + r.status + '):\n\n' + text.substring(0, 300));
      show('screenStart');
      return;
    }
  } catch (e) {
    alert('Network error starting evaluation: ' + e); return;
  }

  if (data.error) {
    if (data.redirect) { window.location.href = data.redirect; return; }
    $('flashStart').textContent = data.error;
    $('flashStart').classList.remove('hidden');
    show('screenStart');
    return;
  }

  sessionId = data.session_id;
  currentQuestion = data.question;
  $('iChildLabel').textContent = 'Speech Evaluation — ' + currentChild.name;
  show('screenInterview');
  interviewStartTs = Date.now();
  presentQuestion();
}

// ─── Init ─────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', function() {
  // Browser support check
  if (!browserSupports()) {
    show('screenUnsupported');
    return;
  }

  // If there's a recent completed evaluation (< 24h old), show it instead
  // of the start screen. Parent can still start a new one with the button.
  if (RECENT_REPORT) {
    showRecentReport(RECENT_REPORT);
  } else {
    show('screenStart');
  }

  // Wire "Start a new evaluation instead" — hide the recent report, show start
  const startFresh = $('rStartFreshBtn');
  if (startFresh) startFresh.onclick = function() {
    if (!confirm('Start a new evaluation? This will charge ₹59 (or use your free one if you haven\u2019t used it).')) return;
    show('screenStart');
  };

  // Wire start button enable/disable
  document.querySelectorAll('input[name=child_id]').forEach(r => {
    r.addEventListener('change', function() {
      const sb = $('startBtn'); if (sb) sb.disabled = false;
    });
  });

  const sb = $('startBtn');
  if (sb) sb.addEventListener('click', startEvaluation);

  const cb = $('iCancelBtn');
  if (cb) cb.addEventListener('click', cancelInterview);

  const db = $('iDoneBtn');
  if (db) db.onclick = stopListening;

  const retry = $('iRetryBtn');
  if (retry) retry.onclick = function() {
    $('iDoneBtn').textContent = '✓ Done speaking';
    $('iDoneBtn').onclick = stopListening;
    startListening();
  };

  const rb = $('micRetryBtn');
  if (rb) rb.addEventListener('click', startEvaluation);

  // Voices for TTS load asynchronously
  if ('speechSynthesis' in window) window.speechSynthesis.onvoiceschanged = function(){};

  // Stop TTS + mic on tab close
  window.addEventListener('beforeunload', function() {
    stopTts(); stopListening();
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
