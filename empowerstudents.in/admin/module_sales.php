<?php
/**
 * /admin/module_sales.php — uptake report for the modular catalogue.
 *
 * Reads from wallet_ledger (charges) + module_consults + service_meta.
 * Pure-read, idempotent, no schema writes.
 */
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/catalogue.php';

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
$since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

// Per-module sales since cutoff
$rows = db()->prepare("
    SELECT
        wl.service_key,
        sp.label,
        sm.icon,
        sm.tier,
        sm.catalogue_group,
        COUNT(*)              AS sales,
        COALESCE(-SUM(wl.amount), 0) AS revenue
    FROM wallet_ledger wl
    LEFT JOIN service_prices sp ON sp.service_key = wl.service_key
    LEFT JOIN service_meta   sm ON sm.service_key = wl.service_key
    WHERE wl.amount < 0
      AND wl.created_at >= ?
      AND wl.service_key IS NOT NULL
      AND wl.service_key != 'cart_discount'
    GROUP BY wl.service_key
    ORDER BY revenue DESC, sales DESC
");
$rows->execute([$since]);
$rows = $rows->fetchAll();

$totals = ['sales' => 0, 'revenue' => 0];
foreach ($rows as $r) {
    $totals['sales']   += (int)$r['sales'];
    $totals['revenue'] += (int)$r['revenue'];
}

// Discount given out
$discount_given = (int) db()->query("
    SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger
    WHERE service_key = 'cart_discount' AND created_at >= '" . $since . "'
")->fetchColumn();

// Consult activity
$consult_questions = (int) db()->query("SELECT COUNT(*) FROM module_consults WHERE created_at >= '" . $since . "'")->fetchColumn();
$consult_breakdown = db()->prepare("SELECT paid_from, COUNT(*) c FROM module_consults WHERE created_at >= ? GROUP BY paid_from")->execute([$since]);
$consult_breakdown = db()->query("SELECT paid_from, COUNT(*) c FROM module_consults WHERE created_at >= '" . $since . "' GROUP BY paid_from")->fetchAll();

// Catalogue page traffic — proxy via wallet activity (we don't track page views here)
// Most-viewed-not-bought is inferable later; for now we flag top sellers.

admin_layout_open('Module sales');
?>

<div class="flex items-center gap-3 mb-4 flex-wrap">
  <span class="text-sm text-slate-600">Window:</span>
  <?php foreach ([7, 30, 90, 365] as $d): ?>
    <a href="?days=<?= $d ?>" class="text-xs px-3 py-1 rounded-full border <?= $days === $d ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
      Last <?= $d ?> days
    </a>
  <?php endforeach; ?>
</div>

<!-- Summary cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Total sales</div>
    <div class="text-2xl font-bold mt-1"><?= number_format($totals['sales']) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Gross revenue</div>
    <div class="text-2xl font-bold mt-1">₹<?= number_format($totals['revenue']) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Discounts given</div>
    <div class="text-2xl font-bold mt-1 text-amber-700">₹<?= number_format($discount_given) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">AI consult questions</div>
    <div class="text-2xl font-bold mt-1"><?= number_format($consult_questions) ?></div>
    <?php if (!empty($consult_breakdown)): ?>
      <div class="text-[10px] text-slate-500 mt-1">
        <?php foreach ($consult_breakdown as $cb): ?>
          <?= e($cb['paid_from'] ?: '—') ?>: <?= (int)$cb['c'] ?>&nbsp;&nbsp;
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Per-module table -->
<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left">
      <tr>
        <th class="px-3 py-2"></th>
        <th class="px-3 py-2">Module</th>
        <th class="px-3 py-2">Group</th>
        <th class="px-3 py-2">Tier</th>
        <th class="px-3 py-2 text-right">Sales</th>
        <th class="px-3 py-2 text-right">Revenue</th>
        <th class="px-3 py-2 text-right">% of total</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="px-3 py-12 text-center text-slate-500">No module sales in the last <?= $days ?> days yet.</td></tr>
      <?php else: foreach ($rows as $r):
          $pct = $totals['revenue'] > 0 ? round((int)$r['revenue'] * 100 / $totals['revenue'], 1) : 0;
      ?>
        <tr class="border-t border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-2 text-xl"><?= e($r['icon'] ?? '📦') ?></td>
          <td class="px-3 py-2">
            <div class="font-medium text-slate-900"><?= e($r['label'] ?? $r['service_key']) ?></div>
            <div class="text-xs text-slate-400 font-mono"><?= e($r['service_key']) ?></div>
          </td>
          <td class="px-3 py-2 text-xs text-slate-500"><?= e($r['catalogue_group'] ?? '—') ?></td>
          <td class="px-3 py-2 text-xs text-slate-500"><?= e($r['tier'] ?? '—') ?></td>
          <td class="px-3 py-2 text-right font-semibold"><?= number_format((int)$r['sales']) ?></td>
          <td class="px-3 py-2 text-right font-semibold">₹<?= number_format((int)$r['revenue']) ?></td>
          <td class="px-3 py-2 text-right text-xs text-slate-500"><?= $pct ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="mt-4 text-xs text-slate-500">
  Source: <code>wallet_ledger</code> rows with <code>amount &lt; 0</code> (excludes <code>cart_discount</code>).
  Edit module pricing/visibility at <a href="/admin/catalogue.php" class="text-indigo-600 hover:underline">/admin/catalogue.php</a>.
</p>

<?php admin_layout_close(); ?>
