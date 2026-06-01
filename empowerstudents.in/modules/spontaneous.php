<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'spontaneous', $band);

// Age-banded broad prompts (3 prompts; child picks one or does all)
$prompts_by_band = [
    'infant'  => [], // no spontaneous speech assessment for <2y
    'toddler' => [
        'Tell me about your favourite toy. What is its name? What do you do with it?',
        'Who is in your family? Tell me about them.',
        'What did you eat today? Did you like it?',
    ],
    'child'   => [
        'Describe your best friend. What do you do together? Why do you like them?',
        'If you could go anywhere in the world for a holiday, where would you go and why?',
        'Tell me about something that made you really happy recently.',
    ],
    'preteen' => [
        'What is one thing you wish adults understood about kids your age?',
        'If you started a club at school, what would it be about and why would others want to join?',
        'Describe a problem you solved on your own. What did you do?',
    ],
    'teen'    => [
        'What is something you care deeply about? Convince me why it matters.',
        'Where do you see yourself in 10 years, and what is one step you can take this year toward it?',
        'Describe a time you disagreed with someone you respect. How did you handle it?',
    ],
];
$prompts = $prompts_by_band[$band] ?? $prompts_by_band['child'];

if ($band === 'infant') {
    module_layout_open($child, 'Spontaneous speech');
    echo '<div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-amber-900">'
       . 'This module is not used below 2 years. Use the <strong>Behaviour</strong> module — it includes language and communication milestones for infants.'
       . '</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $payload = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    $items   = $payload['items'] ?? [];
    // Even with empty items (mic blocked / browser issues), we still
    // FINALIZE the assessment so the dashboard reflects the attempt.

    if (empty($items)) {
        $j = [
            'summary'         => 'Spontaneous speech session was completed but no audio or transcript was captured. This usually happens if the microphone permission was blocked or the browser doesn\'t support live transcription. Please try again on Chrome / Edge / Safari with mic permission allowed.',
            'home_activities' => ['Retry on a different browser with microphone access granted.'],
            'scores'          => null,
            'strengths'       => [],
            'areas_to_grow'   => [],
        ];
        $wpm = null;
    } else {
        $transcripts_text = '';
        $total_words = 0; $total_ms = 0;
        foreach ($items as $i => $it) {
            $tr = trim($it['transcript'] ?? '');
            $w  = $tr ? count(preg_split('/\s+/', $tr)) : 0;
            $total_words += $w;
            $total_ms    += (int)($it['duration_ms'] ?? 0);
            $transcripts_text .= "Prompt " . ($i + 1) . ": " . ($it['prompt'] ?? '') . "\n"
                              . "  Response transcript: " . ($tr ?: '(no transcript captured)') . "\n"
                              . "  Word count: " . $w . ", Duration: " . (int)($it['duration_ms'] ?? 0) . " ms\n\n";
        }
        $wpm = ($total_ms > 0) ? round($total_words / max(1, $total_ms / 60000), 1) : null;

    $sys = "You are a paediatric speech-language pathologist and child psychologist. "
         . "Analyse the spontaneous speech samples below. Score on five dimensions, each 0-10: "
         . "content_richness (vocabulary, ideas, detail), fluency (smoothness, pauses), "
         . "confidence (assertiveness, volume cues from word choice), grammar (sentence structure), "
         . "topic_coherence (sticks to topic, logical flow). "
         . "Be warm, parent-friendly, non-alarming. Indian context.";

    $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs ($band), "
          . "mother tongue: " . ($child['mother_tongue'] ?: 'unknown') . ".\n"
          . "Browser-transcribed responses (auto-STT may have errors):\n\n"
          . $transcripts_text
          . "Approx words/min across responses: " . ($wpm ?? 'n/a') . "\n\n"
          . "Return JSON exactly:\n"
          . '{"scores":{"content_richness":n,"fluency":n,"confidence":n,"grammar":n,"topic_coherence":n},'
          . '"strengths":["..."],"areas_to_grow":["..."],"summary":"3-5 sentences","home_activities":["3 specific ideas"]}';

    $j = claude_json($sys, $user, 1400, 0.4);
    if (!$j) {
        $j = ['summary' => 'Saved. Detailed analysis will appear in the report.', 'scores' => []];
    }

    // save audio blobs
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    foreach ($items as $i => $it) {
        if (!empty($it['audio_b64'])) {
            $bin = base64_decode(preg_replace('/^data:audio\/[a-z0-9-]+;base64,/i', '', $it['audio_b64']));
            if ($bin !== false) {
                $fname = 'sp2_' . $assessment['id'] . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.webm';
                $path  = UPLOAD_DIR . '/' . $fname;
                @file_put_contents($path, $bin);
                db()->prepare("INSERT INTO audio_recordings (assessment_id, prompt, transcript, file_path, duration_ms)
                               VALUES (?, ?, ?, ?, ?)")
                   ->execute([$assessment['id'], $it['prompt'] ?? '', $it['transcript'] ?? '', $fname, (int)($it['duration_ms'] ?? 0)]);
            }
        }
    }
    } // end else (items not empty)

    $scores = $j['scores'] ?? [];
    $avg    = (is_array($scores) && $scores) ? round(array_sum($scores) * 10 / max(1, count($scores)), 1) : null;
    $summary = $j['summary'] ?? 'Saved.';
    if (!empty($j['strengths']))      $summary .= "\n\nStrengths: " . implode('; ', $j['strengths']);
    if (!empty($j['areas_to_grow']))  $summary .= "\nAreas to grow: " . implode('; ', $j['areas_to_grow']);
    if (!empty($j['home_activities'])) $summary .= "\nTry at home: " . implode('; ', $j['home_activities']);

    $flags = [];
    foreach ($scores as $k => $v) if (is_numeric($v) && $v <= 3) $flags[] = ['q' => $k, 'a' => $v];

    finalize_assessment($assessment['id'], $avg, $band, $summary, $flags, ['items' => $items, 'ai' => $j, 'wpm' => $wpm]);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Spontaneous speech');
?>
<p class="text-slate-600 mb-2 max-w-3xl es-bi"
   data-en="Pick any one prompt (or do all three). Tap <strong>Start</strong>, speak naturally for 30–90 seconds, then <strong>Stop</strong>. We capture audio + a live transcript and analyse content, fluency and confidence."
   data-hi="कोई एक प्रश्न चुनें (या तीनों करें)। <strong>Start</strong> दबाएँ, 30–90 सेकंड स्वाभाविक रूप से बोलें, फिर <strong>Stop</strong> दबाएँ। हम ऑडियो और जीवंत लिप्यंतरण पकड़ते हैं और विषय-वस्तु, धाराप्रवाहता तथा आत्मविश्वास का विश्लेषण करते हैं।">
  Pick any one prompt (or do all three). Tap <strong>Start</strong>, speak naturally for 30–90 seconds, then <strong>Stop</strong>. We capture audio + a live transcript and analyse content, fluency and confidence.
</p>
<p class="text-xs text-slate-500 mb-6 es-bi"
   data-en="Works best in Chrome / Edge on a phone or laptop. Allow microphone access when asked."
   data-hi="फ़ोन या लैपटॉप पर Chrome / Edge में सबसे अच्छा काम करता है। पूछने पर माइक की अनुमति दें।">
  Works best in Chrome / Edge on a phone or laptop. Allow microphone access when asked.
</p>

<div id="prompts" class="space-y-4">
<?php foreach ($prompts as $i => $p): ?>
  <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm" data-i="<?= $i ?>">
    <p class="font-medium mb-3"><?= ($i + 1) ?>. <?= e($p) ?></p>
    <div class="flex flex-wrap gap-2 items-center">
      <button type="button" class="btn-start brand-grad text-white px-4 py-2 rounded-lg text-sm es-bi"
              data-en="🎙 Start" data-hi="🎙 शुरू">🎙 Start</button>
      <button type="button" class="btn-stop bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm hidden es-bi"
              data-en="⏹ Stop" data-hi="⏹ रोकें">⏹ Stop</button>
      <span class="status text-xs text-slate-500"></span>
    </div>
    <div class="transcript mt-3 text-sm text-slate-700 italic min-h-[1.5rem]"></div>
    <audio class="player mt-2 hidden w-full" controls></audio>
  </div>
<?php endforeach; ?>
</div>

<form method="post" id="finalForm" class="mt-6">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid"  value="<?= (int)$child['id'] ?>">
  <input type="hidden" name="payload" id="payload">
  <button type="submit" id="submitBtn" disabled class="brand-grad text-white px-5 py-2.5 rounded-lg disabled:opacity-50 es-bi"
          data-en="Analyse &amp; save" data-hi="विश्लेषण और सहेजें">Analyse &amp; save</button>
  <p class="text-xs text-slate-500 mt-2 es-bi"
     data-en="Record at least one prompt before submitting."
     data-hi="जमा करने से पहले कम से कम एक प्रश्न रिकॉर्ड करें।">Record at least one prompt before submitting.</p>
</form>

<script>
const items = <?= json_encode(array_map(fn($p) => ['prompt' => $p, 'type' => 'open'], $prompts)) ?>;
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

document.querySelectorAll('#prompts > div').forEach(card => {
  const i = +card.dataset.i;
  const startB = card.querySelector('.btn-start');
  const stopB  = card.querySelector('.btn-stop');
  const tEl    = card.querySelector('.transcript');
  const player = card.querySelector('.player');
  const status = card.querySelector('.status');

  // Per-card recording state — reset each Start
  let mediaRec = null, chunks = [], recog = null, startedAt = 0;
  let finalText = '', interimText = '';
  let recognitionEnded = false, recorderStopped = false, savedBlob = null, savedDuration = 0;
  let pushed = false, safetyTimeout = null;

  function maybeFinish() {
    if (pushed) return;
    if (!recognitionEnded || !recorderStopped) return;
    pushed = true;
    if (safetyTimeout) { clearTimeout(safetyTimeout); safetyTimeout = null; }
    const transcript = (finalText + ' ' + interimText).trim();
    const finalize = (b64OrNull) => {
      items[i].audio_b64   = b64OrNull;
      items[i].transcript  = transcript;
      items[i].duration_ms = savedDuration;
      if (savedBlob) {
        player.src = URL.createObjectURL(savedBlob);
        player.classList.remove('hidden');
      }
      if (transcript) tEl.textContent = transcript;
      status.textContent = transcript ? 'Saved (' + transcript.split(/\s+/).filter(Boolean).length + ' words)'
                                       : 'Saved (no words detected)';
      checkReady();
    };
    if (savedBlob) {
      const r = new FileReader();
      r.onload = () => finalize(r.result);
      r.readAsDataURL(savedBlob);
    } else {
      finalize(null);
    }
  }

  startB.onclick = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      // RESET — fresh state for this recording
      chunks = []; finalText = ''; interimText = '';
      recognitionEnded = false; recorderStopped = false; savedBlob = null; savedDuration = 0;
      pushed = false;

      mediaRec = new MediaRecorder(stream, { mimeType: 'audio/webm' });
      mediaRec.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
      mediaRec.onstop = () => {
        savedBlob     = new Blob(chunks, { type: 'audio/webm' });
        savedDuration = Date.now() - startedAt;
        stream.getTracks().forEach(t => t.stop());
        recorderStopped = true;
        maybeFinish();
      };
      mediaRec.start();
      startedAt = Date.now();
      tEl.textContent = '';
      status.textContent = '● Listening…';

      if (SR) {
        recog = new SR();
        recog.lang = 'en-IN';
        recog.continuous = true;
        recog.interimResults = true;
        recog.onresult = ev => {
          interimText = '';
          for (let j = ev.resultIndex; j < ev.results.length; j++) {
            const tx = ev.results[j][0].transcript;
            if (ev.results[j].isFinal) finalText += tx + ' ';
            else interimText += tx;
          }
          tEl.textContent = (finalText + ' ' + interimText).trim();
        };
        recog.onerror = () => { /* swallow */ };
        // CRITICAL: wait for the browser's onend before treating recognition as done.
        recog.onend = () => { recognitionEnded = true; maybeFinish(); };
        try { recog.start(); } catch(_) { recognitionEnded = true; }
      } else {
        tEl.textContent = '(Live transcript not supported in this browser.)';
        recognitionEnded = true;  // no recognition was started
      }

      startB.classList.add('hidden');
      stopB.classList.remove('hidden');
    } catch (err) {
      alert('Microphone access denied: ' + err.message);
    }
  };

  stopB.onclick = () => {
    stopB.classList.add('hidden');
    startB.classList.remove('hidden');
    startB.textContent = '🎙 Re-record';
    status.textContent = 'Saving…';

    // Stop recognition first — its onend fires async after pulling final results
    if (recog) {
      try { recog.stop(); } catch(_) { recognitionEnded = true; }
    } else {
      recognitionEnded = true;
    }
    // Then stop the recorder — its onstop fires async
    if (mediaRec && mediaRec.state !== 'inactive') {
      try { mediaRec.stop(); } catch(_) { recorderStopped = true; maybeFinish(); }
    } else {
      recorderStopped = true;
    }
    // Safety net: if a callback never fires within 4 sec, force-finish anyway
    safetyTimeout = setTimeout(() => {
      if (!pushed) {
        recognitionEnded = true;
        recorderStopped  = true;
        if (!savedBlob && chunks.length) {
          savedBlob     = new Blob(chunks, { type: 'audio/webm' });
          savedDuration = Date.now() - startedAt;
        }
        maybeFinish();
      }
    }, 4000);
  };
});

function checkReady() {
  // ENABLE submit if at least one prompt has *any* recording (audio OR transcript)
  const has = items.some(it => it.audio_b64 || (it.transcript && it.transcript.length));
  document.getElementById('submitBtn').disabled = !has;
}

document.getElementById('finalForm').addEventListener('submit', e => {
  // Send ALL items that have audio OR a transcript — don't filter on audio alone
  document.getElementById('payload').value = JSON.stringify({
    items: items.filter(it => it.audio_b64 || (it.transcript && it.transcript.length))
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
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
