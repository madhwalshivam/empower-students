<?php
/**
 * admin/isaa-test.php
 *
 * Admin testing page for the ISAA assessment tool. Lets you (signed in as
 * admin) start a test ISAA for any child, fill the 40-item form, and view
 * the generated report — bypassing the partner onboarding flow.
 *
 * Test assessments have partner_id = NULL (admin-conducted, no payout).
 */

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/auth.php';        // for calc_age_years()
require_once __DIR__ . '/../includes/catalogue.php';
require_once __DIR__ . '/../includes/partner_auth.php';
require_once __DIR__ . '/../includes/isaa_helpers.php';

// All children (with parent name) for picker
$children = db()->query("
    SELECT c.id, c.name, c.dob, c.gender, c.parent_id,
           p.name AS parent_name, p.whatsapp AS parent_whatsapp
    FROM children c
    LEFT JOIN parents p ON p.id = c.parent_id
    ORDER BY c.id DESC
    LIMIT 50
")->fetchAll();

// Admin-test assessments (partner_id IS NULL) — most recent first
$admin_assessments = db()->query("
    SELECT a.*, c.name AS child_name, c.dob AS child_dob,
           p.name AS parent_name
    FROM isaa_assessments a
    JOIN children c ON c.id = a.child_id
    LEFT JOIN parents p ON p.id = a.parent_id
    WHERE a.partner_id IS NULL
    ORDER BY a.id DESC
    LIMIT 20
")->fetchAll();

$page_title = 'ISAA — Admin Test';
admin_layout_open($page_title);
admin_render_flash();
?>

  <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap">
    <h1 class="text-2xl font-bold text-slate-900">🧠 ISAA — Admin Test</h1>
    <a href="/admin/index.php" class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Admin home</a>
  </div>

  <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-900 mb-6">
    <strong>Admin testing mode.</strong>
    From here you can start an ISAA assessment for any child without going through partner onboarding.
    Test assessments are flagged with <code>partner_id = NULL</code> and don't generate partner payouts.
    Use this to validate the form flow and the AI report quality before testing the full partner workflow.
  </div>

  <?php if (!empty($admin_assessments)): ?>
    <h2 class="text-lg font-bold text-slate-900 mb-2">Your test assessments</h2>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-6">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
          <tr>
            <th class="text-left px-3 py-2">Child</th>
            <th class="text-left px-3 py-2">Parent</th>
            <th class="text-left px-3 py-2">Status</th>
            <th class="text-left px-3 py-2">Score</th>
            <th class="text-left px-3 py-2">Started</th>
            <th class="text-left px-3 py-2 text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($admin_assessments as $a):
              $age = round((float)calc_age_years($a['child_dob']), 1);
              $items_done = (int) db()->query("SELECT COUNT(*) FROM isaa_responses WHERE assessment_id = " . (int)$a['id'])->fetchColumn();
          ?>
            <tr class="border-t border-slate-100">
              <td class="px-3 py-2">
                <div class="font-semibold text-slate-900"><?= e($a['child_name']) ?></div>
                <div class="text-xs text-slate-500"><?= $age ?> yrs</div>
              </td>
              <td class="px-3 py-2 text-slate-700"><?= e($a['parent_name'] ?? '—') ?></td>
              <td class="px-3 py-2">
                <?php if ($a['status'] === 'submitted'): ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">✓ Submitted</span>
                <?php elseif ($a['status'] === 'in_progress'): ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700">In progress (<?= $items_done ?>/40)</span>
                <?php elseif ($a['status'] === 'cancelled'): ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">Cancelled</span>
                <?php else: ?>
                  <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-700"><?= e($a['status']) ?></span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-xs">
                <?php if ($a['status'] === 'submitted'): ?>
                  <span class="font-bold text-slate-900"><?= (int)$a['total_score'] ?>/200</span>
                  <span class="text-slate-500"><?= e(isaa_category_label((string)$a['category'])) ?></span>
                <?php else: ?>
                  <span class="text-slate-400">—</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-xs text-slate-500"><?= e(substr((string)($a['started_at'] ?? $a['paid_at'] ?? ''), 0, 16)) ?></td>
              <td class="px-3 py-2 text-right">
                <?php if ($a['status'] === 'submitted'): ?>
                  <a href="/partner-isaa-view.php?id=<?= (int)$a['id'] ?>"
                     class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded hover:bg-emerald-700">
                    📋 View report
                  </a>
                <?php elseif ($a['status'] === 'in_progress' || $a['status'] === 'paid'): ?>
                  <a href="/partner-isaa.php?id=<?= (int)$a['id'] ?>"
                     class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">
                    ↻ Continue
                  </a>
                <?php else: ?>
                  <span class="text-xs text-slate-400">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h2 class="text-lg font-bold text-slate-900 mb-2">Start new test ISAA</h2>
  <p class="text-sm text-slate-600 mb-3">Pick any child to start a fresh ISAA assessment. (If a child already has an active or submitted ISAA, you'll be taken to that one instead.)</p>

  <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
        <tr>
          <th class="text-left px-3 py-2">Child</th>
          <th class="text-left px-3 py-2">DOB / Age</th>
          <th class="text-left px-3 py-2">Parent</th>
          <th class="text-left px-3 py-2">WhatsApp</th>
          <th class="text-left px-3 py-2 text-right">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($children as $c):
            $age = round((float)calc_age_years($c['dob']), 1);
            // Check existing assessment
            $st = db()->prepare("SELECT id, status FROM isaa_assessments
                                 WHERE child_id = ? AND status IN ('paid','in_progress','submitted')
                                 ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$c['id']]);
            $existing = $st->fetch();
        ?>
          <tr class="border-t border-slate-100">
            <td class="px-3 py-2">
              <div class="font-semibold text-slate-900"><?= e($c['name']) ?></div>
              <div class="text-xs text-slate-500">
                <?= e($c['gender'] ?: '—') ?> · child #<?= (int)$c['id'] ?>
              </div>
            </td>
            <td class="px-3 py-2 text-xs text-slate-600">
              <?= e($c['dob']) ?><br>
              <span class="text-slate-500"><?= $age ?> yrs</span>
            </td>
            <td class="px-3 py-2 text-slate-700"><?= e($c['parent_name'] ?? '—') ?></td>
            <td class="px-3 py-2 text-xs text-slate-500 font-mono"><?= e($c['parent_whatsapp'] ?? '—') ?></td>
            <td class="px-3 py-2 text-right">
              <?php if ($existing && $existing['status'] === 'submitted'): ?>
                <a href="/partner-isaa-view.php?id=<?= (int)$existing['id'] ?>"
                   class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded hover:bg-emerald-700">
                  📋 View existing report
                </a>
              <?php elseif ($existing): ?>
                <a href="/partner-isaa.php?id=<?= (int)$existing['id'] ?>"
                   class="text-xs bg-amber-600 text-white px-3 py-1.5 rounded hover:bg-amber-700">
                  ↻ Continue (<?= e($existing['status']) ?>)
                </a>
              <?php else: ?>
                <form method="post" action="/partner-isaa.php" class="m-0 inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="create_for_child">
                  <input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>">
                  <button class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">
                    ▶ Start test ISAA
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($children)): ?>
          <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500 italic">
            No children registered yet.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="text-xs text-slate-500 mt-4">
    💡 <strong>Tip:</strong> The form has 40 items, one per page. To test quickly, just click any radio + Save & Next 40 times.
    The review screen lets you submit; the AI report takes ~15-25 seconds to generate.
  </p>

<?php admin_layout_close(); ?>
