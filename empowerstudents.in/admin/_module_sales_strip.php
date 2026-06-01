<?php
/**
 * /admin/_module_sales_strip.php — small stats strip for the admin home page.
 *
 * Include this from /admin/index.php between the Care Pack revenue strip and
 * the 🤝 Partners strip. Auto-hides if there are no module sales yet (so it
 * doesn't add visual noise during early days).
 *
 * Pure-read. No schema writes.
 */
$_mod_window_days = 30;
$_mod_since = date('Y-m-d H:i:s', strtotime("-{$_mod_window_days} days"));

$_mod_total_sales = (int) db()->query("
    SELECT COUNT(*) FROM wallet_ledger wl
    JOIN service_meta sm ON sm.service_key = wl.service_key
    WHERE wl.amount < 0
      AND wl.created_at >= '" . $_mod_since . "'
      AND sm.is_catalogue = 1
")->fetchColumn();

if ($_mod_total_sales === 0) return; // hide strip until first sale

$_mod_total_revenue = (int) db()->query("
    SELECT COALESCE(-SUM(wl.amount), 0) FROM wallet_ledger wl
    JOIN service_meta sm ON sm.service_key = wl.service_key
    WHERE wl.amount < 0
      AND wl.created_at >= '" . $_mod_since . "'
      AND sm.is_catalogue = 1
")->fetchColumn();

$_mod_top = db()->query("
    SELECT wl.service_key, sp.label, sm.icon, COUNT(*) as c, COALESCE(-SUM(wl.amount), 0) as rev
    FROM wallet_ledger wl
    JOIN service_meta sm ON sm.service_key = wl.service_key
    LEFT JOIN service_prices sp ON sp.service_key = wl.service_key
    WHERE wl.amount < 0
      AND wl.created_at >= '" . $_mod_since . "'
      AND sm.is_catalogue = 1
    GROUP BY wl.service_key
    ORDER BY rev DESC
    LIMIT 3
")->fetchAll();

$_mod_consults_30d = (int) db()->query("SELECT COUNT(*) FROM module_consults WHERE created_at >= '" . $_mod_since . "'")->fetchColumn();
?>

<h2 class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-2 mt-6">📦 Module catalogue (last 30 days)</h2>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Module sales</div>
    <div class="text-2xl font-bold mt-1"><?= number_format($_mod_total_sales) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Module revenue</div>
    <div class="text-2xl font-bold mt-1">₹<?= number_format($_mod_total_revenue) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">AI consults</div>
    <div class="text-2xl font-bold mt-1"><?= number_format($_mod_consults_30d) ?></div>
  </div>
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="text-[10px] uppercase text-slate-500 font-bold">Top sellers</div>
    <div class="text-xs text-slate-700 mt-1 space-y-0.5">
      <?php if (empty($_mod_top)): ?>
        <span class="text-slate-400">—</span>
      <?php else: foreach ($_mod_top as $t): ?>
        <div class="truncate" title="<?= e($t['label'] ?? $t['service_key']) ?>">
          <?= e($t['icon'] ?? '📦') ?>
          <?= e(mb_substr($t['label'] ?? $t['service_key'], 0, 22)) ?>
          <span class="text-slate-400">×<?= (int)$t['c'] ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<p class="text-xs text-slate-500 -mt-4 mb-6">
  → <a href="/admin/module_sales.php" class="text-indigo-600 hover:underline">Full sales report</a>
  &middot; <a href="/admin/catalogue.php" class="text-indigo-600 hover:underline">Manage catalogue</a>
</p>
