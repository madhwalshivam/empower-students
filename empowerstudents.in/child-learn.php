<?php
/**
 * child-learn.php — Child Learning Hub (10 modules)
 *
 * Top: child header + package status + free trial state
 * Middle: 10 module cards in a grid, each showing state-appropriate CTA
 * Bottom: progress chart if 2+ baselines done
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/child_access.php';
if (file_exists(__DIR__ . '/includes/launch_config.php')) {
    require_once __DIR__ . '/includes/launch_config.php';
}

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

// Ensure schema for unlocks + tasks (in case never created)
db()->exec("CREATE TABLE IF NOT EXISTS child_program_unlocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, child_id INTEGER NOT NULL, parent_id INTEGER NOT NULL,
    plan_sku TEXT NOT NULL DEFAULT 'child_learn_14d', amount_paid INTEGER NOT NULL DEFAULT 0,
    started_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL, status TEXT DEFAULT 'active'
)");
ca_ensure_schema();

// ── Load child ───────────────────────────────────────────────
$cid = (int)($_GET['cid'] ?? 0);
if (!$cid) {
    $st = db()->prepare("SELECT id FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$parent_id]);
    $cid = (int)$st->fetchColumn();
    if (!$cid) { header('Location: /add_child.php'); exit; }
}
$cst = db()->prepare("SELECT * FROM children WHERE id = ? AND parent_id = ?");
$cst->execute([$cid, $parent_id]);
$child = $cst->fetch();
if (!$child) { header('Location: /dashboard.php'); exit; }

// All children for switcher
$children = [];
try {
    $st = db()->prepare("SELECT id, name, dob FROM children WHERE parent_id = ? ORDER BY id ASC");
    $st->execute([$parent_id]);
    $children = $st->fetchAll();
} catch (Throwable $_) {}

// Helpers
if (!function_exists('calc_age_years')) {
    function calc_age_years($dob): float {
        if (!$dob) return 0;
        return round((time() - strtotime((string)$dob)) / 86400 / 365.25, 1);
    }
}
$age = calc_age_years($child['dob']);
$is_self_led = $age >= 8;

// ── Handle "pick free module" submission ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pick_free') {
    $pick = preg_replace('/[^a-z_]/', '', (string)($_POST['module'] ?? ''));
    if ($pick) {
        ca_set_free_trial($cid, $pick);
        $_SESSION['flash_ok'] = "✓ Free trial set to " . _ca_label($pick) . " — start whenever you're ready!";
    }
    header('Location: /child-learn.php?cid=' . $cid);
    exit;
}

// ── Access state ─────────────────────────────────────────────
$unlock     = ca_has_active_unlock($cid);
$free_trial = ca_free_trial($cid);
$has_unlock = !empty($unlock);
$has_free   = !empty($free_trial['module']);
$days_in    = $has_unlock ? max(1, min(14, (int)floor((time() - strtotime((string)$unlock['started_at'])) / 86400) + 1)) : 0;

// ── 10 Module definitions ────────────────────────────────────
$modules_def = [
    'speech' => [
        'label' => 'Speech', 'label_hi' => 'भाषा / उच्चारण', 'emoji' => '🗣️', 'color' => 'sky',
        'one_liner' => 'Clarity, pronunciation, vocabulary',
        'eval_url' => '/eval-speech.php', 'is_adaptive' => true,
    ],
    'mind_power' => [
        'label' => 'Mind Power', 'label_hi' => 'दिमाग़ी ताक़त', 'emoji' => '🧠', 'color' => 'violet',
        'one_liner' => 'Memory, attention, reasoning',
        'eval_url' => '/child-eval.php?module=mind_power', 'is_adaptive' => true,
    ],
    'behavior' => [
        'label' => 'Behaviour', 'label_hi' => 'व्यवहार', 'emoji' => '💗', 'color' => 'rose',
        'one_liner' => 'Emotional regulation, social skills',
        'eval_url' => '/child-eval.php?module=behavior', 'is_adaptive' => true,
    ],
    'general_awareness' => [
        'label' => 'General Knowledge', 'label_hi' => 'सामान्य ज्ञान', 'emoji' => '🌏', 'color' => 'amber',
        'one_liner' => 'World facts, curiosity, awareness',
        'eval_url' => '/child-eval.php?module=general_awareness', 'is_adaptive' => true,
    ],
    'maths' => [
        'label' => 'Maths', 'label_hi' => 'गणित', 'emoji' => '🔢', 'color' => 'blue',
        'one_liner' => 'Number sense, arithmetic, problems',
        'eval_url' => '/child-eval.php?module=maths', 'is_adaptive' => true,
    ],
    'language' => [
        'label' => 'Language', 'label_hi' => 'भाषा कौशल', 'emoji' => '📚', 'color' => 'indigo',
        'one_liner' => 'Vocabulary, comprehension, grammar',
        'eval_url' => '/child-eval.php?module=language', 'is_adaptive' => true,
    ],
    'health' => [
        'label' => 'Health screening', 'label_hi' => 'स्वास्थ्य जांच', 'emoji' => '💗', 'color' => 'pink',
        'one_liner' => 'Growth, sleep, milestones',
        'eval_url' => '/modules/health.php', 'is_adaptive' => false,
    ],
    'pulse_check' => [
        'label' => 'Pulse & Breath', 'label_hi' => 'नब्ज़ और सांस', 'emoji' => '🫁', 'color' => 'cyan',
        'one_liner' => 'Camera-based pulse + breath-hold',
        'eval_url' => '/modules/pulse_check.php', 'is_adaptive' => false,
    ],
    'diet' => [
        'label' => 'Diet advice', 'label_hi' => 'आहार सलाह', 'emoji' => '🥗', 'color' => 'green',
        'one_liner' => 'Age-appropriate diet recommendations',
        'eval_url' => '/modules/diet.php', 'is_adaptive' => false,
    ],
    'special_talent' => [
        'label' => 'Special Talent', 'label_hi' => 'विशेष प्रतिभा', 'emoji' => '⭐', 'color' => 'yellow',
        'one_liner' => 'Spot a gift to nurture',
        'eval_url' => '/modules/special_talent.php', 'is_adaptive' => false,
    ],
];

// ── Per-module state ─────────────────────────────────────────
function module_state(int $cid, string $module): array {
    $st = db()->prepare("SELECT id, score, level_reached, ai_summary, flags, completed_at
                          FROM assessments
                          WHERE child_id = ? AND module = ? AND status = 'completed'
                          ORDER BY completed_at DESC LIMIT 1");
    $st->execute([$cid, $module]);
    $row = $st->fetch();
    return $row ?: [];
}

$modules_state = [];
foreach ($modules_def as $key => $_) {
    $modules_state[$key] = module_state($cid, $key);
}

$baselines_done = 0;
foreach ($modules_state as $s) if (!empty($s)) $baselines_done++;

$bal = (int)$parent['credits'];
$unlock_price = function_exists('wallet_service_price') ? (int)(wallet_service_price('child_learn_program') ?? 999) : 999;
if ($unlock_price === 0) $unlock_price = 999;

function color_classes(string $c): array {
    return [
        'sky'    => ['bg' => 'bg-sky-50',    'border' => 'border-sky-200',    'text' => 'text-sky-700',    'btn' => 'bg-sky-600 hover:bg-sky-700'],
        'violet' => ['bg' => 'bg-violet-50', 'border' => 'border-violet-200', 'text' => 'text-violet-700', 'btn' => 'bg-violet-600 hover:bg-violet-700'],
        'rose'   => ['bg' => 'bg-rose-50',   'border' => 'border-rose-200',   'text' => 'text-rose-700',   'btn' => 'bg-rose-600 hover:bg-rose-700'],
        'amber'  => ['bg' => 'bg-amber-50',  'border' => 'border-amber-200',  'text' => 'text-amber-700',  'btn' => 'bg-amber-600 hover:bg-amber-700'],
        'blue'   => ['bg' => 'bg-blue-50',   'border' => 'border-blue-200',   'text' => 'text-blue-700',   'btn' => 'bg-blue-600 hover:bg-blue-700'],
        'indigo' => ['bg' => 'bg-indigo-50', 'border' => 'border-indigo-200', 'text' => 'text-indigo-700', 'btn' => 'bg-indigo-600 hover:bg-indigo-700'],
        'pink'   => ['bg' => 'bg-pink-50',   'border' => 'border-pink-200',   'text' => 'text-pink-700',   'btn' => 'bg-pink-600 hover:bg-pink-700'],
        'cyan'   => ['bg' => 'bg-cyan-50',   'border' => 'border-cyan-200',   'text' => 'text-cyan-700',   'btn' => 'bg-cyan-600 hover:bg-cyan-700'],
        'green'  => ['bg' => 'bg-green-50',  'border' => 'border-green-200',  'text' => 'text-green-700',  'btn' => 'bg-green-600 hover:bg-green-700'],
        'yellow' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'text' => 'text-yellow-700', 'btn' => 'bg-yellow-600 hover:bg-yellow-700'],
    ][$c] ?? ['bg' => 'bg-slate-50', 'border' => 'border-slate-200', 'text' => 'text-slate-700', 'btn' => 'bg-slate-600'];
}

$flash_ok = $_SESSION['flash_ok'] ?? '';
unset($_SESSION['flash_ok']);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Learning Journey · <?= htmlspecialchars($child['name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  .heading-fun { font-family: 'Fredoka', system-ui, sans-serif; font-weight: 700; }
</style>
</head>
<body class="bg-slate-50 min-h-screen">

<header class="bg-white border-b border-slate-200 sticky top-0 z-10 shadow-sm">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between flex-wrap gap-3">
    <a href="/dashboard.php" class="flex items-center gap-2">
      <span class="text-slate-500">←</span>
      <span class="font-bold text-slate-900 text-sm">Dashboard</span>
    </a>
    <?php if (count($children) > 1): ?>
      <form method="GET" class="m-0">
        <select name="cid" onchange="this.form.submit()" class="text-sm border border-slate-300 rounded-lg px-3 py-1.5">
          <?php foreach ($children as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $cid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-6 space-y-5">

  <?php if ($flash_ok): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-xl p-3 text-sm">
      <?= htmlspecialchars($flash_ok) ?>
    </div>
  <?php endif; ?>

  <!-- Child header card -->
  <section class="bg-gradient-to-br from-emerald-600 to-teal-600 text-white rounded-2xl p-5 shadow-lg">
    <div class="flex items-center gap-4 flex-wrap">
      <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center text-2xl font-bold">
        <?= htmlspecialchars(mb_substr($child['name'], 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <h1 class="text-2xl heading-fun leading-tight"><?= htmlspecialchars($child['name']) ?></h1>
        <p class="text-sm text-emerald-100">
          <?= $age ?> years · <?= $is_self_led ? 'self-led' : 'parent-guided' ?>
        </p>
      </div>
      <div class="text-right">
        <div class="text-3xl font-bold"><?= $baselines_done ?>/10</div>
        <div class="text-xs text-emerald-100">modules done</div>
      </div>
    </div>

    <?php if ($has_unlock): ?>
      <div class="mt-4 bg-white/20 rounded-lg p-3 text-sm">
        ✅ <strong>Child Package active</strong> · Day <?= $days_in ?> of 14 · all modules unlocked
      </div>
    <?php elseif ($has_free): ?>
      <div class="mt-4 bg-white/20 rounded-lg p-3 text-sm">
        🎁 Free trial: <strong><?= htmlspecialchars(_ca_label($free_trial['module'])) ?></strong> · other modules locked. <a href="#unlockSection" class="underline">Unlock all 10 →</a>
      </div>
    <?php else: ?>
      <div class="mt-4 bg-white/20 rounded-lg p-3 text-sm">
        🎁 <strong>One free evaluation</strong> for <?= htmlspecialchars($child['name']) ?> — pick your module below.
      </div>
    <?php endif; ?>
  </section>

  <!-- The "choose your free module" picker (shown only if no pick yet AND no unlock) -->
  <?php if (!$has_free && !$has_unlock): ?>
    <section class="bg-amber-50 border-2 border-amber-300 rounded-2xl p-5">
      <div class="flex items-start gap-3 mb-3">
        <div class="text-3xl">🎁</div>
        <div>
          <h2 class="heading-fun text-lg text-amber-900">Pick your free evaluation</h2>
          <p class="text-sm text-amber-800 mt-1">
            Choose ONE module to try free. The other 9 unlock with the Child Package (₹<?= $unlock_price ?>, 14 days).
          </p>
        </div>
      </div>
      <form method="POST" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mt-4">
        <input type="hidden" name="action" value="pick_free">
        <?php foreach ($modules_def as $key => $m): ?>
          <button type="submit" name="module" value="<?= htmlspecialchars($key) ?>"
                  class="bg-white hover:bg-amber-100 hover:border-amber-400 border-2 border-amber-200 rounded-xl p-3 text-center transition"
                  onclick="return confirm('Set <?= htmlspecialchars($m['label']) ?> as your free trial? You can\'t change this later.')">
            <div class="text-2xl mb-1"><?= $m['emoji'] ?></div>
            <div class="text-xs font-bold text-slate-800"><?= htmlspecialchars($m['label']) ?></div>
          </button>
        <?php endforeach; ?>
      </form>
      <p class="text-xs text-amber-700 mt-3 text-center italic">
        ⚡ Tip: pick the module you're MOST curious about. Once picked, this is locked in.
      </p>
    </section>
  <?php endif; ?>

  <!-- Modules grid -->
  <section>
    <h2 class="heading-fun text-lg text-slate-900 mb-3">10 Evaluations for <?= htmlspecialchars($child['name']) ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($modules_def as $key => $m):
        $s    = $modules_state[$key];
        $cl   = color_classes($m['color']);
        $done = !empty($s);
        $is_free_pick = ($free_trial['module'] === $key);
        $can_eval = $has_unlock || $is_free_pick || !$has_free;   // unlock OR is your pick OR you haven't picked yet
        $score = $done ? (int)round((float)$s['score']) : 0;

        // CTA URL
        $eval_url = $m['eval_url'] . (strpos($m['eval_url'], '?') === false ? '?' : '&') . 'cid=' . $cid;
      ?>
        <div class="bg-white border <?= $cl['border'] ?> rounded-2xl p-4 flex flex-col">
          <div class="flex items-start gap-3 mb-3">
            <div class="text-2xl"><?= $m['emoji'] ?></div>
            <div class="flex-1 min-w-0">
              <h3 class="font-bold text-slate-900 leading-tight"><?= htmlspecialchars($m['label']) ?></h3>
              <p class="text-[11px] <?= $cl['text'] ?>"><?= htmlspecialchars($m['label_hi']) ?></p>
              <p class="text-xs text-slate-500 mt-0.5 leading-snug"><?= htmlspecialchars($m['one_liner']) ?></p>
            </div>
            <?php if ($done): ?>
              <span class="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap">✓ DONE</span>
            <?php elseif ($is_free_pick): ?>
              <span class="bg-amber-100 text-amber-700 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap">🎁 FREE</span>
            <?php elseif (!$has_unlock && $has_free): ?>
              <span class="bg-slate-100 text-slate-500 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap">🔒 LOCKED</span>
            <?php endif; ?>
          </div>

          <?php if ($done): ?>
            <div class="<?= $cl['bg'] ?> rounded-lg p-2 mb-3 text-xs flex justify-between items-center">
              <span>Last score</span>
              <strong class="<?= $cl['text'] ?> text-base"><?= $score ?>/100</strong>
            </div>
          <?php endif; ?>

          <?php if ($can_eval || $done): /* Done modules always show redo (gated separately) */ ?>
            <a href="<?= htmlspecialchars($eval_url) ?>" class="mt-auto block text-center py-2.5 px-3 <?= $cl['btn'] ?> text-white text-sm font-bold rounded-lg">
              <?= $done ? '🔄 Redo' : ($is_free_pick ? '▶ Start free' : '▶ Start evaluation') ?>
            </a>
          <?php else: ?>
            <a href="#unlockSection" class="mt-auto block text-center py-2.5 px-3 bg-slate-100 text-slate-500 text-sm font-bold rounded-lg">
              🔒 Locked
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Unlock CTA -->
  <?php if (!$has_unlock): ?>
    <section id="unlockSection" class="bg-gradient-to-br from-orange-50 via-amber-50 to-yellow-50 border-2 border-orange-300 rounded-2xl p-5 shadow-md">
      <div class="text-center mb-4">
        <div class="text-3xl mb-2">🔓</div>
        <h2 class="heading-fun text-xl text-orange-900">Unlock the full Child Package</h2>
        <p class="text-sm text-orange-800 mt-1 max-w-md mx-auto">
          One unlock covers <strong>all 10 modules</strong> — evaluations + daily-practice course — for 14 days.
        </p>
      </div>

      <div class="bg-white rounded-xl p-3 mb-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs">
        <div><div class="text-xl mb-1">🔓</div><strong>All 10 modules</strong></div>
        <div><div class="text-xl mb-1">🔄</div><strong>Unlimited redos</strong></div>
        <div><div class="text-xl mb-1">⏰</div><strong>7-day course</strong></div>
        <div><div class="text-xl mb-1">📈</div><strong>Progress charts</strong></div>
      </div>

      <div class="flex items-baseline justify-center gap-2 mb-3">
        <span class="text-4xl heading-fun text-orange-900">₹<?= number_format($unlock_price) ?></span>
        <span class="text-sm text-orange-700">· 14 days · all-in</span>
      </div>

      <form method="POST" action="/child-learn-unlock.php" class="m-0">
        <input type="hidden" name="cid" value="<?= $cid ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
        <button class="w-full py-3 px-6 bg-gradient-to-br from-orange-600 to-red-600 hover:from-orange-700 hover:to-red-700 text-white text-base font-bold rounded-xl shadow-lg">
          🔓 Unlock Child Package — ₹<?= number_format($unlock_price) ?>
        </button>
      </form>
      <p class="text-center text-xs text-orange-700 mt-2">
        Wallet: ₹<?= number_format($bal) ?> · <?= $bal >= $unlock_price ? 'Pay from wallet' : '<a href="/wallet.php" class="underline">Top up wallet first</a>' ?>
      </p>
    </section>
  <?php endif; ?>

  <p class="text-center text-xs text-slate-500 py-3">
    Need help? <a href="https://wa.me/919311883132" target="_blank" class="text-emerald-600 underline">WhatsApp +91-9311883132</a>
  </p>

</main>

</body>
</html>
