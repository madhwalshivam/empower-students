<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
module_require_credits('pulse_check');
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'pulse_check', $band);

if ($age < 5) {
    module_layout_open($child, 'Pulse &amp; breath check');
    echo '<div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-amber-900">'
       . 'This camera-based check needs a still finger for 15 seconds — best for children 5 and above. '
       . 'For little ones, please use the regular <a href="/modules/health.php?cid=' . (int)$child['id'] . '" class="underline">Health module</a> instead.'
       . '</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $payload = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    $pulse   = (int)($payload['pulse'] ?? 0);
    $bh      = (int)($payload['breath_hold_s'] ?? 0);
    $method  = $payload['method'] ?? 'manual';

    // Score 0-100. Pulse: <=80 = 100, 120 = 0. Breath-hold: 0-60s scaled.
    $pulse_score = $pulse <= 0 ? null : max(0.0, min(100.0, round(100.0 * (120 - max(60, min(120, $pulse))) / (120 - 60), 1)));
    $breath_score = $bh <= 0 ? null : min(100.0, round(100.0 * $bh / 60.0, 1));
    $scores = array_filter([$pulse_score, $breath_score], fn($v) => $v !== null);
    $overall = $scores ? round(array_sum($scores) / count($scores), 1) : null;

    $sys = "You are a paediatrician. Comment briefly on resting pulse vs age (3-5 sentences). "
         . "Indian context, parent-friendly. If pulse > 110 in a 5-12 yr old or > 100 in a teen at rest, suggest a paediatric review (could be anxiety, fever or anaemia). "
         . "If breath-hold is < 15 sec for a 5+ year old, mention possible deconditioning or anxious breathing pattern.";
    $user = "Child: " . $child['name'] . ", age " . round($age, 1) . " yrs.\n"
          . "Resting pulse: " . ($pulse ?: 'n/a') . " bpm (method: $method)\n"
          . "Breath-hold (Buteyko): " . ($bh ?: 'n/a') . " seconds\n";
    $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 400, 0.4);
    if ($summary === '') $summary = "Pulse $pulse bpm. Breath-hold $bh sec.";

    $flags = [];
    if ($pulse > 0 && $pulse > 110) $flags[] = ['q' => 'High resting pulse', 'a' => $pulse];
    if ($bh > 0 && $bh < 15)        $flags[] = ['q' => 'Short breath-hold',  'a' => $bh];

    finalize_assessment($assessment['id'], $overall, $band, $summary, $flags, [
        'pulse' => $pulse, 'breath_hold_s' => $bh, 'method' => $method,
        'pulse_score' => $pulse_score, 'breath_score' => $breath_score,
    ]);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Pulse & breath check');
?>
<p class="text-slate-600 mb-6 max-w-3xl es-bi"
   data-en="Two quick measurements: a 15-second camera pulse (PPG) and a breath-hold timer. Best on a phone with rear camera + flash."
   data-hi="दो त्वरित माप: 15 सेकंड का कैमरा पल्स (PPG) और साँस-रोकने का टाइमर। पीछे के कैमरे + फ़्लैश वाले फ़ोन पर सबसे अच्छा।">
  Two quick measurements: a 15-second camera pulse (PPG) and a breath-hold timer. Best on a phone with rear camera + flash.
</p>

<div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm mb-4">
  <h3 class="font-semibold mb-2 es-bi" data-en="❤️ Pulse (PPG)" data-hi="❤️ नाड़ी (PPG)">❤️ Pulse (PPG)</h3>
  <p class="text-sm text-slate-600 mb-3 es-bi"
     data-en="Place a fingertip firmly over the rear camera lens (it'll turn the torch on automatically if available). Sit quietly first."
     data-hi="उँगली का पोर पीछे के कैमरे के लेंस पर मज़बूती से रखें (अगर उपलब्ध हो तो टॉर्च अपने आप जलेगी)। पहले शांत होकर बैठें।">
    Place a fingertip firmly over the rear camera lens. Sit quietly first.
  </p>
  <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center">
    <video id="ppg_video" playsinline muted autoplay class="hidden mx-auto w-32 h-32 rounded-full object-cover"></video>
    <canvas id="ppg_canvas" width="100" height="100" class="hidden"></canvas>
    <div id="ppg_status" class="text-sm text-slate-700 es-bi"
         data-en='Tap "Start PPG" to begin a 15-sec measurement.'
         data-hi='15 सेकंड की माप शुरू करने के लिए "Start PPG" दबाएँ।'>Tap "Start PPG" to begin a 15-sec measurement.</div>
    <button type="button" id="ppg_btn" onclick="startPPG()"
            class="brand-grad text-white px-4 py-2 rounded-lg text-sm mt-3 es-bi"
            data-en="▶ Start PPG (15 sec)" data-hi="▶ PPG शुरू करें (15 सेकंड)">▶ Start PPG (15 sec)</button>
  </div>
  <label class="block text-sm font-medium text-slate-700 mt-4 es-bi"
         data-en="Or enter manually (bpm)" data-hi="या मैन्युअली डालें (bpm)">Or enter manually (bpm)</label>
  <input type="number" id="pulse_in" min="40" max="200" placeholder="e.g. 92" class="mt-1 w-full sm:w-48 border-slate-200 rounded-lg"
         data-i18n-placeholder-en="e.g. 92" data-i18n-placeholder-hi="जैसे 92">
</div>

<div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm mb-4">
  <h3 class="font-semibold mb-2 es-bi" data-en="🫁 Breath-hold (Buteyko)" data-hi="🫁 साँस रोकें (बुटेको)">🫁 Breath-hold (Buteyko)</h3>
  <p class="text-sm text-slate-600 mb-3 es-bi"
     data-en="Take a normal breath in, then press <strong>Start</strong> and hold. Press <strong>Stop</strong> when you feel the urge to breathe."
     data-hi="सामान्य साँस अंदर लें, फिर <strong>Start</strong> दबाकर रोकें। जब साँस लेने की इच्छा हो तब <strong>Stop</strong> दबाएँ।">
    Take a normal breath in, then press <strong>Start</strong> and hold. Press <strong>Stop</strong> when you feel the urge to breathe.
  </p>
  <div class="text-5xl font-mono font-bold text-center text-indigo-700 bg-slate-50 rounded-xl py-4" id="bh_display">0.0</div>
  <div class="flex gap-2 mt-3">
    <button type="button" id="bh_start" onclick="bhStart()" class="brand-grad text-white px-4 py-2 rounded-lg text-sm es-bi"
            data-en="▶ Start" data-hi="▶ शुरू">▶ Start</button>
    <button type="button" id="bh_stop" onclick="bhStop()" class="bg-rose-500 text-white px-4 py-2 rounded-lg text-sm hidden es-bi"
            data-en="⏹ Stop" data-hi="⏹ रोकें">⏹ Stop</button>
    <button type="button" onclick="bhReset()" class="bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm es-bi"
            data-en="↻ Reset" data-hi="↻ रीसेट">↻ Reset</button>
  </div>
  <label class="block text-sm font-medium text-slate-700 mt-4 es-bi"
         data-en="Or enter manually (seconds)" data-hi="या मैन्युअली डालें (सेकंड में)">Or enter manually (seconds)</label>
  <input type="number" id="bh_manual" min="0" max="180" placeholder="e.g. 35" class="mt-1 w-full sm:w-48 border-slate-200 rounded-lg"
         data-i18n-placeholder-en="e.g. 35" data-i18n-placeholder-hi="जैसे 35">
</div>

<form method="post" id="endForm">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid" value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="payload" id="payload">
  <button class="brand-grad text-white px-6 py-3 rounded-lg font-medium es-bi"
          data-en="Save &amp; analyse" data-hi="सहेजें और विश्लेषण">Save &amp; analyse</button>
</form>

<script>
// ── PPG pulse measurement (15 sec red-channel peak detection) ──
let ppgStream = null, ppgData = [], ppgTimer = null, ppgMethod = 'manual';
async function startPPG() {
  const status = document.getElementById('ppg_status');
  const btn = document.getElementById('ppg_btn');
  try {
    ppgStream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment', width: 320, height: 240}, audio: false});
    const v = document.getElementById('ppg_video');
    v.srcObject = ppgStream; v.classList.remove('hidden');
    const track = ppgStream.getVideoTracks()[0];
    try { if (track.getCapabilities && track.getCapabilities().torch) await track.applyConstraints({advanced: [{torch: true}]}); } catch(_){}
    status.textContent = '📷 Measuring — keep finger still…'; btn.disabled = true;
    ppgData = [];
    const cv = document.getElementById('ppg_canvas');
    const ctx = cv.getContext('2d');
    const t0 = Date.now(), seconds = 15;
    ppgTimer = setInterval(() => {
      ctx.drawImage(v, 0, 0, 100, 100);
      const d = ctx.getImageData(40, 40, 20, 20).data;
      let r = 0; for (let i = 0; i < d.length; i += 4) r += d[i];
      ppgData.push({t: (Date.now() - t0) / 1000, v: r / (d.length / 4)});
      const rem = seconds - Math.floor((Date.now() - t0) / 1000);
      status.textContent = `⏱ ${rem}s remaining (${ppgData.length} samples)`;
      if (Date.now() - t0 >= seconds * 1000) finishPPG();
    }, 66);
  } catch (e) {
    status.textContent = '❌ Camera denied. Please type the pulse below.';
  }
}
function finishPPG() {
  clearInterval(ppgTimer);
  if (ppgStream) { ppgStream.getTracks().forEach(t => t.stop()); ppgStream = null; }
  document.getElementById('ppg_video').classList.add('hidden');
  document.getElementById('ppg_btn').disabled = false;
  const vals = ppgData.map(d => d.v);
  const mean = vals.reduce((a,b) => a + b, 0) / vals.length;
  let peaks = 0;
  for (let i = 2; i < vals.length - 2; i++) {
    if (vals[i] > mean && vals[i] > vals[i-1] && vals[i] > vals[i+1] && vals[i] > vals[i-2] && vals[i] > vals[i+2]) peaks++;
  }
  const dur = ppgData[ppgData.length-1].t - ppgData[0].t;
  const bpm = Math.round(peaks / dur * 60);
  const status = document.getElementById('ppg_status');
  if (bpm >= 40 && bpm <= 200) {
    document.getElementById('pulse_in').value = bpm;
    ppgMethod = 'ppg_camera';
    status.innerHTML = `✓ Detected: <strong>${bpm} bpm</strong>`;
  } else {
    status.innerHTML = `⚠ Signal unclear (${bpm} bpm). Please type it below.`;
  }
}

// ── Breath-hold timer ──
let bhT0 = 0, bhInt = null, bhElapsed = 0;
function bhStart() {
  bhT0 = Date.now() - bhElapsed;
  document.getElementById('bh_start').classList.add('hidden');
  document.getElementById('bh_stop').classList.remove('hidden');
  bhInt = setInterval(() => {
    bhElapsed = Date.now() - bhT0;
    document.getElementById('bh_display').textContent = (bhElapsed / 1000).toFixed(1);
  }, 50);
}
function bhStop() {
  clearInterval(bhInt);
  document.getElementById('bh_start').classList.remove('hidden');
  document.getElementById('bh_stop').classList.add('hidden');
  document.getElementById('bh_manual').value = Math.round(bhElapsed / 1000);
}
function bhReset() {
  clearInterval(bhInt); bhElapsed = 0; bhT0 = 0;
  document.getElementById('bh_display').textContent = '0.0';
  document.getElementById('bh_manual').value = '';
  document.getElementById('bh_start').classList.remove('hidden');
  document.getElementById('bh_stop').classList.add('hidden');
}

document.getElementById('endForm').addEventListener('submit', e => {
  document.getElementById('payload').value = JSON.stringify({
    pulse: parseInt(document.getElementById('pulse_in').value, 10) || 0,
    breath_hold_s: parseInt(document.getElementById('bh_manual').value, 10) || 0,
    method: ppgMethod,
  });
});
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
    document.querySelectorAll('[data-i18n-placeholder-en]').forEach(el => {
      el.placeholder = (lang === 'hi') ? (el.dataset.i18nPlaceholderHi || el.dataset.i18nPlaceholderEn) : el.dataset.i18nPlaceholderEn;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
