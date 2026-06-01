<?php
/**
 * partner-isaa-queue.php
 *
 * Lists ISAA assessments assigned to the logged-in partner (status='paid' or
 * 'in_progress'). Partner clicks one to start the 40-item form.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/auth.php';        // for calc_age_years()
require_once __DIR__ . '/includes/partner_auth.php';

$partner = require_partner();

if ((int)$partner['can_administer_isaa'] !== 1) {
    http_response_code(403);
    require __DIR__ . '/includes/header.php';
    echo '<main class="max-w-2xl mx-auto px-4 py-10"><div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-amber-900">';
    echo '<h1 class="text-xl font-bold mb-2">Not certified for ISAA</h1>';
    echo '<p>Your partner account is not yet authorised to administer ISAA assessments. Please contact admin to request certification.</p>';
    echo '<p class="mt-3"><a href="/partner-dashboard.php" class="text-indigo-600 hover:underline">← Back to dashboard</a></p>';
    echo '</div></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = 'ISAA Queue — Partner Dashboard';

// Pull pending assessments for this partner: 'paid' (not yet started) and 'in_progress'
$queue = db()->prepare("
    SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
           p.name AS parent_name, p.whatsapp AS parent_whatsapp
    FROM isaa_assessments a
    JOIN children c ON c.id = a.child_id
    LEFT JOIN parents p ON p.id = a.parent_id
    WHERE a.partner_id = ? AND a.status IN ('paid','in_progress')
    ORDER BY a.paid_at DESC, a.id DESC
");
$queue->execute([(int)$partner['id']]);
$rows = $queue->fetchAll();

// Also pull children registered by this partner who DON'T have an assessment yet
// (so partner can offer to start one if parent has paid manually / via partner)
$ready_kids = db()->prepare("
    SELECT c.id, c.name, c.dob, c.gender,
           p.name AS parent_name, p.whatsapp AS parent_whatsapp
    FROM children c
    LEFT JOIN parents p ON p.id = c.parent_id
    WHERE c.registered_by_partner_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM isaa_assessments a
          WHERE a.child_id = c.id AND a.status IN ('paid','in_progress','submitted')
      )
");
$ready_kids->execute([(int)$partner['id']]);
$ready_rows = $ready_kids->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<main class="max-w-5xl mx-auto px-4 py-8">

  <div class="flex items-baseline justify-between gap-3 mb-6 flex-wrap">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">ISAA Assessment Queue</h1>
      <p class="text-slate-600 text-sm">Assessments paid for and assigned to you.</p>
    </div>
    <a href="/partner-dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Dashboard</a>
  </div>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm mb-4">
      <?= e($_SESSION['flash_ok']) ?>
    </div>
    <?php unset($_SESSION['flash_ok']); ?>
  <?php endif; ?>

  <?php if (empty($rows)): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-6 text-center text-slate-500 mb-6">
      <p class="mb-2">📭 No pending ISAA assessments right now.</p>
      <p class="text-xs">When a parent of a child you registered (or one assigned to you by admin) buys an ISAA assessment, it'll appear here.</p>
    </div>
  <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-6">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
          <tr>
            <th class="text-left px-3 py-2">Child</th>
            <th class="text-left px-3 py-2">Parent</th>
            <th class="text-left px-3 py-2">Status</th>
            <th class="text-left px-3 py-2">Paid</th>
            <th class="text-left px-3 py-2 text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $age = round((float)calc_age_years($r['child_dob']), 1);
              $is_in_progress = ($r['status'] === 'in_progress');
              $items_done = (int) db()->query("SELECT COUNT(*) FROM isaa_responses WHERE assessment_id = " . (int)$r['id'])->fetchColumn();
          ?>
            <tr class="border-t border-slate-100">
              <td class="px-3 py-2">
                <div class="font-semibold text-slate-900"><?= e($r['child_name']) ?></div>
                <div class="text-xs text-slate-500"><?= $age ?> yrs · <?= e($r['child_gender'] ?: '—') ?></div>
              </td>
              <td class="px-3 py-2">
                <div class="text-slate-700"><?= e($r['parent_name'] ?? '—') ?></div>
                <div class="text-xs text-slate-500 font-mono"><?= e($r['parent_whatsapp'] ?? '—') ?></div>
              </td>
              <td class="px-3 py-2">
                <?php if ($is_in_progress): ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700">In progress (<?= $items_done ?>/40)</span>
                <?php else: ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">Paid · ready</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-xs text-slate-500"><?= e(substr((string)($r['paid_at'] ?? $r['started_at'] ?? ''), 0, 10)) ?></td>
              <td class="px-3 py-2 text-right">
                <a href="/partner-isaa.php?id=<?= (int)$r['id'] ?>"
                   class="brand-grad text-white text-sm font-semibold px-4 py-1.5 rounded hover:opacity-90 inline-block">
                  <?= $is_in_progress ? '↻ Continue' : '▶ Start' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($ready_rows)): ?>
    <h2 class="text-lg font-bold text-slate-900 mb-2 mt-8">Children registered by you (no assessment yet)</h2>
    <p class="text-sm text-slate-600 mb-3">These children don't have a paid ISAA assessment yet. The parent needs to buy one from the catalogue, or admin can create one manually.</p>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
          <tr>
            <th class="text-left px-3 py-2">Child</th>
            <th class="text-left px-3 py-2">Parent</th>
            <th class="text-left px-3 py-2 text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ready_rows as $r):
              $age = round((float)calc_age_years($r['dob']), 1);
          ?>
            <tr class="border-t border-slate-100">
              <td class="px-3 py-2">
                <div class="font-semibold text-slate-900"><?= e($r['name']) ?></div>
                <div class="text-xs text-slate-500"><?= $age ?> yrs</div>
              </td>
              <td class="px-3 py-2 text-slate-700"><?= e($r['parent_name'] ?? '—') ?></td>
              <td class="px-3 py-2 text-right">
                <form method="post" action="/partner-isaa.php" class="m-0 inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="create_for_child">
                  <input type="hidden" name="child_id" value="<?= (int)$r['id'] ?>">
                  <button class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">
                    + Start ISAA for this child
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-500 mt-2">
      Starting an ISAA from here creates an assessment in your queue immediately (admin will reconcile billing). Use this when you've already collected payment from the parent offline.
    </p>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
