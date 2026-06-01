<?php
require __DIR__ . '/_admin.php';
// credit_order_if_paid() now lives inside includes/cashfree.php, which
// _admin.php already pulls in. No need to require payment_return.php
// (which was running its own page-redirect logic and 400ing on this page).

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'reverify' && !empty($_POST['order_id'])) {
        try {
            $r = credit_order_if_paid(trim($_POST['order_id']));
            $tone = ($r['status'] === 'credited' || $r['status'] === 'already_credited') ? 'emerald' : 'amber';
            flash('Re-verify result: ' . ($r['status'] ?? '—'), $tone);
        } catch (Throwable $e) {
            flash('Error: ' . $e->getMessage(), 'rose');
        }
        header('Location: /admin/orders.php' . (empty($_GET) ? '' : '?' . http_build_query($_GET))); exit;
    }
}

$status = $_GET['status'] ?? '';
$where  = '1=1'; $params = [];
if (in_array($status, ['pending','success','failed'])) {
    $where .= " AND status = ?"; $params[] = $status;
}

$sql = "SELECT o.*, p.name AS pname, p.whatsapp
        FROM payment_orders o LEFT JOIN parents p ON p.id = o.parent_id
        WHERE $where ORDER BY o.id DESC LIMIT 200";
$st = db()->prepare($sql); $st->execute($params); $orders = $st->fetchAll();

$totals = [
    'success' => (int) db()->query("SELECT COALESCE(SUM(amount),0) FROM payment_orders WHERE status='success'")->fetchColumn(),
    'pending' => (int) db()->query("SELECT COUNT(*) FROM payment_orders WHERE status='pending'")->fetchColumn(),
    'failed'  => (int) db()->query("SELECT COUNT(*) FROM payment_orders WHERE status='failed'")->fetchColumn(),
];

admin_layout_open('Payments');
admin_render_flash();
?>
<div class="grid grid-cols-3 gap-3 mb-4">
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-emerald-700">Revenue (success)</div>
    <div class="text-2xl font-bold text-emerald-900">₹<?= number_format($totals['success']) ?></div>
  </div>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-amber-700">Pending</div>
    <div class="text-2xl font-bold text-amber-900"><?= $totals['pending'] ?></div>
  </div>
  <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">
    <div class="text-xs uppercase text-rose-700">Failed</div>
    <div class="text-2xl font-bold text-rose-900"><?= $totals['failed'] ?></div>
  </div>
</div>

<div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap gap-2 items-center text-sm">
  <span class="text-xs text-slate-500">Filter:</span>
  <a href="?" class="px-3 py-1 rounded-lg <?= !$status ? 'bg-slate-800 text-white' : 'bg-slate-100' ?>">All</a>
  <a href="?status=success" class="px-3 py-1 rounded-lg <?= $status==='success' ? 'bg-emerald-600 text-white' : 'bg-slate-100' ?>">Success</a>
  <a href="?status=pending" class="px-3 py-1 rounded-lg <?= $status==='pending' ? 'bg-amber-500 text-white' : 'bg-slate-100' ?>">Pending</a>
  <a href="?status=failed"  class="px-3 py-1 rounded-lg <?= $status==='failed'  ? 'bg-rose-600 text-white' : 'bg-slate-100' ?>">Failed</a>
  <span class="ml-auto text-xs text-slate-400"><?= count($orders) ?> shown</span>
</div>

<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left">
      <tr>
        <th class="px-3 py-2">Order</th>
        <th>Parent</th>
        <th class="text-right">Amount</th>
        <th>Status</th>
        <th>Credited</th>
        <th>When</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr class="border-t border-slate-100">
        <td class="px-3 py-2 font-mono text-xs"><?= e($o['order_id']) ?></td>
        <td>
          <a href="/admin/parent.php?id=<?= (int)$o['parent_id'] ?>" class="text-indigo-600 hover:underline">
            <?= e($o['pname'] ?: '#' . $o['parent_id']) ?>
          </a>
          <div class="text-xs text-slate-500 font-mono"><?= e($o['whatsapp']) ?></div>
        </td>
        <td class="text-right">₹<?= (int)$o['amount'] ?></td>
        <td>
          <span class="text-xs px-2 py-0.5 rounded
            <?= $o['status']==='success' ? 'bg-emerald-100 text-emerald-700' : ($o['status']==='pending' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') ?>">
            <?= e($o['status']) ?>
          </span>
        </td>
        <td><?= (int)$o['credited'] ? '✓' : '—' ?></td>
        <td class="text-xs text-slate-500"><?= e(substr($o['completed_at'] ?? $o['created_at'], 0, 16)) ?></td>
        <td>
          <?php if ($o['status'] !== 'success' || !$o['credited']): ?>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reverify">
              <input type="hidden" name="order_id" value="<?= e($o['order_id']) ?>">
              <button class="text-xs text-indigo-600 hover:underline">re-verify</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="7" class="text-center text-slate-400 py-6">No orders.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<p class="text-xs text-slate-400 mt-3">
  Re-verify hits the Cashfree API to recheck status. Idempotent — if already credited, nothing changes.
</p>
<?php admin_layout_close(); ?>
