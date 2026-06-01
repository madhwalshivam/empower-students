<?php
/**
 * tracker.php — daily growth tracker
 *
 * Tracker access comes from Care Pack (initial 30 days) or top-up packs (149 cr each, +30 days).
 * No subscriptions. Days roll over until consumed by daily logs.
 *
 * URL: /tracker.php?id=<child_id>
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/claude.php';
require_once __DIR__ . '/includes/paid_schema.php';
require_parent();

$parent = current_parent();
$child  = child_for_parent((int)($_GET['id'] ?? 0));
if (!$child) { header('Location: /dashboard.php'); exit; }

$pack = care_pack_for((int)$parent['id'], (int)$child['id']);
if (!$pack) {
    header('Location: /care_pack.php?id=' . (int)$child['id']); exit;
}

$days_remaining = (int)$pack['tracker_days_remaining'];
$gate_message = '';

// ── Top-up purchase ──
if (!empty($_POST['action']) && $_POST['action'] === 'topup') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $gate_message = 'Security check failed — reload.';
    } else {
        // Idempotency: each top-up is a NEW transaction, so use a fresh ref each time.
        // We use the count of existing top-ups as the ref to ensure uniqueness.
        $next_idx = (int)db()->query("SELECT COUNT(*) FROM tracker_topups WHERE child_id=" . (int)$child['id'])->fetchColumn();
        $ref = (int)((crc32($child['id'] . ':topup:' . $next_idx)) & 0x7FFFFFFF);

        $charge = wallet_charge_for_service((int)$parent['id'], 'tracker_topup', $ref);
        if ($charge['status'] === 'insufficient') {
            $price = wallet_service_price('tracker_topup') ?? 149;
            $_SESSION['flash_error'] = "Top-up costs {$price} credits — you have {$charge['credits']}.";
            header('Location: /wallet.php?need=' . $price); exit;
        }

        // Add 30 days
        db()->prepare("INSERT INTO tracker_topups (parent_id, child_id, days_added) VALUES (?,?,30)")
            ->execute([$parent['id'], $child['id']]);
        db()->prepare("UPDATE care_packs SET tracker_days_remaining = tracker_days_remaining + 30 WHERE parent_id=? AND child_id=?")
            ->execute([$parent['id'], $child['id']]);
        $_SESSION['flash_success'] = "✨ +30 days added to the tracker.";
        header('Location: /tracker.php?id=' . (int)$child['id']); exit;
    }
}

// ── Daily log save ──
$today = date('Y-m-d');
$today_log_st = db()->prepare("SELECT * FROM daily_logs WHERE child_id=? AND log_date=?");
$today_log_st->execute([$child['id'], $today]);
$today_log = $today_log_st->fetch();

if (!empty($_POST['action']) && $_POST['action'] === 'log') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $gate_message = 'Security check failed — reload.';
    } elseif (!$today_log && $days_remaining <= 0) {
        $gate_message = 'You have used all tracker days. Top up to keep logging.';
    } else {
        $is_new = !$today_log;
        $data = [
            'child_id'    => $child['id'],
            'log_date'    => $today,
            'mood'        => $_POST['mood']      !== '' ? (int)$_POST['mood']      : null,
            'sleep_hours' => $_POST['sleep']     !== '' ? (float)$_POST['sleep']   : null,
            'focus'       => $_POST['focus']     !== '' ? (int)$_POST['focus']     : null,
            'behaviour'   => $_POST['behaviour'] !== '' ? (int)$_POST['behaviour'] : null,
            'appetite'    => $_POST['appetite']  !== '' ? (int)$_POST['appetite']  : null,
            'wins'        => mb_substr(trim((string)($_POST['wins'] ?? '')), 0, 500),
            'concerns'    => mb_substr(trim((string)($_POST['concerns'] ?? '')), 0, 500),
        ];
        if ($today_log) {
            db()->prepare("UPDATE daily_logs SET mood=?, sleep_hours=?, focus=?, behaviour=?, appetite=?, wins=?, concerns=? WHERE id=?")
                ->execute([$data['mood'], $data['sleep_hours'], $data['focus'], $data['behaviour'], $data['appetite'], $data['wins'], $data['concerns'], $today_log['id']]);
        } else {
            db()->prepare("INSERT INTO daily_logs (child_id, log_date, mood, sleep_hours, focus, behaviour, appetite, wins, concerns) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute(array_values($data));
            // Consume one tracker day for new logs only (edits don't burn days)
            tracker_consume_day((int)$child['id']);
        }
        header('Location: /tracker.php?id=' . (int)$child['id'] . '&saved=' . ($is_new ? 'new' : 'edit')); exit;
    }
}

// ── Refresh after possible save ──
$pack = care_pack_for((int)$parent['id'], (int)$child['id']);
$days_remaining = (int)$pack['tracker_days_remaining'];
$today_log_st->execute([$child['id'], $today]);
$today_log = $today_log_st->fetch();

// Last 30 days of logs for chart
$logs = db()->prepare("SELECT * FROM daily_logs WHERE child_id=? AND log_date >= ? ORDER BY log_date ASC");
$logs->execute([$child['id'], date('Y-m-d', strtotime('-29 days'))]);
$logs = $logs->fetchAll();

$by_date = [];
foreach ($logs as $l) $by_date[$l['log_date']] = $l;

// Streak
$streak = 0;
$cur = $today;
while (isset($by_date[$cur])) {
    $streak++;
    $cur = date('Y-m-d', strtotime($cur . ' -1 day'));
}

// 7-day vs prior-7 deltas
function _avg($arr, $key) {
    $vs = array_filter(array_map(fn($l) => $l[$key], $arr), fn($v) => $v !== null && $v !== '');
    return count($vs) > 0 ? array_sum($vs) / count($vs) : null;
}
$last_7  = array_filter($logs, fn($l) => $l['log_date'] >= date('Y-m-d', strtotime('-6 days')));
$prior_7 = array_filter($logs, fn($l) => $l['log_date'] >= date('Y-m-d', strtotime('-13 days')) && $l['log_date'] < date('Y-m-d', strtotime('-6 days')));

// Weekly summary
$wk_start = date('Y-m-d', strtotime('monday this week'));
$wk_st = db()->prepare("SELECT * FROM weekly_summaries WHERE child_id=? AND week_start=?");
$wk_st->execute([$child['id'], $wk_start]);
$wk_summary = $wk_st->fetch();

// AI weekly summary generator
if (!empty($_POST['action']) && $_POST['action'] === 'gen_summary' && csrf_check($_POST['csrf'] ?? '')) {
    $week_logs = array_filter($logs, fn($l) => $l['log_date'] >= $wk_start);
    if (count($week_logs) >= 3) {
        $sys = "You are a paediatric child-development expert. Write a short, warm weekly summary for the parent of {$child['name']}, age " . calc_age_years($child['dob']) . ". 3-4 sentences max. Identify ONE positive trend, ONE thing to watch, and ONE specific suggestion for next week. Plain English, Indian context.";
        $user = "Daily logs:\n" . json_encode(array_values($week_logs), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $txt = claude_chat($sys, [['role' => 'user', 'content' => $user]], 400, 0.6);
        if ($txt) {
            if ($wk_summary) {
                db()->prepare("UPDATE weekly_summaries SET ai_summary=?, created_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$txt, $wk_summary['id']]);
            } else {
                db()->prepare("INSERT INTO weekly_summaries (child_id, week_start, ai_summary) VALUES (?,?,?)")
                    ->execute([$child['id'], $wk_start, $txt]);
            }
        }
    }
    header('Location: /tracker.php?id=' . (int)$child['id']); exit;
}

$page_title = 'Tracker — ' . $child['name'];
$topup_price = wallet_service_price('tracker_topup') ?? 149;
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto">
  <a href="/child.php?id=<?= (int)$child['id'] ?>" class="text-sm text-indigo-600">← <?= e($child['name']) ?></a>

  <!-- ── Days strip ──────────────────────────────────────────── -->
  <div class="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl px-5 py-3 mt-3 mb-5 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-3">
      <div class="text-3xl"><?= $streak > 0 ? '🔥' : '📊' ?></div>
      <div>
        <div class="font-bold"><?= $streak ?>-day streak</div>
        <div class="text-xs text-slate-500"><?= $days_remaining ?> tracker days left</div>
      </div>
    </div>
    <?php if ($days_remaining <= 7): ?>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="topup">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-1.5 rounded-full text-xs font-bold">
          + 30 days · <?= $topup_price ?> cr
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($gate_message): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 mb-4"><?= e($gate_message) ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['saved'])): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-xl px-4 py-2 mb-4 text-sm">
      ✓ <?= $_GET['saved'] === 'new' ? 'Logged for today.' : 'Updated today\'s log.' ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-xl px-4 py-2 mb-4 text-sm">
      <?= e($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <!-- ── Out of days banner ────────────────────────────────── -->
  <?php if ($days_remaining <= 0 && !$today_log): ?>
    <div class="bg-gradient-to-br from-amber-100 to-orange-100 border-2 border-orange-300 rounded-2xl p-6 mb-5 text-center">
      <div class="text-4xl mb-2">⏸️</div>
      <h3 class="font-bold text-lg mb-1">Tracker paused</h3>
      <p class="text-sm text-slate-700 mb-4">You've used all your tracker days. Add 30 more to keep going — your history stays safe.</p>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="topup">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-2.5 rounded-full font-bold text-sm">
          Add 30 days · <?= $topup_price ?> credits
        </button>
      </form>
      <div class="text-xs text-slate-500 mt-3">Your balance: <?= (int)$parent['credits'] ?> credits</div>
    </div>
  <?php endif; ?>

  <!-- ── Today's check-in ──────────────────────────────────── -->
  <?php if ($days_remaining > 0 || $today_log): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5">
      <h2 class="font-bold text-lg mb-1">Today's check-in <span class="text-sm text-slate-500 font-normal">— <?= date('D, d M') ?></span></h2>
      <p class="text-sm text-slate-500 mb-4">
        <?php if ($today_log): ?>
          ✓ <strong class="text-emerald-600">Logged</strong> — you can update if needed (free, doesn't use a day).
        <?php else: ?>
          Two minutes. Logging today will use 1 of <?= $days_remaining ?> remaining days.
        <?php endif; ?>
      </p>

      <form method="post" class="space-y-5">
        <input type="hidden" name="action" value="log">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php
        $emoji_groups = [
            'mood'      => ['How was their mood?',     ['1' => '😢', '2' => '😟', '3' => '😐', '4' => '🙂', '5' => '😄']],
            'focus'     => ['Focus / attention?',      ['1' => '🌫️', '2' => '😶', '3' => '🌤️', '4' => '☀️', '5' => '🎯']],
            'behaviour' => ['Behaviour today?',        ['1' => '🌪️', '2' => '😤', '3' => '😐', '4' => '🤝', '5' => '🌟']],
            'appetite'  => ['Appetite?',               ['1' => '🚫', '2' => '🥺', '3' => '🍽️', '4' => '😋', '5' => '🍱']],
        ];
        foreach ($emoji_groups as $key => $info):
          [$label, $opts] = $info;
          $cur = $today_log[$key] ?? '';
        ?>
          <div>
            <label class="text-sm font-semibold text-slate-700 block mb-2"><?= e($label) ?></label>
            <div class="flex gap-2">
              <?php foreach ($opts as $val => $emoji):
                $checked = (string)$cur === (string)$val;
              ?>
                <label class="cursor-pointer flex-1">
                  <input type="radio" name="<?= $key ?>" value="<?= $val ?>" <?= $checked ? 'checked' : '' ?> class="peer sr-only">
                  <div class="border-2 border-slate-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 rounded-xl p-3 text-center text-2xl hover:border-emerald-300 transition">
                    <?= $emoji ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div>
          <label class="text-sm font-semibold text-slate-700 block mb-2">Sleep hours <span class="font-normal text-slate-400">(optional)</span></label>
          <input type="number" step="0.5" min="0" max="24" name="sleep" value="<?= e($today_log['sleep_hours'] ?? '') ?>"
                 placeholder="e.g. 9.5"
                 class="w-32 border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700 block mb-2">🌟 Wins today <span class="font-normal text-slate-400">(optional)</span></label>
          <textarea name="wins" rows="2" maxlength="500" placeholder="One small thing that went well…"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"><?= e($today_log['wins'] ?? '') ?></textarea>
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700 block mb-2">⚠️ Concerns today <span class="font-normal text-slate-400">(optional)</span></label>
          <textarea name="concerns" rows="2" maxlength="500" placeholder="Anything that worried you…"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"><?= e($today_log['concerns'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-3 rounded-full font-bold transition">
          <?= $today_log ? '↻ Update today' : '✓ Save today' ?>
        </button>
      </form>
    </div>
  <?php endif; ?>

  <!-- ── 30-day chart ──────────────────────────────────────── -->
  <?php if (count($logs) >= 2): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5">
      <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <h2 class="font-bold text-lg">30-day trend</h2>
        <div class="text-xs text-slate-500"><?= count($logs) ?> days logged</div>
      </div>

      <?php
        $metrics = [
          'mood'      => ['Mood', '#f472b6'],
          'focus'     => ['Focus', '#60a5fa'],
          'behaviour' => ['Behaviour', '#34d399'],
          'appetite'  => ['Appetite', '#fbbf24'],
        ];
      ?>
      <div class="space-y-4">
        <?php foreach ($metrics as $key => $info):
          [$label, $color] = $info;
          $a7 = _avg($last_7, $key);
          $ap = _avg($prior_7, $key);
          $delta = ($a7 !== null && $ap !== null) ? ($a7 - $ap) : null;
        ?>
          <div>
            <div class="flex items-center justify-between text-xs text-slate-600 mb-1">
              <strong><?= $label ?></strong>
              <span>
                <?php if ($a7 !== null): ?>
                  7-day avg <strong><?= number_format($a7, 1) ?></strong>/5
                  <?php if ($delta !== null && abs($delta) >= 0.2): ?>
                    <span class="<?= $delta > 0 ? 'text-emerald-600' : 'text-rose-600' ?> ml-1">
                      <?= $delta > 0 ? '↑' : '↓' ?> <?= number_format(abs($delta), 1) ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-slate-400">no data</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="flex items-end gap-0.5 h-12">
              <?php
                $start = strtotime(date('Y-m-d', strtotime('-29 days')));
                for ($d = 0; $d < 30; $d++):
                  $date_iso = date('Y-m-d', $start + $d * 86400);
                  $log = $by_date[$date_iso] ?? null;
                  $val = $log ? $log[$key] : null;
                  $h = $val !== null ? max(8, (int)$val * 18) : 4;
              ?>
                <div class="flex-1 rounded-t <?= $val !== null ? '' : 'bg-slate-100' ?>"
                     style="<?= $val !== null ? "background: $color;" : '' ?> height: <?= $h ?>px; opacity: <?= $val !== null ? '1' : '0.3' ?>;"
                     title="<?= $date_iso ?>: <?= $val ?? '—' ?>"></div>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── Weekly AI summary ─────────────────────────────────── -->
  <div class="bg-gradient-to-br from-violet-50 to-indigo-50 border border-indigo-200 rounded-2xl p-6 mb-5">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
      <h2 class="font-bold text-lg flex items-center gap-2">🤖 This week's AI summary</h2>
      <?php if (count(array_filter($logs, fn($l) => $l['log_date'] >= $wk_start)) >= 3): ?>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="gen_summary">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button type="submit" class="text-xs text-indigo-600 font-semibold hover:underline">↻ <?= $wk_summary ? 'Regenerate' : 'Generate' ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($wk_summary): ?>
      <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(e($wk_summary['ai_summary'])) ?></p>
      <p class="text-xs text-slate-400 mt-3">Generated <?= e(date('d M, H:i', strtotime($wk_summary['created_at']))) ?> · Free with your Care Pack</p>
    <?php elseif (count(array_filter($logs, fn($l) => $l['log_date'] >= $wk_start)) >= 3): ?>
      <p class="text-sm text-slate-600">Click <strong>Generate</strong> above to get this week's AI summary.</p>
    <?php else: ?>
      <p class="text-sm text-slate-600">Log at least 3 days this week to unlock the AI summary.</p>
    <?php endif; ?>
  </div>

  <!-- ── Cross-links ───────────────────────────────────────── -->
  <div class="grid sm:grid-cols-2 gap-3">
    <a href="/growth_plan.php?id=<?= (int)$child['id'] ?>" class="bg-white border border-slate-200 rounded-xl p-4 hover:border-indigo-300 transition flex items-center justify-between">
      <div>
        <div class="font-bold">🌱 Growth Plan</div>
        <div class="text-xs text-slate-500">Re-read the 4-week plan</div>
      </div>
      <span class="text-indigo-500">→</span>
    </a>
    <a href="/course.php?id=<?= (int)$child['id'] ?>" class="bg-white border border-slate-200 rounded-xl p-4 hover:border-amber-300 transition flex items-center justify-between">
      <div>
        <div class="font-bold">📚 <?= e($child['name']) ?>'s Course</div>
        <div class="text-xs text-slate-500">Continue lessons</div>
      </div>
      <span class="text-amber-500">→</span>
    </a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php';
