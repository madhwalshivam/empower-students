<?php
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/expert_report.php';
ensure_referral_schema();
ensure_expert_report_text_columns();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'add_note') {
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($id && $notes !== '') {
            db()->prepare("UPDATE expert_report_orders
                           SET admin_notes = COALESCE(admin_notes,'') || ?
                           WHERE id = ?")
               ->execute(["\n[" . date('Y-m-d H:i') . "] " . admin_user() . ": " . $notes, $id]);
            flash('Note added.', 'emerald');
        }
        header('Location: /admin/expert_orders.php');
        exit;
    }
}

$orders = db()->query("
    SELECT o.*, p.name AS parent_name, p.whatsapp, c.name AS child_name, c.dob
      FROM expert_report_orders o
      JOIN parents p ON p.id = o.parent_id
      JOIN children c ON c.id = o.child_id
     ORDER BY (o.status = 'pending') DESC, o.id DESC
")->fetchAll();

$pending_count   = count(array_filter($orders, fn($o) => $o['status'] === 'pending'));
$delivered_count = count(array_filter($orders, fn($o) => $o['status'] === 'delivered'));

admin_layout_open('Expert report orders');
admin_render_flash();
?>

<div class="grid grid-cols-3 gap-3 mb-4">
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-amber-700">Pending — needs report</div>
    <div class="text-2xl font-bold text-amber-900"><?= $pending_count ?></div>
  </div>
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-emerald-700">Delivered</div>
    <div class="text-2xl font-bold text-emerald-900"><?= $delivered_count ?></div>
  </div>
  <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-slate-700">Total orders</div>
    <div class="text-2xl font-bold text-slate-900"><?= count($orders) ?></div>
  </div>
</div>

<?php if (!$orders): ?>
  <p class="text-slate-500">No expert report orders yet.</p>
<?php else: ?>
  <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left">
        <tr>
          <th class="px-3 py-2">#</th>
          <th>Parent</th>
          <th>Child</th>
          <th>Source</th>
          <th>Status</th>
          <th>Ordered</th>
          <th>Report</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o):
          $has_text = !empty($o['report_text']);
          $row_bg = $o['status'] === 'pending' ? 'bg-amber-50' : '';
        ?>
          <tr class="border-t border-slate-100 <?= $row_bg ?>">
            <td class="px-3 py-2 font-mono text-xs"><?= (int)$o['id'] ?></td>
            <td>
              <div class="font-medium"><?= e($o['parent_name'] ?: '—') ?></div>
              <a class="text-xs text-emerald-700" href="https://wa.me/<?= e(preg_replace('/\D/','',$o['whatsapp'])) ?>" target="_blank"><?= e($o['whatsapp']) ?></a>
            </td>
            <td>
              <?= e($o['child_name']) ?>
              <div class="text-xs text-slate-500">DOB <?= e($o['dob']) ?></div>
            </td>
            <td>
              <?= $o['source'] === 'paid'
                  ? '<span class="text-xs bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded">💳 Paid</span>'
                  : '<span class="text-xs bg-purple-100 text-purple-800 px-2 py-0.5 rounded">🎁 Referral</span>' ?>
              <?php if ($o['source'] === 'paid'): ?>
                <div class="text-xs text-slate-500"><?= (int)$o['amount_paid'] ?> cr</div>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $tone = $o['status'] === 'pending'   ? 'bg-amber-100 text-amber-800'
                      : ($o['status'] === 'delivered' ? 'bg-emerald-100 text-emerald-800'
                      : 'bg-slate-100 text-slate-800');
              ?>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs <?= $tone ?>">
                <?= e($o['status']) ?>
              </span>
            </td>
            <td class="text-xs text-slate-500"><?= e(substr($o['ordered_at'], 0, 16)) ?></td>
            <td>
              <a href="/admin/expert_report_edit.php?order_id=<?= (int)$o['id'] ?>"
                 class="inline-block px-3 py-1.5 rounded-lg text-xs font-medium <?= $has_text
                    ? ($o['status'] === 'delivered' ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-indigo-600 text-white hover:bg-indigo-700')
                    : 'bg-rose-600 text-white hover:bg-rose-700' ?>">
                <?= $has_text
                    ? ($o['status'] === 'delivered' ? '✓ View / re-edit' : '✏️ Continue draft')
                    : '✨ Write report' ?>
              </a>
            </td>
          </tr>
          <?php if (!empty($o['admin_notes'])): ?>
            <tr class="border-t border-slate-50 <?= $row_bg ?>">
              <td colspan="7" class="px-3 py-1 text-xs text-slate-500">
                <details>
                  <summary class="cursor-pointer">Notes</summary>
                  <div class="whitespace-pre-line mt-1 pl-3"><?= e(trim($o['admin_notes'])) ?></div>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<p class="text-xs text-slate-400 mt-4">
  Click <strong>✨ Write report</strong> to enter the report workshop. You can generate an AI draft, edit it, and deliver to the parent.
</p>

<?php admin_layout_close(); ?>
