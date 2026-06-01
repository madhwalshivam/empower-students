<?php
/**
 * growth_plan.php — view the AI growth plan from the Care Pack
 *
 * URL: /growth_plan.php?id=<child_id>
 *
 * Shows the existing plan (generated when the parent bought the Care Pack).
 * If no Care Pack, redirects to /care_pack.php to buy.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/paid_schema.php';
require_parent();

$parent = current_parent();
$child  = child_for_parent((int)($_GET['id'] ?? 0));
if (!$child) { header('Location: /dashboard.php'); exit; }

$pack = care_pack_for((int)$parent['id'], (int)$child['id']);
if (!$pack) {
    header('Location: /care_pack.php?id=' . (int)$child['id']); exit;
}

$st = db()->prepare("SELECT * FROM growth_plans WHERE child_id=?");
$st->execute([$child['id']]);
$plan = $st->fetch();

if (!$plan) {
    // Edge case: care pack exists but plan generation failed silently.
    // Send back to care_pack.php which will detect existing pack and offer regen.
    header('Location: /care_pack.php?id=' . (int)$child['id'] . '&view=1'); exit;
}

// Tracker days remaining (drives the cross-sell card)
$tracker_days = tracker_days_remaining((int)$child['id']);

// Has personal course? (it should — co-generated with the plan)
$pc_st = db()->prepare("SELECT pc.*, COUNT(pl.id) AS lesson_count, SUM(CASE WHEN pl.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS done_count FROM personal_courses pc LEFT JOIN personal_lessons pl ON pl.course_id=pc.id WHERE pc.child_id=? GROUP BY pc.id");
$pc_st->execute([$child['id']]);
$pc = $pc_st->fetch();

// Simple markdown renderer
if (!function_exists('_md_to_html')) {
function _md_to_html(string $md): string {
    $h = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/^### (.+)$/m', '<h3 class="text-base font-bold mt-4 mb-1">$1</h3>', $h);
    $h = preg_replace('/^## (.+)$/m',  '<h2 class="text-lg font-bold mt-5 mb-2">$1</h2>', $h);
    $h = preg_replace('/^# (.+)$/m',   '<h1 class="text-2xl font-bold mt-6 mb-3">$1</h1>', $h);
    $h = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $h);
    $h = preg_replace('/_([^_]+)_/', '<em>$1</em>', $h);
    $h = preg_replace('/^- (.+)$/m', '<li>$1</li>', $h);
    $h = preg_replace('/(<li>.*<\/li>(\n|$))+/s', '<ul class="list-disc pl-5 space-y-1 my-2">$0</ul>', $h);
    $h = preg_replace('/\n\n+/', '</p><p class="mb-3">', $h);
    return '<p class="mb-3">' . $h . '</p>';
}
}

$page_title = 'Growth Plan — ' . $child['name'];
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
  <a href="/child.php?id=<?= (int)$child['id'] ?>" class="text-sm text-indigo-600">← <?= e($child['name']) ?></a>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-4 my-4 text-sm">
      <?= e($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <div class="bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500 rounded-3xl p-8 text-white mt-3 mb-6 shadow-xl relative overflow-hidden">
    <div class="absolute top-4 right-4 bg-white/20 px-3 py-1 rounded-full text-xs font-bold">CARE PACK · ACTIVE</div>
    <p class="opacity-90 text-sm">For <?= e($child['name']) ?></p>
    <h1 class="text-3xl font-bold mt-1 mb-2">🌱 Growth Plan</h1>
    <p class="opacity-90 max-w-xl">Personalised from <?= e($child['name']) ?>'s assessment. Activated <?= e(date('d M Y', strtotime($pack['purchased_at']))) ?>.</p>
  </div>

  <div class="bg-white rounded-2xl shadow border border-slate-200 p-6 sm:p-10 mb-6">
    <?= _md_to_html($plan['plan_text']) ?>
  </div>

  <!-- Cross-sell row -->
  <div class="grid sm:grid-cols-2 gap-4">

    <a href="/course.php?id=<?= (int)$child['id'] ?>" class="bg-gradient-to-br from-amber-500 to-orange-600 rounded-2xl p-6 text-white hover:scale-[1.02] transition-transform shadow-lg block">
      <div class="text-3xl mb-2">📚</div>
      <h3 class="font-bold text-lg mb-1"><?= e($child['name']) ?>'s Personal Course</h3>
      <?php if ($pc): ?>
        <p class="text-sm opacity-90 mb-2"><?= (int)$pc['done_count'] ?>/<?= (int)$pc['lesson_count'] ?> lessons complete</p>
        <span class="text-xs bg-white/20 px-2 py-1 rounded-full">Continue learning →</span>
      <?php else: ?>
        <p class="text-sm opacity-90">Five lessons designed for your child →</p>
      <?php endif; ?>
    </a>

    <a href="/tracker.php?id=<?= (int)$child['id'] ?>" class="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-6 text-white hover:scale-[1.02] transition-transform shadow-lg block">
      <div class="text-3xl mb-2">📊</div>
      <h3 class="font-bold text-lg mb-1">Daily Tracker</h3>
      <p class="text-sm opacity-90 mb-2"><?= $tracker_days ?> days left</p>
      <span class="text-xs bg-white/20 px-2 py-1 rounded-full">Log today →</span>
    </a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php';
