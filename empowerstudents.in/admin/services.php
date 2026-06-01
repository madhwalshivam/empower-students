<?php
require __DIR__ . '/_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'save') {
        $prices = $_POST['price'] ?? [];
        $active = $_POST['active'] ?? [];
        foreach ($prices as $key => $val) {
            $key = trim($key);
            if ($key === '') continue;
            $is_active = isset($active[$key]) ? 1 : 0;
            db()->prepare("UPDATE service_prices SET price = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE service_key = ?")
               ->execute([(int)$val, $is_active, $key]);
        }
        flash('Pricing saved.');
        header('Location: /admin/services.php'); exit;
    }
}

$rows = db()->query("SELECT * FROM service_prices ORDER BY price ASC, service_key ASC")->fetchAll();

admin_layout_open('Pricing');
admin_render_flash();
?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">

  <p class="text-sm text-slate-600 mb-4">
    Edit credit cost for each service. <strong>1 credit = ₹1</strong>. New parents get
    <strong><?= (int)SIGNUP_FREE_CREDITS ?></strong> free credits on signup. Hard cap on
    comprehensive AI report: <strong><?= (int)COMPREHENSIVE_REPORT_MAX_PER_CHILD ?></strong> per child.
  </p>

  <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left">
        <tr><th class="px-3 py-2">Service key</th><th>Label</th><th class="text-right">Credits</th><th>Active</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="border-t border-slate-100">
          <td class="px-3 py-2 font-mono text-xs"><?= e($r['service_key']) ?></td>
          <td><?= e($r['label']) ?></td>
          <td class="text-right">
            <input type="number" name="price[<?= e($r['service_key']) ?>]"
                   value="<?= (int)$r['price'] ?>" min="0" max="9999"
                   class="w-20 text-right border border-slate-200 rounded-lg p-1">
          </td>
          <td>
            <input type="checkbox" name="active[<?= e($r['service_key']) ?>]" <?= (int)$r['is_active'] ? 'checked' : '' ?>>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <button class="mt-4 bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm">Save all pricing</button>
</form>
<?php admin_layout_close(); ?>
