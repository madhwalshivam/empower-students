<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'speech', $band);

// Sentences ramping in difficulty (paraphrased common test sentences)
$sentences = [
    'The sun is bright today.',
    'My puppy likes to play with the red ball.',
    'She sells seashells by the seashore.',
    'Peter Piper picked a peck of pickled peppers.',
    'Around the rugged rocks the ragged rascal ran.',
];
$open_question = 'Tell me about a happy day you remember. What happened, who was there, and how did you feel?';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $payload = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    $items   = $payload['items'] ?? [];
    // Even if no items captured (mic blocked / browser issue), we still
    // FINALIZE the assessment so it shows as "done" on the dashboard.
    // Otherwise dashboard.php sees no completion record and shows the
    // module as not started, which is confusing for the parent.

    if (empty($items)) {
        // No transcripts captured — likely mic blocked. Save a placeholder
        // assessment so the parent sees the attempt but knows to retry.
        $j = [
            'summary'   => 'Speech recording session was completed but no audio or transcript was captured. This usually happens if the microphone permission was blocked or the browser doesn\'t support live transcription. Please try again on Chrome or Edge with mic permission allowed.',
            'next_steps'=> 'Retry on Chrome / Edge / Safari with microphone permission granted.',
            'items'     => [],
            'overall'   => ['fluency' => null, 'articulation' => null, 'confidence' => null, 'stuttering' => null],
        ];
    } else {
        // Run AI analysis on the transcripts
        $transcripts_text = '';
        foreach ($items as $i => $it) {
            $transcripts_text .= "Item " . ($i + 1) . " (" . $it['type'] . ")\n"
                              . "  Prompt: " . ($it['prompt'] ?? '') . "\n"
                              . "  Transcript: " . ($it['transcript'] ?? '(no transcript)') . "\n"
                              . "  Duration: " . ($it['duration_ms'] ?? 0) . " ms\n\n";
        }

        $sys = "You are a paediatric speech-language pathologist. Be warm and non-clinical in tone. "
             . "Score each item on fluency (0-10), articulation clarity (0-10), confidence (0-10), and stuttering (0-10 where 0=none, 10=severe). "
             . "Then give an overall opinion (3-5 sentences) and a clear next-step suggestion (home practice / SLP referral if needed).";

        $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs, mother tongue: "
              . ($child['mother_tongue'] ?: 'unknown') . ".\n"
              . "Speech-task transcripts (browser auto-transcribed; may have errors):\n\n"
              . $transcripts_text
              . "\nReturn JSON exactly like:\n"
              . '{"items":[{"index":0,"fluency":n,"articulation":n,"confidence":n,"stuttering":n,"note":"..."}],'
              . '"overall":{"fluency":n,"articulation":n,"confidence":n,"stuttering":n},'
              . '"summary":"...","next_steps":"..."}';

        $j = claude_json($sys, $user, 1200, 0.3);

        if (!$j) {
            // soft fallback
            $j = ['summary' => 'Saved. Detailed AI analysis will appear in your report.',
                  'overall' => ['fluency' => null, 'articulation' => null, 'confidence' => null, 'stuttering' => null]];
        }
    }

    // Save audio blobs separately
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    foreach ($items as $i => $it) {
        if (!empty($it['audio_b64'])) {
            $bin = base64_decode(preg_replace('/^data:audio\/[a-z0-9-]+;base64,/i', '', $it['audio_b64']));
            if ($bin !== false) {
                $fname = 'sp_' . $assessment['id'] . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.webm';
                $path  = UPLOAD_DIR . '/' . $fname;
                file_put_contents($path, $bin);
                db()->prepare('INSERT INTO audio_recordings (assessment_id, prompt, transcript, file_path, duration_ms) VALUES (?,?,?,?,?)')
                   ->execute([$assessment['id'], $it['prompt'] ?? '', $it['transcript'] ?? '', $fname, (int)($it['duration_ms'] ?? 0)]);
            }
        }
    }

    $overall = $j['overall'] ?? [];
    $score_avg = null;
    $vals = array_filter([$overall['fluency'] ?? null, $overall['articulation'] ?? null, $overall['confidence'] ?? null], 'is_numeric');
    if ($vals) $score_avg = round(array_sum($vals) / count($vals) * 10, 1); // out of 100

    finalize_assessment($assessment['id'], $score_avg, null,
        ($j['summary'] ?? '') . "\n\nNext steps: " . ($j['next_steps'] ?? ''),
        $j['items'] ?? [],
        ['transcripts' => $items, 'ai' => $j]);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Speech & voice');
?>
<p class="text-slate-600 mb-4 max-w-3xl es-bi"
   data-en="<?= e($child['name']) ?> will read four short sentences and answer one open question. Recording happens in the browser; audio and the auto-transcript are sent to our AI for a fluency, tone, confidence and stuttering analysis."
   data-hi="<?= e($child['name']) ?> चार छोटे वाक्य पढ़ेंगे और एक खुले सवाल का उत्तर देंगे। रिकॉर्डिंग ब्राउज़र में होती है; ऑडियो और लिप्यंतरण हमारे AI को धाराप्रवाहता, स्वर, आत्मविश्वास और हकलाने के विश्लेषण के लिए भेजे जाते हैं।">
  <?= e($child['name']) ?> will read four short sentences and answer one open question.
  Recording happens in the browser; audio and the auto-transcript are sent to our AI for a fluency, tone, confidence and stuttering analysis.
</p>

<div id="permission-card" class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 text-sm">
  <p class="font-semibold mb-1 es-bi" data-en="Microphone permission" data-hi="माइक्रोफ़ोन की अनुमति">Microphone permission</p>
  <p class="es-bi"
     data-en="When you press <em>Start</em>, your browser will ask for mic access. Please allow it. Best in Chrome / Edge / Safari (latest)."
     data-hi="जब आप <em>Start</em> दबाएँगे, ब्राउज़र माइक की अनुमति माँगेगा। कृपया अनुमति दें। Chrome / Edge / Safari (नवीनतम) में सबसे अच्छा काम करता है।">
    When you press <em>Start</em>, your browser will ask for mic access. Please allow it. Best in Chrome / Edge / Safari (latest).
  </p>
</div>

<div id="task-card" class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm">
  <p class="text-sm text-slate-500 mb-1">
    <span class="es-bi" data-en="Task" data-hi="कार्य">Task</span>
    <span id="taskNo">1</span> / <?= count($sentences) + 1 ?>
  </p>
  <!-- IMPORTANT: taskType + taskPrompt are dynamic — JS owns them. NO es-bi class here. -->
  <p class="text-xs uppercase tracking-wide text-indigo-600 font-semibold" id="taskType">Read aloud</p>
  <h2 id="taskPrompt" class="text-2xl font-semibold mt-2 mb-6">Press start to begin.</h2>

  <div class="flex flex-wrap gap-2 mb-4">
    <button id="btnStart"  class="brand-grad text-white font-semibold px-5 py-2.5 rounded-lg es-bi"
            data-en="Start recording" data-hi="रिकॉर्डिंग शुरू करें">Start recording</button>
    <button id="btnStop"   class="hidden bg-rose-600 text-white font-semibold px-5 py-2.5 rounded-lg es-bi"
            data-en="Stop &amp; next" data-hi="रोकें और आगे">Stop &amp; next</button>
    <button id="btnSkip"   class="hidden bg-slate-200 text-slate-700 font-semibold px-5 py-2.5 rounded-lg es-bi"
            data-en="Skip" data-hi="छोड़ें">Skip</button>
    <button id="btnFinish" class="hidden bg-emerald-600 text-white font-semibold px-5 py-2.5 rounded-lg es-bi"
            data-en="Finish &amp; analyse" data-hi="समाप्त करें">Finish &amp; analyse</button>
  </div>
  <p id="liveStatus" class="text-sm text-slate-500"></p>
  <p id="liveText"   class="mt-2 text-sm text-slate-700 italic min-h-[2em]"></p>
</div>

<form id="form" method="post" class="hidden">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid" value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="payload" id="payload">
</form>

<script>
const SENTENCES = <?= json_encode($sentences) ?>;
const OPEN_Q   = <?= json_encode($open_question) ?>;
const TASKS = SENTENCES.map(s => ({ type: 'read', prompt: s }));
TASKS.push({ type: 'spontaneous', prompt: OPEN_Q });

let idx = 0;
let mediaRecorder = null;
let chunks = [];
let recStartedAt = 0;
let results = [];
let recognition = null;
let interimTranscript = '';
let finalTranscript = '';

const $taskNo  = document.getElementById('taskNo');
const $taskType= document.getElementById('taskType');
const $prompt  = document.getElementById('taskPrompt');
const $btnStart= document.getElementById('btnStart');
const $btnStop = document.getElementById('btnStop');
const $btnSkip = document.getElementById('btnSkip');
const $btnFin  = document.getElementById('btnFinish');
const $status  = document.getElementById('liveStatus');
const $live    = document.getElementById('liveText');

function showTask() {
  const isHi = (function(){ try { return localStorage.getItem('es_lang') === 'hi'; } catch(_){ return false; } })();
  if (idx >= TASKS.length) {
    $taskType.textContent = isHi ? 'सभी कार्य पूरे' : 'All tasks completed';
    $prompt.textContent   = isHi ? '"समाप्त करें" दबाकर AI विश्लेषण के लिए भेजें।' : 'Press "Finish & analyse" to send for AI analysis.';
    $btnStart.classList.add('hidden'); $btnStop.classList.add('hidden'); $btnSkip.classList.add('hidden');
    $btnFin.classList.remove('hidden');
    return;
  }
  const t = TASKS[idx];
  $taskNo.textContent = idx + 1;
  $taskType.textContent = t.type === 'read'
      ? (isHi ? 'ज़ोर से पढ़ें' : 'Read aloud')
      : (isHi ? 'खुला प्रश्न' : 'Open question');
  $prompt.textContent = t.prompt;
  $btnStart.classList.remove('hidden');
  $btnStop.classList.add('hidden');
  $btnSkip.classList.remove('hidden');
  $live.textContent = '';
  $status.textContent = '';
}

function startRec() {
  navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
    // Reset all state for the new task — critical to avoid leaks from prior task
    chunks = [];
    finalTranscript = ''; interimTranscript = '';
    let recognitionEnded = false;
    let recorderStopped  = false;
    let pushed           = false;     // guard against double-push
    let savedBlob        = null;
    let savedDuration    = 0;

    function maybeAdvance() {
      if (pushed) return;
      if (!recognitionEnded || !recorderStopped) return;
      pushed = true;
      // Both recognition AND recorder are done — safe to push & advance.
      const transcript = (finalTranscript + ' ' + interimTranscript).trim();
      if (savedBlob) {
        const reader = new FileReader();
        reader.onloadend = () => {
          results.push({
            type: TASKS[idx].type, prompt: TASKS[idx].prompt,
            transcript: transcript,
            duration_ms: savedDuration,
            audio_b64: reader.result,
          });
          idx++; showTask();
        };
        reader.readAsDataURL(savedBlob);
      } else {
        // No audio captured — push transcript-only
        results.push({
          type: TASKS[idx].type, prompt: TASKS[idx].prompt,
          transcript: transcript,
          duration_ms: savedDuration,
          audio_b64: null,
        });
        idx++; showTask();
      }
    }

    mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
    mediaRecorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    mediaRecorder.onstop = () => {
      savedBlob     = new Blob(chunks, { type: 'audio/webm' });
      savedDuration = Date.now() - recStartedAt;
      stream.getTracks().forEach(t => t.stop());
      recorderStopped = true;
      maybeAdvance();
    };
    mediaRecorder.start();
    recStartedAt = Date.now();
    $btnStart.classList.add('hidden'); $btnSkip.classList.add('hidden');
    $btnStop.classList.remove('hidden');
    $status.textContent = '● Recording…';

    // Try Web Speech API for live transcript
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SR) {
      recognition = new SR();
      recognition.continuous = true;
      recognition.interimResults = true;
      recognition.lang = 'en-IN';
      recognition.onresult = ev => {
        interimTranscript = ''; let f = '';
        for (let i = ev.resultIndex; i < ev.results.length; i++) {
          const tx = ev.results[i][0].transcript;
          if (ev.results[i].isFinal) f += tx + ' ';
          else interimTranscript += tx;
        }
        finalTranscript += f;
        $live.textContent = (finalTranscript + ' ' + interimTranscript).trim();
      };
      recognition.onerror = e => { /* swallow */ };
      // CRITICAL: wait for recognition's end event before we consider transcript "complete"
      recognition.onend = () => {
        recognitionEnded = true;
        maybeAdvance();
      };
      try { recognition.start(); } catch(e) { recognitionEnded = true; }
    } else {
      $live.textContent = '(Live transcript not supported in this browser.)';
      // No recognition started, so mark it ended immediately
      recognitionEnded = true;
    }

    // Stash the cleanup helper on a global so stopRec can trigger it
    window._currentRecStop = () => {
      $status.textContent = 'Saving…';
      $btnStop.classList.add('hidden');
      // Stop recognition first — its onend fires async
      if (recognition) {
        try { recognition.stop(); } catch(e) { recognitionEnded = true; }
      } else {
        recognitionEnded = true;
      }
      // Then stop the recorder — its onstop fires async
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try { mediaRecorder.stop(); } catch(e) { recorderStopped = true; maybeAdvance(); }
      } else {
        recorderStopped = true;
      }
      // Safety net: if neither callback fires within 3 sec, force-advance
      setTimeout(() => {
        if (!pushed) { recognitionEnded = true; recorderStopped = true; maybeAdvance(); }
      }, 3000);
    };
  }).catch(err => {
    alert('Microphone access was denied or is unavailable. ' + err.message);
  });
}

function stopRec() {
  if (typeof window._currentRecStop === 'function') {
    window._currentRecStop();
    window._currentRecStop = null;
  }
}

function skipRec() {
  results.push({
    type: TASKS[idx].type, prompt: TASKS[idx].prompt,
    transcript: '', duration_ms: 0, audio_b64: null
  });
  idx++; showTask();
}

$btnStart.onclick = startRec;
$btnStop.onclick  = stopRec;
$btnSkip.onclick  = skipRec;
$btnFin.onclick   = () => {
  document.getElementById('payload').value = JSON.stringify({ items: results });
  document.getElementById('form').submit();
};
showTask();
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
    // After language toggle, refresh both static labels AND the dynamic taskType/taskPrompt
    b.addEventListener('click', () => setTimeout(() => {
      applyBi();
      if (typeof showTask === 'function') showTask();
    }, 50));
  });
})();
</script>

<?php module_layout_close(); ?>
