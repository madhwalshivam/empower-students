<?php
/**
 * /catalogue.php — parent-facing browseable module catalogue.
 *
 * Public access (no parent login required to browse). Buying requires login.
 * Auto-filters by selected child's age if logged in.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalogue.php';

// Optional partner attribution if present
if (file_exists(__DIR__ . '/includes/partner_capture.php')) {
    require_once __DIR__ . '/includes/partner_capture.php';
    if (function_exists('partner_capture_from_url')) partner_capture_from_url();
}

$page_title = 'Module Catalogue — Empower Students';

// Optional child context (if a parent is logged in and has children)
$child = null;
$age   = null;
$parent = current_parent();
if ($parent) {
    $cid_in = (int)($_GET['cid'] ?? 0);
    if ($cid_in > 0) {
        $child = child_for_parent($cid_in);
    } else {
        $st = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
        $st->execute([(int)$parent['id']]);
        $child = $st->fetch() ?: null;
    }
    if ($child) $age = calc_age_years($child['dob']);
}

$filter_group = $_GET['g'] ?? '';
$filter_tier  = $_GET['t'] ?? '';

$filters = [];
if ($filter_group !== '') $filters['group'] = $filter_group;
if ($age !== null && empty($_GET['all_ages'])) $filters['age'] = $age;

$rows = catalogue_modules($filters);

// Group rows for display
$grouped = ['pack' => [], 'special' => [], 'all' => [], 'parent' => [], 'consult' => []];
foreach ($rows as $r) {
    if ($filter_tier !== '' && $r['tier'] !== $filter_tier) continue;
    $g = $r['catalogue_group'] ?: 'all';
    if (!isset($grouped[$g])) $grouped[$g] = [];
    $grouped[$g][] = $r;
}

require __DIR__ . '/includes/header.php';
?>

<style>
  .cat-card {
    background: #fff;
    border: 1px solid rgb(226, 232, 240);
    border-radius: 1rem;
    padding: 1.25rem;
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
    display: flex;
    flex-direction: column;
    height: 100%;
  }
  .cat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px -16px rgba(0,0,0,0.15);
    border-color: rgb(199, 210, 254);
  }
  .cat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
  }
  .cat-icon {
    font-size: 2rem;
    line-height: 1;
    margin-bottom: 0.5rem;
  }
  .cat-tier-badge {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    border-width: 1px;
    border-style: solid;
  }
  .cat-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: rgb(15, 23, 42);
  }
  .cat-pill-link {
    display: inline-block;
    padding: 0.4rem 0.9rem;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid rgb(226, 232, 240);
    background: #fff;
    color: rgb(51, 65, 85);
    margin-right: 0.4rem;
    margin-bottom: 0.4rem;
    text-decoration: none;
  }
  .cat-pill-link.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-color: transparent;
  }
</style>

<div class="max-w-6xl mx-auto px-4 py-6">

  <header class="mb-6">
    <h1 class="text-2xl sm:text-3xl font-bold mb-1" data-i18n="cat.heading">
      Choose what to work on
    </h1>
    <p class="text-slate-600 max-w-2xl">
      <?php if ($child && $age !== null): ?>
        Showing modules matched to <strong><?= e($child['name']) ?></strong> (<?= number_format($age, 1) ?> yrs).
        <a href="?all_ages=1<?= $filter_group ? '&g=' . e($filter_group) : '' ?>" class="text-indigo-600 hover:underline">See all ages</a>.
      <?php else: ?>
        Pay only for what you need. Each module has its own assessment, AI report, and personalised plan.
      <?php endif; ?>
    </p>
  </header>

  <!-- Filter pills -->
  <div class="mb-6">
    <a class="cat-pill-link <?= $filter_group === '' ? 'active' : '' ?>" href="<?= e('catalogue.php' . ($child ? '?cid=' . (int)$child['id'] : '')) ?>">All</a>
    <?php foreach (['pack', 'special', 'all', 'parent', 'consult'] as $g): ?>
      <a class="cat-pill-link <?= $filter_group === $g ? 'active' : '' ?>"
         href="?g=<?= e($g) ?><?= $child ? '&cid=' . (int)$child['id'] : '' ?>"><?= e(catalogue_group_label($g)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php
  // Discount banner
  if ($filter_group === '' || $filter_group === 'all' || $filter_group === 'special'): ?>
  <div class="mb-6 bg-gradient-to-r from-amber-50 to-rose-50 border border-amber-200 rounded-xl p-4 text-sm">
    <strong class="text-amber-900">💡 Bundle and save —</strong>
    <span class="text-slate-700">Add 2 modules to cart for 10% off, 3 for 20% off, 5+ for 30% off. Stacks with the existing bundles below.</span>
  </div>
  <?php endif; ?>

  <?php
  // Render in fixed group order so the page reads consistently
  $group_order = ['pack', 'special', 'all', 'parent', 'consult'];
  foreach ($group_order as $g):
      if (empty($grouped[$g])) continue;
  ?>
    <section class="mb-10">
      <h2 class="text-lg font-semibold mb-3"><?= e(catalogue_group_label($g)) ?></h2>
      <div class="cat-grid">
        <?php foreach ($grouped[$g] as $m):
          $owned = ($parent && $child) ? module_owns((int)$parent['id'], (int)$child['id'], $m['service_key']) : false;
          $detail_href = '/module.php?key=' . urlencode($m['service_key']) . ($child ? '&cid=' . (int)$child['id'] : '');
        ?>
          <a href="<?= e($detail_href) ?>" class="cat-card no-underline text-current block">
            <div class="flex items-start justify-between mb-2">
              <div class="cat-icon"><?= e($m['icon'] ?: '📦') ?></div>
              <span class="cat-tier-badge <?= e(tier_badge_class($m['tier'] ?? '')) ?>">
                <?= e(tier_label($m['tier'] ?? '')) ?>
              </span>
            </div>
            <h3 class="font-semibold text-slate-900 mb-1 leading-snug"><?= e($m['label']) ?></h3>
            <p class="text-sm text-slate-600 mb-4 flex-grow"><?= e($m['short_desc'] ?: '') ?></p>
            <div class="flex items-center justify-between mt-auto pt-3 border-t border-slate-100">
              <div>
                <span class="cat-price">₹<?= number_format((int)$m['price']) ?></span>
                <?php if ((int)$m['plan_weeks'] > 0): ?>
                  <span class="text-xs text-slate-500 ml-1"><?= (int)$m['plan_weeks'] ?>-wk plan</span>
                <?php endif; ?>
              </div>
              <?php if ($owned): ?>
                <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">✓ Owned</span>
              <?php else: ?>
                <span class="text-xs text-indigo-600 font-semibold">View →</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if (empty($rows)): ?>
    <div class="text-center py-20 text-slate-500">
      <p class="text-3xl mb-3">🔍</p>
      <p>No modules match those filters.</p>
      <a href="catalogue.php" class="text-indigo-600 hover:underline">Clear filters</a>
    </div>
  <?php endif; ?>

  <!-- Tracker top-up reminder -->
  <section class="mt-10 bg-slate-50 border border-slate-200 rounded-xl p-5 text-sm text-slate-700">
    <strong>📔 Daily tracker top-up</strong> — already have a Care Pack or module?
    Add another <strong>30 days</strong> of daily logging for <strong>₹149</strong>.
    <a href="/wallet.php" class="text-indigo-600 hover:underline ml-1">Top up →</a>
  </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
