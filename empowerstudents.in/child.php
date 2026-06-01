<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_round.php';
require_parent();
$child = child_for_parent((int)($_GET['id'] ?? 0));
if (!$child) { header('Location: /dashboard.php'); exit; }
$page_title = $child['name'];

$age   = calc_age_years($child['dob']);
$band  = age_band($age);

// Current evaluation round (1, 2, 3, ...). Bumped by /api_reset_evaluation.php.
$current_round = current_evaluation_round((int)$child['id']);

// Pull module status — only for the CURRENT round
$st = db()->prepare("SELECT module, status, score, ai_summary, completed_at
                     FROM assessments
                     WHERE child_id = ? AND COALESCE(evaluation_round, 1) = ?
                     ORDER BY id DESC");
$st->execute([$child['id'], $current_round]);
$rows = $st->fetchAll();
$by_mod = [];
foreach ($rows as $r) {
    if (!isset($by_mod[$r['module']])) $by_mod[$r['module']] = $r;
}

// Modules: filter age-appropriately. Under-2 sees a focused subset.
$all_modules = [
    'health'             => ['💗', 'Health screening',         'Growth, sleep, sensory, milestone red-flags',                          true],
    'pulse_check'        => ['❤️', 'Pulse &amp; breath',         'Camera PPG + Buteyko breath-hold',                                     $age >= 5],
    'behavior'           => ['🧩', 'Behaviour',                 'Age-appropriate. <2y: subtle ASD/learning markers (M-CHAT inspired)',  true],
    'mind_power'         => ['🧠', 'Mind power',                'Memory, attention, problem-solving',                                   true],
    'emotions'           => ['😊', 'Emotions',                  'How feelings are named, regulated and expressed',                      true],
    'general_awareness'  => ['🌍', 'General awareness',         '2-min adaptive quiz',                                                  $age >= 3],
    'special_talent'     => ['⭐', 'Special talent',            'Spot a gift to nurture',                                               $age >= 2],
    'speech'             => ['🎤', 'Speech &amp; voice',         'Read sentences. AI scores fluency, tone, stuttering',                  $age >= 4],
    'spontaneous'        => ['💬', 'Spontaneous expression',    'Open-ended question',                                                  $age >= 4],
    'math'               => ['🔢', 'Maths level',               'Adaptive base-level finder',                                           $age >= 4],
    'language'           => ['📚', 'Language &amp; reading',     'Word-power and timed comprehension',                                   $age >= 5],
    'parent_index'       => ['👪', 'Parent index',              'How we&rsquo;re nurturing the child',                                  true],
    'diet'               => ['🥗', 'Diet advice',               'Tuned to age, nature and morbidity',                                   true],
];

require_once __DIR__ . '/includes/wallet.php';
$_balance = (int)((current_parent() ?? [])['credits'] ?? 0);

require __DIR__ . '/includes/header.php';
?>

<a href="/dashboard.php" class="text-sm text-indigo-600 hover:underline" data-i18n="child.back">&larr; All children</a>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8 mt-4">
  <div class="flex flex-wrap items-center gap-4">
    <div class="w-16 h-16 brand-grad rounded-full flex items-center justify-center text-white font-bold text-2xl">
      <?= e(mb_strtoupper(mb_substr($child['name'], 0, 1))) ?>
    </div>
    <div class="flex-1 min-w-0">
      <h1 class="text-2xl font-extrabold truncate"><?= e($child['name']) ?></h1>
      <p class="text-sm text-slate-500">
        <?= $age !== null ? number_format($age, 1) : '—' ?> <span data-i18n="child.years">yrs</span>
        &middot; <?= e($child['gender'] ?: '—') ?>
        &middot; <span class="font-semibold"><?= e($band) ?></span>
        <?php if ($child['class_grade']): ?> &middot; <?= e($child['class_grade']) ?><?php endif; ?>
        <?php if ($current_round > 1): ?>
          &middot; <span class="bg-indigo-100 text-indigo-700 text-xs font-bold px-2 py-0.5 rounded-full">Evaluation #<?= (int)$current_round ?></span>
        <?php endif; ?>
      </p>
      <?php if ($child['diagnosis']): ?>
        <p class="text-xs text-rose-600 mt-1"><?= e($child['diagnosis']) ?></p>
      <?php endif; ?>
    </div>
    <a href="/report.php?id=<?= (int)$child['id'] ?>" class="brand-grad text-white font-semibold px-4 py-2 rounded-lg shadow hover:opacity-90" data-i18n="child.report">View AI report</a>
  </div>
</div>

<?php
// ── Care Pack upsell row (only after at least 1 assessment is done) ──
$done_count = 0;
foreach ($by_mod as $r) if ($r['status'] === 'done') $done_count++;

require_once __DIR__ . '/includes/paid_schema.php';
$parent = current_parent();
$_pack = care_pack_for((int)$parent['id'], (int)$child['id']);

if ($done_count > 0):
    if (!$_pack):
?>
<!-- ─── Not yet purchased: hero CTA ─── -->
<div class="bg-gradient-to-br from-rose-500 via-orange-500 to-amber-500 rounded-3xl p-6 sm:p-8 text-white mb-8 shadow-xl relative overflow-hidden">
  <div class="absolute -top-4 right-6 bg-amber-300 text-rose-900 px-4 py-1 rounded-full text-xs font-bold rotate-3">SAVE 148 cr</div>
  <div class="grid sm:grid-cols-3 gap-6 items-center">
    <div class="sm:col-span-2">
      <p class="text-xs uppercase tracking-wider opacity-90 font-semibold">🎁 Care Pack for <?= e($child['name']) ?></p>
      <h2 class="text-2xl sm:text-3xl font-bold mt-1 mb-2">Three personalised tools, ready in 60 seconds</h2>
      <p class="opacity-95 text-sm leading-relaxed">
        Based on <?= e($child['name']) ?>'s actual assessment results — AI generates a 4-week growth plan,
        a personal course of 5 lessons, and unlocks 30 days of daily tracking.
      </p>
    </div>
    <div class="text-center sm:text-right">
      <div class="text-4xl font-bold">499 cr</div>
      <div class="text-xs opacity-80 line-through">647 cr separately</div>
      <a href="/care_pack.php?id=<?= (int)$child['id'] ?>" class="inline-block mt-3 bg-white text-rose-600 hover:bg-rose-50 px-6 py-2.5 rounded-full font-bold text-sm transition shadow-lg">
        See what's inside →
      </a>
    </div>
  </div>
</div>
<?php else:
    // Already has Care Pack — show three feature cards as quick-access
    $tracker_days = (int)$_pack['tracker_days_remaining'];
?>
<!-- ─── Care Pack active: feature cards ─── -->
<div class="mb-8">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <h2 class="text-lg font-semibold flex items-center gap-2">
      🎁 <?= e($child['name']) ?>'s Care Pack
      <span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-2 py-0.5 rounded-full">ACTIVE</span>
    </h2>
  </div>
  <div class="grid sm:grid-cols-3 gap-4">
    <a href="/growth_plan.php?id=<?= (int)$child['id'] ?>" class="bg-gradient-to-br from-violet-500 to-indigo-600 rounded-2xl p-5 text-white hover:scale-[1.02] transition-transform shadow-lg block">
      <div class="text-3xl mb-2">🌱</div>
      <h3 class="font-bold mb-1">Growth Plan</h3>
      <p class="text-sm opacity-90">4-week personalised action plan</p>
    </a>
    <a href="/course.php?id=<?= (int)$child['id'] ?>" class="bg-gradient-to-br from-amber-500 to-orange-600 rounded-2xl p-5 text-white hover:scale-[1.02] transition-transform shadow-lg block">
      <div class="text-3xl mb-2">📚</div>
      <h3 class="font-bold mb-1">Personal Course</h3>
      <p class="text-sm opacity-90">5 AI-written lessons</p>
    </a>
    <a href="/tracker.php?id=<?= (int)$child['id'] ?>" class="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-5 text-white hover:scale-[1.02] transition-transform shadow-lg block">
      <div class="text-3xl mb-2">📊</div>
      <h3 class="font-bold mb-1">Daily Tracker</h3>
      <p class="text-sm opacity-90"><?= $tracker_days ?> days remaining</p>
    </a>
  </div>
</div>
<?php endif; endif; ?>

<h2 class="text-lg font-semibold mb-4" data-i18n="child.modules">Assessment modules</h2>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($all_modules as $key => $m):
    $available = $m[3];
    $row  = $by_mod[$key] ?? null;
    $done = $row && $row['status'] === 'done';
  ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm <?= $available ? '' : 'opacity-50' ?>">
      <div class="flex items-start gap-3 mb-2">
        <span class="text-2xl"><?= $m[0] ?></span>
        <div class="flex-1">
          <h3 class="font-semibold"><?= $m[1] ?></h3>
          <p class="text-xs text-slate-500"><?= $m[2] ?></p>
        </div>
        <?php $price = wallet_service_price($key); if ($price !== null): ?>
          <span class="text-xs bg-indigo-50 text-indigo-700 border border-indigo-100 rounded-full px-2 py-0.5 whitespace-nowrap">
            <?= (int)$price ?> <span data-i18n="nav.cr">cr</span>
          </span>
        <?php endif; ?>
      </div>
      <?php if ($done): ?>
        <p class="text-xs text-emerald-700 mb-2">✓ <span data-i18n="child.summary">Done</span> <?= $row['score'] !== null ? '· score ' . number_format((float)$row['score'], 1) : '' ?></p>
        <a href="/modules/<?= e($key) ?>.php?cid=<?= (int)$child['id'] ?>"
           class="block text-center bg-slate-100 text-slate-700 font-medium py-2 rounded-lg hover:bg-slate-200" data-i18n="child.redo">Re-do</a>
      <?php elseif ($available): ?>
        <a href="/modules/<?= e($key) ?>.php?cid=<?= (int)$child['id'] ?>"
           class="block text-center brand-grad text-white font-medium py-2 rounded-lg hover:opacity-90" data-i18n="child.start">Start</a>
      <?php else: ?>
        <p class="text-xs text-slate-400 italic">Not applicable at this age</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- ─── Start fresh evaluation ─── -->
<div class="mt-10 mb-6 bg-amber-50 border border-amber-200 rounded-2xl p-5 sm:p-6">
  <div class="flex items-start gap-4 flex-wrap sm:flex-nowrap">
    <div class="text-3xl">🔄</div>
    <div class="flex-1 min-w-0">
      <h3 class="font-bold text-amber-900 mb-1">Want to do a fresh evaluation for <?= e($child['name']) ?>?</h3>
      <p class="text-sm text-amber-800 leading-relaxed mb-3">
        After 3&ndash;6 months, kids change. Start an entirely new evaluation round &mdash;
        all 13 modules reset to "not done", expert report can be ordered fresh.
        Old results stay safely archived for comparison.
        <strong>You'll need to re-run modules and re-pay for any paid items (including a new expert report).</strong>
      </p>
      <button type="button" id="btnResetEval"
              class="bg-amber-600 text-white font-semibold px-4 py-2 rounded-lg hover:bg-amber-700 text-sm">
        🔄 Start fresh evaluation (Round #<?= (int)$current_round + 1 ?>)
      </button>
      <p id="resetMsg" class="text-xs mt-2 hidden"></p>
    </div>
  </div>
</div>

<script>
(function () {
  const btn = document.getElementById('btnResetEval');
  if (!btn) return;
  const msg = document.getElementById('resetMsg');
  const childName = <?= json_encode($child['name']) ?>;
  const childId   = <?= (int)$child['id'] ?>;
  const csrf      = <?= json_encode(csrf_token()) ?>;

  btn.addEventListener('click', async () => {
    // Step 1: high-friction confirmation
    const ok1 = confirm(
      `Start a fresh evaluation for ${childName}?\n\n` +
      `• All 13 modules reset to "not done"\n` +
      `• Old data stays archived (you can compare later)\n` +
      `• You'll need to re-run modules and re-pay for an expert report\n\n` +
      `Continue?`
    );
    if (!ok1) return;

    // Step 2: type-to-confirm
    const typed = prompt(`To confirm, type RESET (in capitals) below:`);
    if (typed !== 'RESET') {
      msg.className = 'text-xs mt-2 text-rose-700';
      msg.textContent = '✗ Cancelled. You did not type RESET.';
      msg.classList.remove('hidden');
      return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Resetting…';
    try {
      const res = await fetch('/api_reset_evaluation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ child_id: childId, confirm: 'RESET', csrf })
      });
      const j = await res.json();
      if (j.ok) {
        msg.className = 'text-xs mt-2 text-emerald-700 font-semibold';
        msg.textContent = '✓ Round ' + j.new_round + ' started. Reloading…';
        msg.classList.remove('hidden');
        setTimeout(() => location.reload(), 1200);
      } else {
        msg.className = 'text-xs mt-2 text-rose-700';
        msg.textContent = '✗ ' + (j.error || 'Reset failed.');
        msg.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = '🔄 Start fresh evaluation (Round #<?= (int)$current_round + 1 ?>)';
      }
    } catch (err) {
      msg.className = 'text-xs mt-2 text-rose-700';
      msg.textContent = '✗ Network error. Try again.';
      msg.classList.remove('hidden');
      btn.disabled = false;
      btn.textContent = '🔄 Start fresh evaluation (Round #<?= (int)$current_round + 1 ?>)';
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
