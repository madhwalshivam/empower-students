<?php
require_once __DIR__ . '/includes/auth.php';
require_parent();
$page_title = 'Dashboard';
$parent = current_parent();

// Load parent_reflect helpers so the dashboard can show recent/in-progress status
@include_once __DIR__ . '/includes/parent_reflect_schema.php';
@include_once __DIR__ . '/includes/parent_reflect_engine.php';
@include_once __DIR__ . '/includes/home_course_engine.php';

/* fresh-v8c: detect active 7-day course (defensive — table may not exist yet) */
$hc_active = null;
$hc_today = null;
$hc_progress = null;
try {
    if (function_exists('_home_course_ensure_schema')) {
        _home_course_ensure_schema();
    }
    if (function_exists('home_course_find_active')) {
        $hc_active = home_course_find_active((int)$parent['id']);
    }
    if ($hc_active && function_exists('home_course_today_day')) {
        $hc_today = home_course_today_day((int)$hc_active['id']);
    }
    if ($hc_active && function_exists('home_course_progress_data')) {
        $hc_progress = home_course_progress_data((int)$hc_active['id']);
    }
} catch (Throwable $e) {
    error_log('[dashboard] home_course lookup failed: ' . $e->getMessage());
    $hc_active = null;
}

// All children
$cs = db()->prepare('SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC');
$cs->execute([$parent['id']]);
$children = $cs->fetchAll();

// Selected child = ?cid=N or first child
$selected_cid = (int)($_GET['cid'] ?? ($children[0]['id'] ?? 0));
$selected = null;
foreach ($children as $c) {
    if ((int)$c['id'] === $selected_cid) { $selected = $c; break; }
}
if (!$selected && $children) {
    $selected = $children[0];
    $selected_cid = (int)$selected['id'];
}

// Catalogue of all 12 modules — shown for every child, every age
$ALL_MODULES = [
    'health'            => ['💗', 'Health screening',     'svc.health'],
    'mind_power'        => ['🧠', 'Mind power',           'svc.mind'],
    /* fresh-v8f: removed 'emotions' — overlaps with Behaviour */
    'behavior'          => ['🧩', 'Behaviour',            'svc.beh'],
    'general_awareness' => ['🌍', 'General awareness',    'svc.gen'],
    'special_talent'    => ['⭐', 'Special talent',       'svc.tal'],
    /* fresh-v8d: removed 'speech' — replaced by Speech & Language Eval card at top */
    /* fresh-v8e: removed 'spontaneous' and 'parent_index' — to be folded into Child Starter Pack */
    'math'              => ['🔢', 'Maths level',          'svc.math'],
    'language'          => ['📚', 'Language &amp; reading','svc.lang'],
    'diet'              => ['🥗', 'Diet advice',          'svc.diet'],
];

// Per-module status for the selected child
$module_status = [];
if ($selected) {
    $today_start = date('Y-m-d 00:00:00');
    foreach ($ALL_MODULES as $key => $meta) {
        $st = db()->prepare(
            "SELECT score, completed_at, ai_summary
             FROM assessments
             WHERE child_id = ? AND module = ? AND status = 'done'
             ORDER BY completed_at DESC LIMIT 1"
        );
        $st->execute([$selected_cid, $key]);
        $latest = $st->fetch();

        $today = db()->prepare(
            "SELECT COUNT(*) FROM assessments
             WHERE child_id = ? AND module = ? AND status = 'done'
             AND completed_at >= ?"
        );
        $today->execute([$selected_cid, $key, $today_start]);
        $done_today = (int)$today->fetchColumn();

        $count = db()->prepare(
            "SELECT COUNT(*) FROM assessments WHERE child_id = ? AND module = ? AND status = 'done'"
        );
        $count->execute([$selected_cid, $key]);

        $module_status[$key] = [
            'latest'      => $latest ?: null,
            'done_today'  => $done_today,
            'total_count' => (int)$count->fetchColumn(),
        ];
    }
}

// Unread feedback from the team
$fb_st = db()->prepare("SELECT * FROM parent_feedback WHERE parent_id = ? AND seen_by_parent = 0 ORDER BY id DESC");
$fb_st->execute([$parent['id']]);
$unread_feedback = $fb_st->fetchAll();

// Daily-limit flash from a redirected module
$daily_flash = '';
if (!empty($_SESSION['daily_limit_flash'])) {
    $daily_flash = $_SESSION['daily_limit_flash'];
    unset($_SESSION['daily_limit_flash']);
}

require __DIR__ . '/includes/header.php';
?>

<?php foreach ($unread_feedback as $fb): ?>
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-3 text-emerald-900 text-sm">
    💬 <strong><span data-i18n="dash.note">Note from</span> <?= e($fb['author']) ?>:</strong>
    <div class="mt-1 whitespace-pre-line"><?= e($fb['body']) ?></div>
    <a href="/wallet.php?ack=<?= (int)$fb['id'] ?>" class="text-xs underline mt-2 inline-block" data-i18n="dash.markRead">Mark as read</a>
  </div>
<?php endforeach; ?>

<?php if ($daily_flash): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-4 text-amber-900 text-sm">
    ⏳ <?= e($daily_flash) ?>
  </div>
<?php endif; ?>

<!-- Greeting + add child -->
<div class="flex flex-wrap items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-2xl sm:text-3xl font-extrabold">
      <span data-i18n="dash.greet">Hi</span> <?= e($parent['name'] ?: 'parent') ?> 👋
    </h1>
    <p class="text-slate-600">
      <span data-i18n="dash.intro.balance">You have</span>
      <a href="/wallet.php" class="text-emerald-700 font-semibold">
        <?= (int)$parent['credits'] ?> <span data-i18n="wal.credits">credits</span>
      </a>.
    </p>
  </div>
  <a href="/add_child.php" class="brand-grad text-white font-semibold px-5 py-2.5 rounded-xl shadow hover:opacity-90" data-i18n="dash.add_child">+ Add child</a>
</div>

<?php if (empty($children)): ?>
  <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center">
    <div class="text-5xl mb-3">👶</div>
    <h2 class="text-xl font-semibold mb-1" data-i18n="dash.empty">No child registered yet</h2>
    <p class="text-slate-600 mb-4" data-i18n="dash.empty.hint">Add your child&rsquo;s profile to begin the assessments.</p>
    <a href="/add_child.php" class="inline-block brand-grad text-white font-semibold px-5 py-2.5 rounded-xl" data-i18n="dash.empty.cta">Add my first child</a>
  </div>
<?php else: ?>

  <!-- Child selector tabs -->
  <div class="flex gap-2 mb-6 overflow-x-auto -mx-4 px-4 pb-1">
    <?php foreach ($children as $c):
      $cid = (int)$c['id'];
      $is_active = $cid === $selected_cid;
      $tab_age = calc_age_years($c['dob']);
      $tab_age_disp = $tab_age !== null ? number_format($tab_age, 1) : '—';
    ?>
      <a href="?cid=<?= $cid ?>"
         class="shrink-0 inline-flex items-center gap-2 rounded-2xl border-2 px-4 py-2.5 transition <?= $is_active ? 'bg-indigo-600 text-white border-indigo-600 shadow' : 'bg-white text-slate-700 border-slate-200 hover:border-indigo-300' ?>">
        <span class="w-8 h-8 rounded-full flex items-center justify-center font-bold <?= $is_active ? 'bg-white/20' : 'brand-grad text-white' ?>">
          <?= e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?>
        </span>
        <span class="font-semibold"><?= e($c['name']) ?></span>
        <span class="text-xs <?= $is_active ? 'text-white/80' : 'text-slate-400' ?>">· <?= e($tab_age_disp) ?>y</span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($selected):
    $sage  = calc_age_years($selected['dob']);
    $sage_disp = $sage !== null ? number_format($sage, 1) : '—';
    $sband = age_band($sage);
    $done_count = 0;
    foreach ($module_status as $s) if ($s['latest']) $done_count++;
    $progress_pct = round(($done_count / 12) * 100);
  ?>

    <!-- Selected child header card -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-5">
      <div class="flex flex-wrap items-center gap-3 justify-between">
        <div>
          <h2 class="text-xl font-bold"><?= e($selected['name']) ?></h2>
          <p class="text-sm text-slate-500">
            <?= e($sage_disp) ?> <span data-i18n="child.years">yrs</span>
            &middot; <?= e($selected['gender'] ?: '—') ?>
            &middot; <span class="font-semibold"><?= e($sband) ?></span>
            <?php if (!empty($selected['class_grade'])): ?> &middot; <?= e($selected['class_grade']) ?><?php endif; ?>
            <?php if (!empty($selected['diagnosis'])): ?> &middot; <span class="text-rose-600"><?= e($selected['diagnosis']) ?></span><?php endif; ?>
          </p>
        </div>
        <a href="/report.php?id=<?= $selected_cid ?>"
           class="inline-flex items-center gap-2 brand-grad text-white text-sm font-semibold px-4 py-2 rounded-lg shadow hover:opacity-90"
           data-i18n="dash.btn.report">📋 AI report</a>
      </div>
      <div class="mt-3 text-xs text-slate-500">
        <?= $done_count ?> / 12 <span data-i18n="dash.modules">modules done</span>
        <div class="mt-1 h-2 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full brand-grad transition-all" style="width: <?= $progress_pct ?>%"></div>
        </div>
      </div>
    </div>

    <!-- ─────────────────  PREMIUM SERVICES (parent-facing)  ───────────────── -->
    <?php
      // Show recent parent-reflect status if available
      $pr_recent = null; $pr_in_progress = null;
      if (function_exists('pr_recent_complete_for')) {
          $pr_recent = pr_recent_complete_for($parent['id'], 7);
      }
      if (function_exists('pr_in_progress_for')) {
          $pr_in_progress = pr_in_progress_for($parent['id']);
      }
    ?>
    <div class="mb-6">
      <h3 class="text-xs uppercase tracking-wider text-slate-500 font-semibold mb-3 px-1">
        Premium services
      </h3>
      <div class="grid sm:grid-cols-2 gap-3">

        <!-- Speech Evaluation card -->
        <a href="/eval-speech.php"
           class="block rounded-2xl border-2 border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50 p-4 hover:shadow-md transition">
          <div class="flex items-start gap-3 mb-2">
            <span class="text-3xl shrink-0">🎤</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-baseline gap-2 flex-wrap">
                <h4 class="font-bold text-slate-900">Speech &amp; Language Eval</h4>
                <span class="text-xs bg-emerald-200 text-emerald-900 font-bold px-2 py-0.5 rounded-full">₹1000</span>
              </div>
              <p class="text-xs text-slate-600 mt-1 leading-snug">
                Adaptive voice evaluation in Hindi or English. Find your child's current speech level
                in 10 minutes. <strong>First one free.</strong>
              </p>
            </div>
          </div>
          <div class="text-xs text-emerald-700 font-semibold flex items-center gap-1">
            Start evaluation <span>→</span>
          </div>
        </a>

        <!-- fresh-v8c: active 7-day course card (shown only when course is active) -->
        <?php if ($hc_active): 
            $hc_day_no = (int)($hc_today['day_no'] ?? 1);
            $hc_done = ($hc_progress && isset($hc_progress['days']) && is_array($hc_progress['days']))
                       ? count($hc_progress['days']) : 0;
            $hc_pct = (int) round(($hc_done / 7) * 100);
        ?>
        <a href="/home-course.php?id=<?= (int)$hc_active['id'] ?>"
           class="block rounded-2xl border-2 border-emerald-300 bg-gradient-to-br from-emerald-50 to-teal-50 p-4 hover:shadow-md transition relative">
          <span class="absolute top-2 right-2 text-[10px] uppercase tracking-wide font-bold bg-emerald-600 text-white px-2 py-0.5 rounded-full">
            ● Course on
          </span>
          <div class="flex items-start gap-3 mb-2">
            <span class="text-3xl shrink-0">📚</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-baseline gap-2 flex-wrap">
                <h4 class="font-bold text-slate-900">7-Day Home Course</h4>
                <span class="text-xs bg-emerald-200 text-emerald-900 font-bold px-2 py-0.5 rounded-full">Day <?= $hc_day_no ?> of 7</span>
              </div>
              <p class="text-xs text-slate-600 mt-1 leading-snug">
                Your personalised daily practice — 10 minutes a day, adapted to your reflection.
              </p>
              <div class="mt-2 w-full bg-emerald-100 rounded-full h-1.5 overflow-hidden">
                <div class="bg-emerald-600 h-full transition-all" style="width: <?= $hc_pct ?>%"></div>
              </div>
            </div>
          </div>
          <div class="text-xs text-emerald-700 font-semibold flex items-center gap-1">
            <?= $hc_done >= 7 ? 'Course complete — review days' : 'Continue Day ' . $hc_day_no ?>
            <span>→</span>
          </div>
        </a>
        <?php endif; ?>

        <!-- Parent Reflection card -->
        <a href="/parent-reflect.php"
           class="block rounded-2xl border-2 border-purple-200 bg-gradient-to-br from-indigo-50 to-purple-50 p-4 hover:shadow-md transition relative">
          <?php if ($pr_recent): ?>
            <span class="absolute top-2 right-2 text-[10px] uppercase tracking-wide font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">
              ✓ Recent
            </span>
          <?php elseif ($pr_in_progress): ?>
            <span class="absolute top-2 right-2 text-[10px] uppercase tracking-wide font-bold bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full">
              In progress
            </span>
          <?php endif; ?>
          <div class="flex items-start gap-3 mb-2">
            <span class="text-3xl shrink-0">💜</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-baseline gap-2 flex-wrap">
                <h4 class="font-bold text-slate-900">Parent Reflection</h4>
                <span class="text-xs bg-purple-200 text-purple-900 font-bold px-2 py-0.5 rounded-full">₹1000</span>
              </div>
              <p class="text-xs text-slate-600 mt-1 leading-snug">
                A private 15-minute reflection on home, family &amp; your own state.
                Get a personalised report and a 7-day course tailored just for you.
              </p>
            </div>
          </div>
          <div class="text-xs text-purple-700 font-semibold flex items-center gap-1">
            <?= $pr_recent ? 'View my reflection' : ($pr_in_progress ? 'Continue where you left off' : 'Begin reflection') ?>
            <span>→</span>
          </div>
        </a>
      </div>
    </div>

    <!-- 12-module grid -->
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($ALL_MODULES as $key => $meta):
        $emoji   = $meta[0];
        $label   = $meta[1];
        $i18n    = $meta[2];
        $status  = $module_status[$key];
        $latest  = $status['latest'];
        $locked_today = $latest && $status['done_today'] > 0;

        $next_avail = '';
        if ($locked_today) {
            $tomorrow = strtotime('tomorrow midnight');
            $hours = max(1, (int)round(($tomorrow - time()) / 3600));
            $next_avail = 'Next attempt in ~' . $hours . 'h';
        }

        $price = function_exists('wallet_service_price') ? wallet_service_price($key) : null;

        if ($locked_today) {
            $border       = 'border-amber-200 bg-amber-50';
            $badge_text   = '⏳ Done today';
            $badge_color  = 'bg-amber-100 text-amber-800';
        } elseif ($latest) {
            $border       = 'border-emerald-200 bg-white';
            $badge_text   = '✓ Done';
            $badge_color  = 'bg-emerald-100 text-emerald-700';
        } else {
            $border       = 'border-slate-200 bg-white';
            $badge_text   = '▶ Start';
            $badge_color  = 'bg-indigo-100 text-indigo-700';
        }
      ?>
        <div class="rounded-2xl border-2 p-4 shadow-sm hover:shadow-md transition flex flex-col <?= $border ?>">
          <div class="flex items-start justify-between gap-2 mb-2">
            <div class="flex items-center gap-2 flex-1 min-w-0">
              <span class="text-2xl shrink-0"><?= $emoji ?></span>
              <h3 class="font-semibold text-slate-800 truncate" data-i18n="<?= $i18n ?>.t"><?= $label ?></h3>
            </div>
            <span class="text-[10px] uppercase tracking-wide font-bold rounded-full px-2 py-0.5 shrink-0 <?= $badge_color ?>">
              <?= $badge_text ?>
            </span>
          </div>

          <div class="text-xs text-slate-500 mb-3 min-h-[36px]">
            <?php if ($latest): ?>
              <?php if ($latest['score'] !== null): ?>
                <span data-i18n="dash.lastScore">Last score:</span>
                <strong class="text-slate-800"><?= number_format((float)$latest['score'], 1) ?></strong>
              <?php else: ?>
                <span data-i18n="dash.completed">Completed</span>
              <?php endif; ?>
              · <?= e(substr($latest['completed_at'] ?? '', 0, 10)) ?>
              <?php if ($status['total_count'] > 1): ?>
                · <?= $status['total_count'] ?> <span data-i18n="dash.attempts">attempts</span>
              <?php endif; ?>
              <?php if ($locked_today): ?>
                <div class="text-amber-700 mt-1"><?= e($next_avail) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-slate-400" data-i18n="dash.notStarted2">Not started yet</span>
              <?php if ($price): ?>
                · <?= (int)$price ?> <span data-i18n="nav.cr">cr</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div class="mt-auto flex gap-2">
            <?php
              /* fresh-v8g: route mind_power / behavior / general_awareness to the
                 adaptive child-eval engine (turn-by-turn). Others keep old form. */
              $adaptive_modules = ['mind_power', 'behavior', 'general_awareness'];
              $module_href = in_array($key, $adaptive_modules, true)
                  ? '/child-eval.php?cid=' . $selected_cid . '&module=' . e($key)
                  : '/modules/' . e($key) . '.php?cid=' . $selected_cid;
            ?>
            <?php if ($locked_today): ?>
              <button disabled class="flex-1 text-center bg-slate-200 text-slate-500 text-sm font-semibold py-2 rounded-lg cursor-not-allowed">
                🔒 <span data-i18n="dash.lockedToday">Come back tomorrow</span>
              </button>
            <?php elseif ($latest): ?>
              <a href="<?= $module_href ?>"
                 class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium py-2 rounded-lg"
                 data-i18n="child.redo">Redo</a>
            <?php else: ?>
              <a href="<?= $module_href ?>"
                 class="flex-1 text-center brand-grad text-white text-sm font-semibold py-2 rounded-lg hover:opacity-90"
                 data-i18n="child.start">Start</a>
            <?php endif; ?>

            <?php if ($status['total_count'] > 0): ?>
              <button type="button"
                      data-history-module="<?= e($key) ?>"
                      data-history-label="<?= e($label) ?>"
                      class="track-btn px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-sm font-medium"
                      title="View history graph">📈</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

<?php endif; ?>

<!-- ─────────────────  HISTORY MODAL  ───────────────── -->
<div id="esModal" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onclick="if(event.target===this) esCloseModal()">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 relative">
    <button onclick="esCloseModal()" class="absolute top-3 right-3 text-slate-400 hover:text-slate-700 text-2xl leading-none">×</button>
    <h3 id="esModalTitle" class="text-lg font-semibold mb-1">Module history</h3>
    <p id="esModalSub" class="text-xs text-slate-500 mb-4">All attempts for this child</p>
    <div id="esModalChart" class="bg-slate-50 rounded-xl p-3 mb-4"></div>
    <div id="esModalList" class="space-y-2 max-h-64 overflow-y-auto text-sm"></div>
    <div id="esModalEmpty" class="hidden text-center text-slate-500 py-8 text-sm">No previous attempts.</div>
  </div>
</div>

<script>
(function() {
  const modal     = document.getElementById('esModal');
  const titleEl   = document.getElementById('esModalTitle');
  const subEl     = document.getElementById('esModalSub');
  const chartEl   = document.getElementById('esModalChart');
  const listEl    = document.getElementById('esModalList');
  const emptyEl   = document.getElementById('esModalEmpty');
  const childId   = <?= json_encode($selected_cid) ?>;

  window.esCloseModal = function() { modal.classList.add('hidden'); };

  document.querySelectorAll('.track-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const mod   = btn.dataset.historyModule;
      const label = btn.dataset.historyLabel;
      titleEl.innerHTML = '📈 ' + label;
      subEl.textContent = 'Loading attempts…';
      chartEl.innerHTML = '';
      listEl.innerHTML  = '';
      emptyEl.classList.add('hidden');
      modal.classList.remove('hidden');

      try {
        const res  = await fetch('/api/history.php?cid=' + childId + '&module=' + encodeURIComponent(mod), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.attempts || data.attempts.length === 0) {
          subEl.textContent = '';
          emptyEl.classList.remove('hidden');
          return;
        }
        subEl.textContent = data.attempts.length + ' attempt' + (data.attempts.length === 1 ? '' : 's') + ' for this module';
        renderChart(data.attempts);
        renderList(data.attempts);
      } catch (err) {
        subEl.textContent = 'Could not load history.';
        console.error(err);
      }
    });
  });

  function renderChart(attempts) {
    const points = attempts.slice().reverse().filter(a => a.score !== null);
    if (points.length < 1) {
      chartEl.innerHTML = '<p class="text-xs text-slate-500 text-center py-4">No numeric scores recorded for this module.</p>';
      return;
    }
    const W = 480, H = 160, P = 30;
    const xs = points.map((_, i) => points.length === 1 ? W / 2 : P + (i * (W - 2 * P)) / (points.length - 1));
    const scoreMax = Math.max.apply(null, points.map(p => p.score).concat([100]));
    const scoreMin = Math.min.apply(null, points.map(p => p.score).concat([0]));
    const range = (scoreMax - scoreMin) || 1;
    const ys = points.map(p => H - P - ((p.score - scoreMin) / range) * (H - 2 * P));

    const path = points.map((_, i) => (i === 0 ? 'M ' : 'L ') + xs[i].toFixed(1) + ' ' + ys[i].toFixed(1)).join(' ');
    const dots = points.map((p, i) =>
      '<circle cx="' + xs[i].toFixed(1) + '" cy="' + ys[i].toFixed(1) + '" r="5" fill="#4f46e5"/>' +
      '<text x="' + xs[i].toFixed(1) + '" y="' + (ys[i] - 12).toFixed(1) + '" text-anchor="middle" fill="#1e293b" font-size="11" font-weight="600">' + p.score.toFixed(1) + '</text>'
    ).join('');

    const fill = points.length > 1
      ? '<path d="' + path + ' L ' + xs[xs.length - 1].toFixed(1) + ' ' + (H - P) + ' L ' + xs[0].toFixed(1) + ' ' + (H - P) + ' Z" fill="url(#esGrad)"/>' +
        '<path d="' + path + '" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
      : '';

    chartEl.innerHTML =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" class="w-full h-auto">' +
      '<defs><linearGradient id="esGrad" x1="0" x2="0" y1="0" y2="1">' +
      '<stop offset="0%" stop-color="#4f46e5" stop-opacity="0.25"/>' +
      '<stop offset="100%" stop-color="#4f46e5" stop-opacity="0"/></linearGradient></defs>' +
      '<line x1="' + P + '" y1="' + (H - P) + '" x2="' + (W - P) + '" y2="' + (H - P) + '" stroke="#cbd5e1" stroke-width="1"/>' +
      fill + dots + '</svg>';
  }

  function renderList(attempts) {
    listEl.innerHTML = attempts.map(a => {
      const summaryText = a.ai_summary ? a.ai_summary.slice(0, 240) + (a.ai_summary.length > 240 ? '…' : '') : '';
      const scoreCell = a.score !== null
        ? '<div class="font-bold text-slate-800">' + a.score.toFixed(1) + '</div><div class="text-[10px] text-slate-400 uppercase">score</div>'
        : '<div class="text-emerald-600 font-semibold">✓</div>';
      return '<div class="flex items-start justify-between gap-3 border border-slate-100 rounded-lg p-3">' +
             '<div class="flex-1 min-w-0">' +
             '<div class="text-xs text-slate-500">' + escapeHtml(a.completed_at_short) + '</div>' +
             (summaryText ? '<div class="text-xs text-slate-700 mt-1">' + escapeHtml(summaryText) + '</div>' : '') +
             '</div>' +
             '<div class="text-right shrink-0">' + scoreCell + '</div>' +
             '</div>';
    }).join('');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape') esCloseModal(); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
