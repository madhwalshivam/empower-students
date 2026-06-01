<?php
/**
 * /admin/catalogue.php — manage module catalogue metadata.
 *
 * Edits service_meta + the joined service_prices.price/is_active.
 * Pricing-only edits already work in /admin/services.php; this page adds
 * the catalogue metadata layer (icon, group, tier, descriptions, plan length,
 * free consults, sort order, visibility).
 */
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/catalogue.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'save') {
        $rows = $_POST['row'] ?? [];
        $sm_upd = db()->prepare("UPDATE service_meta SET
            catalogue_group = ?, tier = ?, icon = ?,
            short_desc = ?, short_desc_hi = ?,
            sample_question = ?, sample_question_hi = ?,
            age_min = ?, age_max = ?, plan_weeks = ?,
            free_consults_included = ?, sort_order = ?,
            is_catalogue = ?, assessment_ready = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE service_key = ?");
        $sp_upd = db()->prepare("UPDATE service_prices SET price = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE service_key = ?");
        foreach ($rows as $key => $v) {
            $sm_upd->execute([
                $v['group'] ?? '',
                $v['tier']  ?? '',
                $v['icon']  ?? '',
                $v['short'] ?? '',
                $v['short_hi'] ?? '',
                $v['sample'] ?? '',
                $v['sample_hi'] ?? '',
                (float)($v['age_min'] ?? 0),
                (float)($v['age_max'] ?? 18),
                (int)($v['plan_weeks'] ?? 0),
                (int)($v['consults'] ?? 0),
                (int)($v['sort'] ?? 100),
                isset($v['catalogue']) ? 1 : 0,
                isset($v['ready']) ? 1 : 0,
                $key,
            ]);
            $sp_upd->execute([
                (int)($v['price'] ?? 0),
                isset($v['active']) ? 1 : 0,
                $key,
            ]);
        }
        flash('Catalogue saved.');
        header('Location: /admin/catalogue.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'reseed') {
        // Force a fresh seed run by clearing the static guard isn't easy;
        // instead, we just touch the file's seed inserts manually.
        // The schema file uses INSERT OR IGNORE so this is a no-op for
        // existing rows — perfect for reseeding only missing rows.
        require_once __DIR__ . '/../includes/catalogue_schema.php';
        flash('Re-seeded any missing catalogue rows.');
        header('Location: /admin/catalogue.php');
        exit;
    }
}

// Pull rows including legacy hidden ones AND not-yet-ready ones so admin can manage them
$rows = catalogue_modules(['include_legacy' => true, 'include_partial' => true]);

admin_layout_open('Module catalogue');
admin_render_flash();
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
  <p class="text-sm text-slate-600">
    Manage the parent-facing module catalogue. Pricing in <strong>credits = ₹</strong>.
    Toggle <em>Catalogue</em> off to hide a row from <code>/catalogue.php</code> without deleting it.
  </p>
  <form method="post" class="m-0">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reseed">
    <button class="text-xs px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg">↻ Re-seed missing rows</button>
  </form>
</div>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">

  <div class="overflow-x-auto bg-white rounded-2xl border border-slate-200">
    <table class="w-full text-xs">
      <thead class="bg-slate-50 text-slate-600 uppercase text-[10px]">
        <tr>
          <th class="px-2 py-2 text-left">Key</th>
          <th class="px-2 py-2">Icon</th>
          <th class="px-2 py-2 text-left">Label</th>
          <th class="px-2 py-2">Group</th>
          <th class="px-2 py-2">Tier</th>
          <th class="px-2 py-2 text-right">Price</th>
          <th class="px-2 py-2">Plan wk</th>
          <th class="px-2 py-2">Free consults</th>
          <th class="px-2 py-2">Age min</th>
          <th class="px-2 py-2">Age max</th>
          <th class="px-2 py-2">Sort</th>
          <th class="px-2 py-2">Active</th>
          <th class="px-2 py-2">Catalogue</th>
          <th class="px-2 py-2" title="Assessment file exists. Off = module hides from /catalogue.php (admin can flip on once an assessment is built).">Ready</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $k = $r['service_key']; ?>
          <tr class="border-t border-slate-100">
            <td class="px-2 py-1 font-mono text-[10px]"><?= e($k) ?></td>
            <td class="px-2 py-1 text-center">
              <input name="row[<?= e($k) ?>][icon]" value="<?= e($r['icon'] ?? '') ?>"
                     class="w-12 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1"><?= e($r['label']) ?></td>
            <td class="px-2 py-1">
              <select name="row[<?= e($k) ?>][group]" class="border border-slate-200 rounded p-1 text-xs">
                <?php foreach (['', 'special', 'all', 'parent', 'pack', 'consult'] as $g): ?>
                  <option value="<?= e($g) ?>" <?= ($r['catalogue_group'] ?? '') === $g ? 'selected' : '' ?>><?= e($g ?: '—') ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-1">
              <select name="row[<?= e($k) ?>][tier]" class="border border-slate-200 rounded p-1 text-xs">
                <?php foreach (['', 'quick', 'standard', 'deep', 'pack', 'consult'] as $t): ?>
                  <option value="<?= e($t) ?>" <?= ($r['tier'] ?? '') === $t ? 'selected' : '' ?>><?= e($t ?: '—') ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-1 text-right">
              <input type="number" name="row[<?= e($k) ?>][price]" value="<?= (int)$r['price'] ?>"
                     min="0" max="9999" class="w-20 text-right border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="number" name="row[<?= e($k) ?>][plan_weeks]" value="<?= (int)($r['plan_weeks'] ?? 0) ?>"
                     min="0" max="52" class="w-14 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="number" name="row[<?= e($k) ?>][consults]" value="<?= (int)($r['free_consults_included'] ?? 0) ?>"
                     min="0" max="50" class="w-14 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="number" step="0.5" name="row[<?= e($k) ?>][age_min]" value="<?= (float)($r['age_min'] ?? 0) ?>"
                     class="w-14 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="number" step="0.5" name="row[<?= e($k) ?>][age_max]" value="<?= (float)($r['age_max'] ?? 18) ?>"
                     class="w-14 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="number" name="row[<?= e($k) ?>][sort]" value="<?= (int)($r['sort_order'] ?? 100) ?>"
                     class="w-16 text-center border border-slate-200 rounded p-1">
            </td>
            <td class="px-2 py-1 text-center">
              <input type="checkbox" name="row[<?= e($k) ?>][active]" <?= (int)$r['is_active'] ? 'checked' : '' ?>>
            </td>
            <td class="px-2 py-1 text-center">
              <input type="checkbox" name="row[<?= e($k) ?>][catalogue]" <?= (int)($r['is_catalogue'] ?? 0) ? 'checked' : '' ?>>
            </td>
            <td class="px-2 py-1 text-center">
              <input type="checkbox" name="row[<?= e($k) ?>][ready]" <?= (int)($r['assessment_ready'] ?? 1) ? 'checked' : '' ?>>
            </td>
          </tr>
          <tr class="bg-slate-50/50 border-t border-dashed border-slate-100">
            <td colspan="14" class="px-2 py-2">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                <div>
                  <label class="text-slate-500">Short description (English)</label>
                  <input name="row[<?= e($k) ?>][short]" value="<?= e($r['short_desc'] ?? '') ?>" class="w-full border border-slate-200 rounded p-1">
                </div>
                <div>
                  <label class="text-slate-500">Short description (हिन्दी)</label>
                  <input name="row[<?= e($k) ?>][short_hi]" value="<?= e($r['short_desc_hi'] ?? '') ?>" class="w-full border border-slate-200 rounded p-1">
                </div>
                <div>
                  <label class="text-slate-500">Free sample question (English)</label>
                  <input name="row[<?= e($k) ?>][sample]" value="<?= e($r['sample_question'] ?? '') ?>" class="w-full border border-slate-200 rounded p-1">
                </div>
                <div>
                  <label class="text-slate-500">Free sample question (हिन्दी)</label>
                  <input name="row[<?= e($k) ?>][sample_hi]" value="<?= e($r['sample_question_hi'] ?? '') ?>" class="w-full border border-slate-200 rounded p-1">
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    <button class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm">Save all</button>
    <a href="/admin/services.php" class="ml-3 text-xs text-slate-500 hover:underline">Pricing-only view →</a>
  </div>
</form>

<?php admin_layout_close(); ?>
