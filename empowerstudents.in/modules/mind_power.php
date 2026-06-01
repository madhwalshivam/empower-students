<?php
/**
 * mind_power.php — Real cognitive screening, true sequential page-by-page flow.
 * Layout fix: every element inside .mp-card is forced display:block.
 */
require_once __DIR__ . '/_common.php';
$child = module_require_child();
module_require_credits('mind_power');
$age  = calc_age_years($child['dob']);
$band = age_band($age);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $payload = json_decode($_POST['payload'] ?? '[]', true) ?: [];
    $a = start_or_resume_assessment($child['id'], 'mind_power', $band);

    $sub = $payload['subtests'] ?? [];
    $scores = [
        'digit_span'  => max(0, min(10, (int)($sub['digit_span']['span']     ?? 0))),
        'visual_span' => max(0, min(10, (int)($sub['visual_span']['span']    ?? 0))),
        'attention'   => max(0, min(100,(int)($sub['attention']['accuracy'] ?? 0))),
        'reasoning'   => max(0, min(100,(int)($sub['reasoning']['accuracy'] ?? 0))),
    ];
    $expect_span = $age < 5 ? 3 : ($age < 7 ? 4 : ($age < 10 ? 5 : ($age < 13 ? 6 : 7)));
    $ds_pct = min(100, round(($scores['digit_span']  / $expect_span) * 100));
    $vs_pct = min(100, round(($scores['visual_span'] / $expect_span) * 100));
    $composite = round(($ds_pct * 0.30) + ($vs_pct * 0.30) + ($scores['attention'] * 0.20) + ($scores['reasoning'] * 0.20));

    $flags = [];
    if ($scores['digit_span']  < max(2, $expect_span - 2)) $flags[] = ['q' => 'Digit span very low for age',  'severity' => 'concern'];
    if ($scores['visual_span'] < max(2, $expect_span - 2)) $flags[] = ['q' => 'Visual span very low for age', 'severity' => 'concern'];
    if ($scores['attention']   < 50) $flags[] = ['q' => 'Attention/inhibition below average', 'severity' => 'watch'];
    if ($scores['reasoning']   < 40) $flags[] = ['q' => 'Pattern reasoning below average',   'severity' => 'watch'];

    $sys = "You are a paediatric clinician. The child just completed a 4-part cognitive screening: "
         . "digit span, visual pattern memory, attention/inhibition, and pattern reasoning. "
         . "Write a warm, plain-English summary for the parent in 3 short paragraphs:\n"
         . "1. What was tested and what the scores mean.\n"
         . "2. Where the child did well.\n"
         . "3. What to nurture, plus when to consult a specialist if relevant.\n"
         . "Indian context. Avoid alarm. Never give a diagnosis.";
    $context = ['age_years'=>round($age,1),'age_band'=>$band,'subtests'=>$scores,'expected_span'=>$expect_span,'composite_pct'=>$composite,'flags'=>$flags];
    $ai_summary = claude_chat($sys, "Child cognitive results JSON:\n" . json_encode($context));

    finalize_assessment($a['id'], $composite, 'composite', $ai_summary, $flags, $payload);
    header('Location: /child.php?id=' . (int)$child['id'] . '&done=mind_power');
    exit;
}

$stage = preg_replace('/[^a-z0-9_]/', '', $_GET['stage'] ?? 'intro');
$valid_stages = ['intro','g1_intro','g1','g1_done','g2_intro','g2','g2_done','g3_intro','g3','g3_done','g4_intro','g4','g4_done','all_done'];
if (!in_array($stage, $valid_stages, true)) $stage = 'intro';
$cid = (int)$child['id'];
function url_for_stage($cid, $stage) { return '/modules/mind_power.php?cid=' . $cid . '&stage=' . $stage; }

$page_title = 'Mind Power';
require __DIR__ . '/../includes/header.php';
?>

<style>
  /* ── Card and forced block layout ─────────────────────────────── */
  .mp-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 1rem;
    padding: 2rem 1.5rem;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    max-width: 36rem;
    margin: 1rem auto;
    text-align: center;
  }

  /* CRITICAL — force every direct child of mp-card AND its inner divs
     to render as proper blocks with vertical spacing. Tailwind preflight
     strips default display from p, h1-h6, so we put it back here.
     Use :not(.hidden) so Tailwind's display:none on .hidden still wins. */
  .mp-card > *:not(.hidden),
  .mp-card .mp-block:not(.hidden) {
    display: block;
    width: 100%;
  }
  /* Belt-and-braces: .hidden ALWAYS wins, on anything */
  .hidden { display: none !important; }

  .mp-emoji {
    display: block;
    font-size: 4rem;
    line-height: 1;
    margin: 0 auto 1rem;
  }
  .mp-eyebrow {
    display: block;
    letter-spacing: 0.12em;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    margin-bottom: 0.5rem;
    color: inherit;
  }
  .mp-title {
    display: block;
    font-size: 1.6rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.25;
    letter-spacing: -0.02em;
    margin: 0 auto 0.75rem;
  }
  .mp-desc {
    display: block;
    color: #475569;
    line-height: 1.6;
    margin: 0 auto 0.5rem;
    max-width: 28rem;
  }
  .mp-hint {
    display: block;
    color: #94a3b8;
    font-size: 0.75rem;
    margin: 0.75rem auto 1.5rem;
    max-width: 28rem;
  }
  .mp-cta {
    display: block;
    margin-top: 1.5rem;
    text-align: center;
  }
  .mp-cta button, .mp-cta a {
    display: inline-flex; align-items: center; gap: 0.5rem;
    font-weight: 700; padding: 0.85rem 1.75rem; border-radius: 0.75rem;
    color: white; box-shadow: 0 8px 16px -8px rgba(79,70,229,0.5);
    text-decoration: none;
  }
  .mp-progress-mini {
    text-align: center; font-size: 0.75rem; color: #64748b;
    margin: 0.5rem auto 0;
  }
  .mp-progress-mini strong { color: #4f46e5; }
  .mp-lock-banner {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #fcd34d;
    color: #78350f;
    border-radius: 0.75rem;
    padding: 0.75rem 1rem;
    margin: 1rem auto;
    font-size: 0.85rem;
    max-width: 30rem;
  }
  /* Make sure es-bi spans inside block elements don't kill the block layout */
  .mp-card .mp-emoji .es-bi,
  .mp-card .mp-eyebrow .es-bi,
  .mp-card .mp-title .es-bi,
  .mp-card .mp-desc .es-bi,
  .mp-card .mp-hint .es-bi { display: inline; }

  @media (max-width: 640px) {
    .mp-card { padding: 1.5rem 1rem; }
    .mp-emoji { font-size: 3rem; }
    .mp-title { font-size: 1.3rem; }
    .mp-desc { font-size: 0.95rem; }
  }
</style>

<?php
function stage_to_progress($stage) {
    if (in_array($stage, ['intro'], true)) return [0, 4];
    if (preg_match('/g(\d)/', $stage, $m)) {
        $n = (int)$m[1];
        $done = (strpos($stage, '_done') !== false) ? $n : $n - 1;
        return [$done, 4];
    }
    if ($stage === 'all_done') return [4, 4];
    return [0, 4];
}
[$games_done, $games_total] = stage_to_progress($stage);
?>

<?php if ($games_done > 0 && $stage !== 'all_done'): ?>
  <p class="mp-progress-mini">
    <span class="es-bi" data-en="Games done:" data-hi="खेल पूरे:">Games done:</span>
    <strong><?= $games_done ?> / <?= $games_total ?></strong>
  </p>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php if ($stage === 'intro'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🧠</div>
    <h2 class="mp-title">
      <span class="es-bi" data-en="Hi <?= e($child['name']) ?>! 👋"
                          data-hi="नमस्ते <?= e($child['name']) ?>! 👋">Hi <?= e($child['name']) ?>! 👋</span>
    </h2>
    <p class="mp-desc es-bi"
       data-en="We&rsquo;re going to play <strong>4 short brain games</strong> together — about <strong>5–7 minutes</strong> in total."
       data-hi="हम साथ में <strong>4 छोटे ब्रेन गेम</strong> खेलेंगे — कुल <strong>5–7 मिनट</strong>।">
      We&rsquo;re going to play <strong>4 short brain games</strong> together.
    </p>
    <p class="mp-hint es-bi"
       data-en="Try your best — each game gives you only one chance per day."
       data-hi="पूरी कोशिश करें — हर खेल का दिन में एक ही मौका मिलता है।">
      Try your best — each game gives you only one chance per day.
    </p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g1_intro') ?>" class="brand-grad es-bi"
         data-en="▶ Let&rsquo;s begin" data-hi="▶ चलिए शुरू करें">▶ Let&rsquo;s begin</a>
    </div>
  </div>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g1_intro'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🔢</div>
    <p class="mp-eyebrow text-indigo-600 es-bi" data-en="Game 1 of 4" data-hi="खेल 1 / 4">Game 1 of 4</p>
    <h2 class="mp-title es-bi" data-en="Number Memory" data-hi="संख्या स्मृति">Number Memory</h2>
    <p class="mp-desc es-bi"
       data-en="Numbers will flash on screen one at a time. When they finish, type them back in the same order."
       data-hi="संख्याएँ एक-एक करके स्क्रीन पर दिखेंगी। जब खत्म हों, उन्हें उसी क्रम में टाइप करें।">
      Numbers will flash on screen one at a time. Type them back in the same order.
    </p>
    <p class="mp-hint es-bi"
       data-en="Each correct round adds one more digit. ⚠️ One wrong answer ends the game."
       data-hi="हर सही दौर में एक अंक और बढ़ता है। ⚠️ एक भी गलत उत्तर पर खेल समाप्त।">
      Each correct round adds one more digit. ⚠️ One wrong answer ends the game.
    </p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g1') ?>" class="brand-grad es-bi"
         data-en="▶ Start Game 1" data-hi="▶ खेल 1 शुरू करें">▶ Start Game 1</a>
    </div>
  </div>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g1'): ?>

  <div class="mp-card">
    <div class="mp-block" id="g1Ready">
      <div class="mp-emoji">🔢</div>
      <p class="mp-eyebrow text-indigo-600">
        <span class="es-bi" data-en="Round" data-hi="दौर">Round</span> <span id="g1RoundNum">1</span>
      </p>
      <h2 class="mp-title es-bi" data-en="Ready?" data-hi="तैयार?">Ready?</h2>
      <p class="mp-desc">
        <span class="es-bi" data-en="Watch" data-hi="देखें">Watch</span>
        <strong><span id="g1Span">3</span></strong>
        <span class="es-bi" data-en="numbers, then type them in order." data-hi="संख्याएँ, फिर उन्हें क्रम में टाइप करें।">numbers, then type them in order.</span>
      </p>
      <p class="mp-hint" id="g1Status"></p>
      <div class="mp-cta">
        <button id="g1StartRound" type="button" class="brand-grad es-bi"
                data-en="▶ Start round" data-hi="▶ दौर शुरू करें">▶ Start round</button>
      </div>
    </div>

    <div class="mp-block hidden" id="g1Show">
      <p class="mp-eyebrow text-indigo-600 es-bi" data-en="Watch carefully…" data-hi="ध्यान से देखें…">Watch carefully…</p>
      <div class="border-2 border-dashed border-slate-200 rounded-xl p-10 my-6 flex items-center justify-center min-h-[180px]">
        <div id="g1Display" class="text-7xl font-bold text-indigo-600 tracking-wide"></div>
      </div>
    </div>

    <div class="mp-block hidden" id="g1Input">
      <p class="mp-eyebrow text-indigo-600 es-bi" data-en="Now your turn!" data-hi="अब आपकी बारी!">Now your turn!</p>
      <h2 class="mp-title es-bi" data-en="Type the numbers" data-hi="संख्याएँ टाइप करें">Type the numbers</h2>
      <input id="g1Answer" type="text" inputmode="numeric" autocomplete="off"
             class="block mt-3 w-full max-w-sm mx-auto border-2 border-slate-200 rounded-lg p-3 text-3xl tracking-widest text-center font-mono focus:border-indigo-500">
      <div class="mp-cta">
        <button id="g1Submit" type="button" class="brand-grad es-bi"
                data-en="Submit" data-hi="जमा करें">Submit</button>
      </div>
    </div>

    <div class="mp-block hidden" id="g1RoundResult">
      <div class="mp-emoji" id="g1RoundEmoji">✓</div>
      <h2 class="mp-title" id="g1RoundMsg"></h2>
      <p class="mp-desc" id="g1RoundDetail"></p>
      <div class="mp-cta">
        <button id="g1NextRound" type="button" class="brand-grad es-bi"
                data-en="▶ Next round" data-hi="▶ अगला दौर">▶ Next round</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const $ = id => document.getElementById(id);
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const startSpan = <?= json_encode($age < 5 ? 2 : 3) ?>;
    const cap = 9;
    let state = JSON.parse(sessionStorage.getItem('mp_g1') || 'null') || {
      span: startSpan, bestSpan: 0, round: 1, rounds: [], totalRounds: 0, correctRounds: 0
    };
    function show(which) {
      ['g1Ready','g1Show','g1Input','g1RoundResult'].forEach(id => $(id).classList.add('hidden'));
      $(which).classList.remove('hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function refreshReady() {
      $('g1RoundNum').textContent = state.round;
      $('g1Span').textContent = state.span;
      $('g1Status').textContent = state.bestSpan > 0 ? 'Best so far: ' + state.bestSpan + ' digits' : '';
    }
    refreshReady();
    $('g1StartRound').onclick = async () => {
      show('g1Show');
      const seq = [];
      for (let i = 0; i < state.span; i++) seq.push(Math.floor(Math.random() * 10));
      $('g1Display').textContent = '';
      await sleep(400);
      for (const d of seq) {
        $('g1Display').textContent = d;
        await sleep(700);
        $('g1Display').textContent = '';
        await sleep(250);
      }
      $('g1Display').textContent = '? ? ?';
      await sleep(400);
      show('g1Input');
      $('g1Answer').value = '';
      $('g1Answer').focus();
      window._g1Sequence = seq;
    };
    function onSubmit() {
      const seq = window._g1Sequence;
      const got = $('g1Answer').value.trim();
      const correct = (got === seq.join(''));
      state.rounds.push({ shown: seq.join(''), got, span: state.span, correct });
      state.totalRounds++;
      if (correct) {
        state.correctRounds++;
        state.bestSpan = Math.max(state.bestSpan, state.span);
        $('g1RoundEmoji').textContent = '✓';
        $('g1RoundMsg').textContent = 'Correct!';
        $('g1RoundDetail').textContent = 'You remembered all ' + state.span + ' digits.';
      } else {
        $('g1RoundEmoji').textContent = '🔒';
        $('g1RoundMsg').textContent = 'Game over for today';
        $('g1RoundDetail').textContent = 'The numbers were: ' + seq.join(' ') + '. Try again tomorrow!';
      }
      show('g1RoundResult');
      const ended = !correct || (state.round >= 12) || (state.span >= cap);
      if (correct) { if (state.span < cap) state.span += 1; }
      state.round += 1;
      if (ended) {
        $('g1NextRound').textContent = '▶ Finish Game 1';
        $('g1NextRound').dataset.en = '▶ Finish Game 1';
        $('g1NextRound').dataset.hi = '▶ खेल 1 समाप्त';
      }
      sessionStorage.setItem('mp_g1', JSON.stringify(state));
      $('g1NextRound').onclick = () => {
        if (ended) {
          const result = JSON.parse(sessionStorage.getItem('mp_result') || '{}');
          result.digit_span = { span: state.bestSpan, rounds: state.rounds, accuracy: Math.round((state.correctRounds / state.totalRounds) * 100), ended_on_mistake: !correct };
          sessionStorage.setItem('mp_result', JSON.stringify(result));
          sessionStorage.removeItem('mp_g1');
          window.location.href = '<?= url_for_stage($cid, 'g1_done') ?>';
        } else {
          refreshReady();
          show('g1Ready');
        }
      };
    }
    $('g1Submit').onclick = onSubmit;
    $('g1Answer').addEventListener('keydown', e => { if (e.key === 'Enter') onSubmit(); });
  })();
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g1_done'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🎉</div>
    <p class="mp-eyebrow text-emerald-600 es-bi" data-en="1 of 4 done" data-hi="1 / 4 पूरा">1 of 4 done</p>
    <h2 class="mp-title es-bi" data-en="Number Memory complete!" data-hi="संख्या स्मृति पूरी!">Number Memory complete!</h2>
    <div class="grid grid-cols-2 gap-3 max-w-md mx-auto mt-5 mb-4">
      <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
        <p class="mp-eyebrow text-indigo-600 es-bi" data-en="Best span" data-hi="सर्वश्रेष्ठ">Best span</p>
        <p class="text-3xl font-bold text-indigo-900 mt-1" id="r1Span">—</p>
        <p class="text-xs text-slate-500 mt-1 es-bi" data-en="digits remembered" data-hi="अंक याद">digits remembered</p>
      </div>
      <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4">
        <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Accuracy" data-hi="सटीकता">Accuracy</p>
        <p class="text-3xl font-bold text-emerald-900 mt-1" id="r1Acc">—%</p>
        <p class="text-xs text-slate-500 mt-1 es-bi" data-en="rounds correct" data-hi="दौर सही">rounds correct</p>
      </div>
    </div>
    <p class="mp-desc" id="r1Note"></p>
    <div class="mp-lock-banner es-bi hidden" id="r1Lock"
         data-en="🔒 This game is locked for today. Come back tomorrow!"
         data-hi="🔒 यह खेल आज के लिए बंद है। कल वापस आएँ!">
      🔒 This game is locked for today. Come back tomorrow!
    </div>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g2_intro') ?>" class="brand-grad es-bi"
         data-en="▶ Next game" data-hi="▶ अगला खेल">▶ Next game</a>
    </div>
  </div>
  <script>
    const r1 = (JSON.parse(sessionStorage.getItem('mp_result') || '{}')).digit_span || {};
    document.getElementById('r1Span').textContent = r1.span ?? '—';
    document.getElementById('r1Acc').textContent  = (r1.accuracy ?? '—') + '%';
    document.getElementById('r1Note').textContent =
      (r1.span >= 6) ? 'Excellent memory! 🌟'
      : (r1.span >= 4) ? 'Good work — well done!'
      : 'Nice try — keep practising!';
    if (r1.ended_on_mistake) document.getElementById('r1Lock').classList.remove('hidden');
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g2_intro'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🎯</div>
    <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Game 2 of 4" data-hi="खेल 2 / 4">Game 2 of 4</p>
    <h2 class="mp-title es-bi" data-en="Picture Memory" data-hi="चित्र स्मृति">Picture Memory</h2>
    <p class="mp-desc es-bi"
       data-en="A grid of 9 squares will light up in a sequence. Click the same squares in the same order."
       data-hi="9 चौकोरों का जाल एक क्रम में जलेगा। उन्हीं चौकोरों को उसी क्रम में दबाएँ।">
      A grid of 9 squares will light up. Click the same squares in the same order.
    </p>
    <p class="mp-hint es-bi"
       data-en="⚠️ One wrong answer ends the game."
       data-hi="⚠️ एक भी गलत उत्तर पर खेल समाप्त।">⚠️ One wrong answer ends the game.</p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g2') ?>" class="brand-grad es-bi"
         data-en="▶ Start Game 2" data-hi="▶ खेल 2 शुरू करें">▶ Start Game 2</a>
    </div>
  </div>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g2'): ?>

  <div class="mp-card">
    <div class="mp-block" id="g2Ready">
      <div class="mp-emoji">🎯</div>
      <p class="mp-eyebrow text-emerald-600">
        <span class="es-bi" data-en="Round" data-hi="दौर">Round</span> <span id="g2RoundNum">1</span>
      </p>
      <h2 class="mp-title es-bi" data-en="Ready?" data-hi="तैयार?">Ready?</h2>
      <p class="mp-desc">
        <span class="es-bi" data-en="Watch" data-hi="देखें">Watch</span>
        <strong><span id="g2Span">3</span></strong>
        <span class="es-bi" data-en="squares light up, then click them in order." data-hi="चौकोर जलेंगे, फिर उन्हें क्रम में दबाएँ।">squares light up, then click them in order.</span>
      </p>
      <p class="mp-hint" id="g2Status"></p>
      <div class="mp-cta">
        <button id="g2StartRound" type="button" class="brand-grad es-bi"
                data-en="▶ Start round" data-hi="▶ दौर शुरू करें">▶ Start round</button>
      </div>
    </div>
    <div class="mp-block hidden" id="g2Show">
      <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Watch the squares…" data-hi="चौकोरों को देखें…">Watch the squares…</p>
      <div id="g2Grid" class="grid grid-cols-3 gap-3 max-w-[280px] mx-auto my-6 select-none"></div>
    </div>
    <div class="mp-block hidden" id="g2Input">
      <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Click in order!" data-hi="क्रम में दबाएँ!">Click in order!</p>
      <h2 class="mp-title es-bi" data-en="Tap the squares" data-hi="चौकोरों को दबाएँ">Tap the squares</h2>
      <div id="g2InputGrid" class="grid grid-cols-3 gap-3 max-w-[280px] mx-auto my-6 select-none"></div>
    </div>
    <div class="mp-block hidden" id="g2RoundResult">
      <div class="mp-emoji" id="g2RoundEmoji">✓</div>
      <h2 class="mp-title" id="g2RoundMsg"></h2>
      <p class="mp-desc" id="g2RoundDetail"></p>
      <div class="mp-cta">
        <button id="g2NextRound" type="button" class="brand-grad es-bi"
                data-en="▶ Next round" data-hi="▶ अगला दौर">▶ Next round</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const $ = id => document.getElementById(id);
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const startSpan = <?= json_encode($age < 5 ? 2 : 3) ?>;
    const cap = 8;
    let state = JSON.parse(sessionStorage.getItem('mp_g2') || 'null') || {
      span: startSpan, bestSpan: 0, round: 1, rounds: [], totalRounds: 0, correctRounds: 0
    };
    function show(which) {
      ['g2Ready','g2Show','g2Input','g2RoundResult'].forEach(id => $(id).classList.add('hidden'));
      $(which).classList.remove('hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function refreshReady() {
      $('g2RoundNum').textContent = state.round;
      $('g2Span').textContent = state.span;
      $('g2Status').textContent = state.bestSpan > 0 ? 'Best so far: ' + state.bestSpan + ' squares' : '';
    }
    refreshReady();
    function buildGrid(containerId, clickable) {
      const grid = $(containerId);
      grid.innerHTML = '';
      const cells = [];
      for (let i = 0; i < 9; i++) {
        const cell = document.createElement('div');
        cell.dataset.idx = i;
        cell.className = 'aspect-square rounded-xl bg-slate-100 border-2 border-slate-200 transition-all ' + (clickable ? 'cursor-pointer' : '');
        grid.appendChild(cell);
        cells.push(cell);
      }
      return cells;
    }
    $('g2StartRound').onclick = async () => {
      show('g2Show');
      const cells = buildGrid('g2Grid', false);
      const indices = [...Array(9).keys()].sort(() => Math.random() - 0.5).slice(0, state.span);
      await sleep(400);
      for (const i of indices) {
        cells[i].classList.add('bg-emerald-500');
        await sleep(650);
        cells[i].classList.remove('bg-emerald-500');
        await sleep(250);
      }
      await sleep(400);
      window._g2Sequence = indices;
      show('g2Input');
      const inputCells = buildGrid('g2InputGrid', true);
      const taps = [];
      inputCells.forEach(c => {
        c.onclick = () => {
          const idx = parseInt(c.dataset.idx);
          if (taps.includes(idx)) return;
          c.classList.add('bg-amber-300','ring-4','ring-amber-200');
          taps.push(idx);
          if (taps.length === state.span) {
            inputCells.forEach(cc => cc.onclick = null);
            checkAnswer(taps, indices);
          }
        };
      });
    };
    function checkAnswer(got, expected) {
      const correct = JSON.stringify(got) === JSON.stringify(expected);
      state.rounds.push({ shown: expected, got, span: state.span, correct });
      state.totalRounds++;
      if (correct) {
        state.correctRounds++;
        state.bestSpan = Math.max(state.bestSpan, state.span);
        $('g2RoundEmoji').textContent = '✓';
        $('g2RoundMsg').textContent = 'Correct!';
        $('g2RoundDetail').textContent = 'You remembered all ' + state.span + ' squares.';
      } else {
        $('g2RoundEmoji').textContent = '🔒';
        $('g2RoundMsg').textContent = 'Game over for today';
        $('g2RoundDetail').textContent = 'You got ' + got.filter((v, i) => v === expected[i]).length + ' of ' + state.span + ' right. Try again tomorrow!';
      }
      show('g2RoundResult');
      const ended = !correct || (state.round >= 10) || (state.span >= cap);
      if (correct) { if (state.span < cap) state.span += 1; }
      state.round += 1;
      if (ended) {
        $('g2NextRound').textContent = '▶ Finish Game 2';
        $('g2NextRound').dataset.en = '▶ Finish Game 2';
        $('g2NextRound').dataset.hi = '▶ खेल 2 समाप्त';
      }
      sessionStorage.setItem('mp_g2', JSON.stringify(state));
      $('g2NextRound').onclick = () => {
        if (ended) {
          const result = JSON.parse(sessionStorage.getItem('mp_result') || '{}');
          result.visual_span = { span: state.bestSpan, rounds: state.rounds, accuracy: Math.round((state.correctRounds / state.totalRounds) * 100), ended_on_mistake: !correct };
          sessionStorage.setItem('mp_result', JSON.stringify(result));
          sessionStorage.removeItem('mp_g2');
          window.location.href = '<?= url_for_stage($cid, 'g2_done') ?>';
        } else {
          refreshReady();
          show('g2Ready');
        }
      };
    }
  })();
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g2_done'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🌟</div>
    <p class="mp-eyebrow text-emerald-600 es-bi" data-en="2 of 4 done" data-hi="2 / 4 पूरा">2 of 4 done</p>
    <h2 class="mp-title es-bi" data-en="Picture Memory complete!" data-hi="चित्र स्मृति पूरी!">Picture Memory complete!</h2>
    <div class="grid grid-cols-2 gap-3 max-w-md mx-auto mt-5 mb-4">
      <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4">
        <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Best span" data-hi="सर्वश्रेष्ठ">Best span</p>
        <p class="text-3xl font-bold text-emerald-900 mt-1" id="r2Span">—</p>
        <p class="text-xs text-slate-500 mt-1 es-bi" data-en="squares" data-hi="चौकोर">squares</p>
      </div>
      <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-4">
        <p class="mp-eyebrow text-cyan-600 es-bi" data-en="Accuracy" data-hi="सटीकता">Accuracy</p>
        <p class="text-3xl font-bold text-cyan-900 mt-1" id="r2Acc">—%</p>
        <p class="text-xs text-slate-500 mt-1 es-bi" data-en="rounds correct" data-hi="दौर सही">rounds correct</p>
      </div>
    </div>
    <p class="mp-desc" id="r2Note"></p>
    <div class="mp-lock-banner es-bi hidden" id="r2Lock"
         data-en="🔒 This game is locked for today. Come back tomorrow!"
         data-hi="🔒 यह खेल आज के लिए बंद है। कल वापस आएँ!">
      🔒 This game is locked for today. Come back tomorrow!
    </div>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g3_intro') ?>" class="brand-grad es-bi"
         data-en="▶ Next game" data-hi="▶ अगला खेल">▶ Next game</a>
    </div>
  </div>
  <script>
    const r2 = (JSON.parse(sessionStorage.getItem('mp_result') || '{}')).visual_span || {};
    document.getElementById('r2Span').textContent = r2.span ?? '—';
    document.getElementById('r2Acc').textContent  = (r2.accuracy ?? '—') + '%';
    document.getElementById('r2Note').textContent =
      (r2.span >= 5) ? 'Sharp visual memory! 🎯'
      : (r2.span >= 3) ? 'Solid work!'
      : 'Practice helps — keep playing.';
    if (r2.ended_on_mistake) document.getElementById('r2Lock').classList.remove('hidden');
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g3_intro'): ?>

  <div class="mp-card">
    <div class="mp-emoji">👀</div>
    <p class="mp-eyebrow text-amber-600 es-bi" data-en="Game 3 of 4" data-hi="खेल 3 / 4">Game 3 of 4</p>
    <h2 class="mp-title es-bi" data-en="Quick Eye" data-hi="तेज़ नज़र">Quick Eye</h2>
    <p class="mp-desc es-bi"
       data-en="A colour word will appear (like RED). Tap the colour the word is <strong>printed in</strong> — NOT what it spells."
       data-hi="एक रंग का शब्द दिखेगा (जैसे RED)। शब्द जिस <strong>रंग में लिखा हो</strong> उसे दबाएँ — शब्द को मत पढ़ें।">
      A colour word will appear. Tap the colour the word is <strong>printed in</strong> — NOT what it spells.
    </p>
    <p class="mp-hint es-bi"
       data-en="One round, 30 seconds. No second chances today."
       data-hi="एक दौर, 30 सेकंड। आज दूसरा मौका नहीं।">One round, 30 seconds. No second chances today.</p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g3') ?>" class="brand-grad es-bi"
         data-en="▶ Start Game 3" data-hi="▶ खेल 3 शुरू करें">▶ Start Game 3</a>
    </div>
  </div>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g3'): ?>

  <div class="mp-card">
    <div class="mp-block" id="g3Ready">
      <div class="mp-emoji">👀</div>
      <h2 class="mp-title es-bi" data-en="Ready?" data-hi="तैयार?">Ready?</h2>
      <p class="mp-desc es-bi"
         data-en="30 seconds. Tap the colour the word is <strong>printed in</strong>."
         data-hi="30 सेकंड। शब्द जिस <strong>रंग में लिखा हो</strong> उसे दबाएँ।">
         30 seconds. Tap the colour the word is <strong>printed in</strong>.
      </p>
      <div class="mp-cta">
        <button id="g3StartRound" type="button" class="brand-grad es-bi"
                data-en="▶ Start round" data-hi="▶ दौर शुरू करें">▶ Start round</button>
      </div>
    </div>
    <div class="mp-block hidden" id="g3Play">
      <div class="border-2 border-dashed border-slate-200 rounded-xl py-10 my-3 min-h-[160px] flex items-center justify-center">
        <div id="g3Word" class="text-6xl font-bold tracking-wide">—</div>
      </div>
      <div id="g3Buttons" class="grid grid-cols-2 sm:grid-cols-4 gap-2 max-w-md mx-auto"></div>
      <div class="text-sm text-slate-500 mt-4">⏱ <span id="g3Time">30</span>s · ✓ <span id="g3Correct">0</span> · ✗ <span id="g3Wrong">0</span></div>
    </div>
  </div>

  <script>
  (function () {
    const $ = id => document.getElementById(id);
    function show(which) {
      ['g3Ready','g3Play'].forEach(id => $(id).classList.add('hidden'));
      $(which).classList.remove('hidden');
    }
    const colours = [
      { name: 'RED', css: '#ef4444' }, { name: 'BLUE', css: '#3b82f6' },
      { name: 'GREEN', css: '#10b981' }, { name: 'YELLOW', css: '#f59e0b' }
    ];
    const DURATION = 30;
    $('g3StartRound').onclick = () => {
      show('g3Play');
      const btnBox = $('g3Buttons');
      btnBox.innerHTML = '';
      const buttons = colours.map(c => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'py-3 rounded-lg font-bold text-white text-lg';
        b.style.background = c.css;
        b.textContent = c.name;
        b.dataset.css = c.css;
        btnBox.appendChild(b);
        return b;
      });
      let trialEndsAt = Date.now() + DURATION * 1000;
      let correct = 0, wrong = 0, total = 0;
      let currentInkCss = null;
      function newTrial() {
        const word = colours[Math.floor(Math.random() * 4)];
        const ink  = colours[Math.floor(Math.random() * 4)];
        currentInkCss = ink.css;
        $('g3Word').textContent = word.name;
        $('g3Word').style.color = ink.css;
      }
      buttons.forEach(b => {
        b.onclick = () => {
          if (Date.now() > trialEndsAt) return;
          total++;
          if (b.dataset.css === currentInkCss) { correct++; b.style.outline = '3px solid #fff'; setTimeout(() => b.style.outline = '', 150); }
          else { wrong++; }
          $('g3Correct').textContent = correct;
          $('g3Wrong').textContent = wrong;
          newTrial();
        };
      });
      newTrial();
      const tick = setInterval(() => {
        const remaining = Math.max(0, Math.ceil((trialEndsAt - Date.now()) / 1000));
        $('g3Time').textContent = remaining;
        if (remaining <= 0) {
          clearInterval(tick);
          const acc = total === 0 ? 0 : Math.round((correct / total) * 100);
          const result = JSON.parse(sessionStorage.getItem('mp_result') || '{}');
          result.attention = { correct, wrong, total, accuracy: acc, speed: (total / DURATION).toFixed(1) };
          sessionStorage.setItem('mp_result', JSON.stringify(result));
          window.location.href = '<?= url_for_stage($cid, 'g3_done') ?>';
        }
      }, 200);
    };
  })();
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g3_done'): ?>

  <div class="mp-card">
    <div class="mp-emoji">⚡</div>
    <p class="mp-eyebrow text-amber-600 es-bi" data-en="3 of 4 done" data-hi="3 / 4 पूरा">3 of 4 done</p>
    <h2 class="mp-title es-bi" data-en="Quick Eye complete!" data-hi="तेज़ नज़र पूरी!">Quick Eye complete!</h2>
    <div class="grid grid-cols-3 gap-2 max-w-lg mx-auto mt-5 mb-4">
      <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
        <p class="mp-eyebrow text-amber-600 es-bi" data-en="Speed" data-hi="गति">Speed</p>
        <p class="text-2xl font-bold text-amber-900 mt-1" id="r3Speed">—</p>
        <p class="text-[11px] text-slate-500 es-bi" data-en="taps / sec" data-hi="दबाव / से.">taps / sec</p>
      </div>
      <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
        <p class="mp-eyebrow text-emerald-600 es-bi" data-en="Accuracy" data-hi="सटीकता">Accuracy</p>
        <p class="text-2xl font-bold text-emerald-900 mt-1" id="r3Acc">—%</p>
      </div>
      <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3">
        <p class="mp-eyebrow text-indigo-600 es-bi" data-en="Total" data-hi="कुल">Total</p>
        <p class="text-2xl font-bold text-indigo-900 mt-1" id="r3Total">—</p>
        <p class="text-[11px] text-slate-500 es-bi" data-en="taps" data-hi="दबाव">taps</p>
      </div>
    </div>
    <p class="mp-desc" id="r3Note"></p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g4_intro') ?>" class="brand-grad es-bi"
         data-en="▶ Next game" data-hi="▶ अगला खेल">▶ Next game</a>
    </div>
  </div>
  <script>
    const r3 = (JSON.parse(sessionStorage.getItem('mp_result') || '{}')).attention || {};
    document.getElementById('r3Speed').textContent = r3.speed ?? '—';
    document.getElementById('r3Acc').textContent   = (r3.accuracy ?? '—') + '%';
    document.getElementById('r3Total').textContent = r3.total ?? '—';
    document.getElementById('r3Note').textContent =
      (r3.accuracy >= 80) ? 'Lightning fast and accurate! ⚡'
      : (r3.accuracy >= 60) ? 'Good focus!'
      : 'Tricky game — your brain is learning.';
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g4_intro'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🧩</div>
    <p class="mp-eyebrow text-rose-600 es-bi" data-en="Game 4 of 4" data-hi="खेल 4 / 4">Game 4 of 4</p>
    <h2 class="mp-title es-bi" data-en="Pattern Puzzle" data-hi="पैटर्न पहेली">Pattern Puzzle</h2>
    <p class="mp-desc es-bi"
       data-en="You&rsquo;ll see a pattern — numbers, letters, or shapes. Pick the answer that completes it. 8 questions."
       data-hi="आप एक पैटर्न देखेंगे — संख्याएँ, अक्षर, या आकार। जो उत्तर इसे पूरा करे, उसे चुनें। कुल 8 प्रश्न।">
      You&rsquo;ll see a pattern. Pick the answer that completes it. 8 questions.
    </p>
    <p class="mp-hint es-bi"
       data-en="No timer — take your time."
       data-hi="कोई समय सीमा नहीं।">No timer — take your time.</p>
    <div class="mp-cta">
      <a href="<?= url_for_stage($cid, 'g4') ?>" class="brand-grad es-bi"
         data-en="▶ Start Game 4" data-hi="▶ खेल 4 शुरू करें">▶ Start Game 4</a>
    </div>
  </div>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g4'): ?>

  <div class="mp-card">
    <p class="mp-eyebrow text-rose-600">
      <span class="es-bi" data-en="Question" data-hi="प्रश्न">Question</span>
      <span id="g4QNum">1</span> / 8
    </p>
    <h2 class="mp-title es-bi" data-en="Which one comes next?" data-hi="अगला कौन सा है?">Which one comes next?</h2>
    <div id="g4Question" class="text-3xl font-mono bg-slate-50 border border-slate-200 rounded-xl py-6 my-4"></div>
    <div id="g4Options" class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-md mx-auto"></div>
    <p class="mp-progress-mini mt-4">✓ <span id="g4Correct">0</span> correct so far</p>
  </div>

  <script>
  (function () {
    const $ = id => document.getElementById(id);
    function gen() {
      const items = [];
      items.push((() => { const s = Math.floor(Math.random() * 5) + 1, st = Math.floor(Math.random() * 3) + 2; const a = s + 3 * st; return { prompt: [s, s+st, s+2*st].join(', ') + ', ?', options: shuffle(a, [a+1, a-st, a+st+1]) }; })());
      items.push((() => { const s = Math.floor(Math.random() * 4) + 2; const a = s * 8; return { prompt: [s, s*2, s*4].join(', ') + ', ?', options: shuffle(a, [a+2, a/2, a-4]) }; })());
      items.push((() => { const A = 65, s = Math.floor(Math.random() * 10), st = 2; const a = String.fromCharCode(A + s + 3*st); return { prompt: [s, s+st, s+2*st].map(n => String.fromCharCode(A+n)).join(', ') + ', ?', options: shuffle(a, [String.fromCharCode(A+s+3*st+1), String.fromCharCode(A+s+2*st), String.fromCharCode(A+s+4*st)]) }; })());
      items.push({ prompt: '🔴 🔵 🔴 🔵 🔴 ?', options: shuffle('🔵', ['🔴','🟡','🟢']) });
      const sets = [{ items: ['🍎','🍌','🍇','🚗'], odd: 3 }, { items: ['🐶','🐱','🐰','🌳'], odd: 3 }, { items: ['⚽','🏀','🎾','📚'], odd: 3 }];
      const pick = sets[Math.floor(Math.random() * sets.length)];
      items.push({ prompt: 'Which does NOT belong?', options: pick.items.map((x, i) => ({ value: x, correct: i === pick.odd })) });
      items.push((() => { const s = Math.floor(Math.random() * 5) + 15, st = Math.floor(Math.random() * 2) + 2; const a = s - 3*st; return { prompt: [s, s-st, s-2*st].join(', ') + ', ?', options: shuffle(a, [a-1, a+st, a+1]) }; })());
      items.push({ prompt: '1, 4, 9, 16, ?', options: shuffle(25, [20, 22, 24]) });
      items.push({ prompt: 'Cat → Kitten · Dog → ?', options: shuffle('Puppy', ['Cub','Calf','Foal']) });
      return items;
    }
    function shuffle(correct, distractors) {
      const arr = [correct, ...distractors].map(String).map(v => ({ value: v, correct: v === String(correct) }));
      arr.sort(() => Math.random() - 0.5);
      return arr;
    }
    const items = gen();
    let i = 0, correct = 0;
    function showItem() {
      if (i >= items.length) {
        const result = JSON.parse(sessionStorage.getItem('mp_result') || '{}');
        result.reasoning = { correct, total: items.length, accuracy: Math.round((correct / items.length) * 100) };
        sessionStorage.setItem('mp_result', JSON.stringify(result));
        window.location.href = '<?= url_for_stage($cid, 'g4_done') ?>';
        return;
      }
      const it = items[i];
      $('g4QNum').textContent = i + 1;
      $('g4Correct').textContent = correct;
      $('g4Question').textContent = it.prompt;
      const box = $('g4Options');
      box.innerHTML = '';
      it.options.forEach((opt, idx) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'py-4 px-2 rounded-xl bg-slate-50 border-2 border-slate-200 hover:border-indigo-400 hover:bg-indigo-50 text-lg font-semibold transition';
        b.textContent = opt.value;
        b.onclick = () => {
          if (opt.correct) { correct++; b.classList.add('bg-emerald-100','border-emerald-400'); }
          else {
            b.classList.add('bg-rose-100','border-rose-400');
            it.options.forEach((o, j) => { if (o.correct) box.children[j].classList.add('bg-emerald-100','border-emerald-400'); });
          }
          [...box.children].forEach(c => c.disabled = true);
          setTimeout(() => { i++; showItem(); }, 700);
        };
        box.appendChild(b);
      });
    }
    showItem();
  })();
  </script>

<?php /* ═══════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($stage === 'g4_done' || $stage === 'all_done'): ?>

  <div class="mp-card">
    <div class="mp-emoji">🏆</div>
    <p class="mp-eyebrow text-rose-600 es-bi" data-en="All 4 games done!" data-hi="सभी 4 खेल पूरे!">All 4 games done!</p>
    <h2 class="mp-title es-bi" data-en="Excellent work!" data-hi="बहुत अच्छा!">Excellent work!</h2>
    <div class="grid grid-cols-2 gap-3 max-w-md mx-auto mt-5 mb-5">
      <div class="bg-rose-50 border border-rose-100 rounded-xl p-4">
        <p class="mp-eyebrow text-rose-600 es-bi" data-en="Pattern correct" data-hi="पैटर्न सही">Pattern correct</p>
        <p class="text-3xl font-bold text-rose-900 mt-1"><span id="r4Correct">0</span> / 8</p>
      </div>
      <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-4">
        <p class="mp-eyebrow text-cyan-600 es-bi" data-en="Pattern accuracy" data-hi="पैटर्न सटीकता">Pattern accuracy</p>
        <p class="text-3xl font-bold text-cyan-900 mt-1" id="r4Acc">—%</p>
      </div>
    </div>
    <p class="mp-desc es-bi"
       data-en="Tap below to save your results. The AI will write a summary your parent can read."
       data-hi="नीचे दबाकर परिणाम सहेजें। AI आपके माता-पिता के लिए सारांश लिखेगा।">
      Tap below to save your results. The AI will write a summary your parent can read.
    </p>
    <form method="post" id="finishForm" class="mp-cta">
      <input type="hidden" name="csrf"    value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="cid"     value="<?= $cid ?>">
      <input type="hidden" name="payload" id="finishPayload" value="">
      <button type="submit" class="brand-grad es-bi"
              data-en="▶ Finish &amp; save" data-hi="▶ समाप्त करें">▶ Finish &amp; save</button>
    </form>
  </div>

  <script>
    const finalRes = JSON.parse(sessionStorage.getItem('mp_result') || '{}');
    const r4 = finalRes.reasoning || {};
    document.getElementById('r4Correct').textContent = r4.correct ?? 0;
    document.getElementById('r4Acc').textContent     = (r4.accuracy ?? 0) + '%';
    document.getElementById('finishForm').addEventListener('submit', () => {
      const payload = {
        started_at: Date.now(),
        subtests: {
          digit_span:  finalRes.digit_span  || { span: 0 },
          visual_span: finalRes.visual_span || { span: 0 },
          attention:   finalRes.attention   || { accuracy: 0 },
          reasoning:   finalRes.reasoning   || { accuracy: 0 }
        },
        total_rt_ms: 0
      };
      document.getElementById('finishPayload').value = JSON.stringify(payload);
      sessionStorage.removeItem('mp_result');
      sessionStorage.removeItem('mp_g1');
      sessionStorage.removeItem('mp_g2');
    });
  </script>

<?php endif; ?>

<script>
(function () {
  function getLang() { try { return localStorage.getItem('es_lang') || 'en'; } catch(_) { return 'en'; } }
  function applyBi() {
    const lang = getLang();
    document.querySelectorAll('.es-bi').forEach(el => {
      const en = el.dataset.en, hi = el.dataset.hi;
      el.innerHTML = (lang === 'hi' && hi) ? hi : (en || el.innerHTML);
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
