<?php
/**
 * /my-modules.php — top-level page listing every module the parent owns,
 * grouped by child, with last activity per module (last log / last assessment /
 * last consult / plan progress).
 *
 * Replaces the friction of "go to /catalogue.php and scroll for green Owned ✓".
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/wallet.php';

require_parent();
$parent = current_parent();

// Pull all children for this parent
$st = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC");
$st->execute([(int)$parent['id']]);
$children = $st->fetchAll();

// All catalogue rows (incl partials, so we can show "Coming soon" for paid-but-no-assessment ones)
$catalogue_rows = catalogue_modules(['include_partial' => true]);
$cat_by_key = [];
foreach ($catalogue_rows as $r) { $cat_by_key[$r['service_key']] = $r; }

// For each child, find every module they own + last activity
function activity_for_module(int $child_id, string $service_key): array {
    $alias_keys = function_exists('legacy_keys_for_catalogue')
        ? legacy_keys_for_catalogue($service_key) : [$service_key];
    $ph = implode(',', array_fill(0, count($alias_keys), '?'));

    // Last assessment
    $st = db()->prepare("SELECT MAX(completed_at) AS t FROM assessments
                         WHERE child_id = ? AND module IN ($ph) AND status = 'done'");
    $st->execute(array_merge([$child_id], $alias_keys));
    $last_assessment = $st->fetchColumn() ?: null;

    // Last consult
    $st = db()->prepare("SELECT MAX(created_at) AS t FROM module_consults
                         WHERE child_id = ? AND service_key = ?");
    $st->execute([$child_id, $service_key]);
    $last_consult = $st->fetchColumn() ?: null;

    // Last log entry that mentions this module
    $st = db()->prepare("SELECT log_date, module_fields_json FROM daily_logs
                         WHERE child_id = ? AND module_fields_json LIKE ?
                         ORDER BY log_date DESC LIMIT 1");
    $st->execute([$child_id, '%"' . $service_key . '"%']);
    $log_row = $st->fetch();
    $last_log = $log_row['log_date'] ?? null;

    // Plan exists?
    $st = db()->prepare("SELECT started_at, weeks FROM module_plans WHERE child_id = ? AND service_key = ?");
    $st->execute([$child_id, $service_key]);
    $plan = $st->fetch() ?: null;

    // Pick most recent of all
    $candidates = array_filter([$last_assessment, $last_consult, $last_log, $plan ? $plan['started_at'] : null]);
    $most_recent = !empty($candidates) ? max($candidates) : null;

    return [
        'last_assessment' => $last_assessment,
        'last_consult'    => $last_consult,
        'last_log'        => $last_log,
        'plan'            => $plan,
        'most_recent'     => $most_recent,
    ];
}

function child_owns_module(int $parent_id, int $child_id, string $service_key): bool {
    return module_owns($parent_id, $child_id, $service_key);
}

function format_when($when): string {
    if (!$when) return '—';
    $ts = strtotime($when);
    $days_ago = (int) floor((time() - $ts) / 86400);
    if ($days_ago === 0) return 'today';
    if ($days_ago === 1) return 'yesterday';
    if ($days_ago < 7) return $days_ago . ' days ago';
    return date('j M', $ts);
}

$page_title = 'My modules — Empower Students';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto px-4 py-8">

  <h1 class="text-2xl sm:text-3xl font-bold mb-2">📚 My modules</h1>
  <p class="text-slate-600 mb-8">Everything you've unlocked, organised by child.</p>

  <?php if (empty($children)): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-8 text-center">
      <p class="text-slate-700 mb-4">Add a child to start using your modules.</p>
      <a href="/add_child.php" class="inline-block brand-grad text-white font-semibold px-6 py-3 rounded-xl">+ Add a child</a>
    </div>
  <?php endif; ?>

  <?php foreach ($children as $child):
      $age = number_format(calc_age_years($child['dob']), 1);
      // Find owned modules for this child
      $owned_modules = [];
      foreach ($cat_by_key as $key => $row) {
          if ($row['catalogue_group'] === 'pack' || $row['catalogue_group'] === 'consult') continue;
          if (child_owns_module((int)$parent['id'], (int)$child['id'], $key)) {
              $owned_modules[$key] = $row;
          }
      }
  ?>

  <section class="mb-10">
    <div class="flex items-baseline justify-between flex-wrap gap-2 mb-4">
      <h2 class="text-xl font-bold text-slate-900">
        👶 <?= e($child['name']) ?>
        <span class="text-sm font-normal text-slate-500 ml-2"><?= e($age) ?> yrs</span>
      </h2>
      <span class="text-xs text-slate-500"><?= count($owned_modules) ?> module<?= count($owned_modules) === 1 ? '' : 's' ?> active</span>
    </div>

    <?php if (empty($owned_modules)): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-5">
        <p class="text-sm text-amber-900 mb-2">No modules unlocked for <?= e($child['name']) ?> yet.</p>
        <a href="/catalogue.php" class="text-sm font-semibold text-amber-700 underline">Browse the catalogue →</a>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($owned_modules as $key => $row):
            $act = activity_for_module((int)$child['id'], $key);
            $is_partial = (int)($row['assessment_ready'] ?? 1) === 0;
        ?>
          <a href="/module.php?key=<?= urlencode($key) ?>&cid=<?= (int)$child['id'] ?>"
             class="block bg-white border border-slate-200 rounded-2xl p-5 hover:border-indigo-300 hover:shadow-sm transition">
            <div class="flex items-start justify-between gap-3 mb-2">
              <div class="flex items-baseline gap-2">
                <span class="text-2xl"><?= e($row['icon'] ?? '📘') ?></span>
                <div>
                  <h3 class="text-base font-semibold text-slate-900 leading-tight"><?= e($row['label']) ?></h3>
                  <p class="text-xs text-slate-500 mt-0.5"><?= e(catalogue_group_label($row['catalogue_group'])) ?> · <?= e(tier_label($row['tier'])) ?></p>
                </div>
              </div>
              <?php if ($is_partial): ?>
                <span class="text-xs px-2 py-0.5 bg-amber-100 text-amber-800 border border-amber-200 rounded-full whitespace-nowrap">launching soon</span>
              <?php endif; ?>
            </div>

            <div class="text-xs text-slate-600 space-y-1 mb-3">
              <div class="flex justify-between">
                <span>📊 Assessment</span>
                <span class="text-slate-500"><?= e(format_when($act['last_assessment'])) ?></span>
              </div>
              <div class="flex justify-between">
                <span>📔 Last log</span>
                <span class="text-slate-500"><?= e(format_when($act['last_log'])) ?></span>
              </div>
              <div class="flex justify-between">
                <span>💬 Last consult</span>
                <span class="text-slate-500"><?= e(format_when($act['last_consult'])) ?></span>
              </div>
              <?php if ($act['plan']):
                  $weeks = max(1, (int)$act['plan']['weeks']);
                  $start = strtotime($act['plan']['started_at']);
                  $w = min($weeks, max(1, (int) floor((time() - $start) / (7 * 86400)) + 1));
              ?>
                <div class="flex justify-between">
                  <span>🗓️ Plan</span>
                  <span class="text-slate-500">Week <?= $w ?> of <?= $weeks ?></span>
                </div>
              <?php endif; ?>
            </div>

            <div class="text-xs font-semibold text-indigo-600">Open module →</div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php endforeach; ?>

  <div class="text-center mt-8 mb-4">
    <a href="/catalogue.php" class="text-sm text-indigo-600 hover:underline">+ Add another module</a>
  </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
