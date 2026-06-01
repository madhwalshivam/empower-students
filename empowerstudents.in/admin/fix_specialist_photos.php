<?php
/**
 * fix_specialist_photos.php — one-time tool. Visit /admin/fix_specialist_photos.php
 * to update the photo paths to match files actually present in /assets/images/.
 * After running once, you can delete this file.
 */
require __DIR__ . '/_admin.php';

// Map: old filename in DB → new filename to use (relative to /assets/images/)
// Based on the user's actual /assets/images/ directory
$mapping = [
    'ot.jpg'           => 'ot.png',
    'speech.jpg'       => 'speech.png',
    'psychologist.jpg' => 'psychologist.png',
    'neurologist.jpg'  => 'regenerative.png',  // user has regenerative.png in place of neurologist
    'paeds.jpg'        => 'paeds.png',
    'counsellor.jpg'   => 'counsellor.png',
    'special_ed.jpg'   => 'special_ed.png',
];

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    foreach ($mapping as $old => $new) {
        // Match if photo column equals exactly old filename, OR contains old filename anywhere
        $st = db()->prepare("UPDATE specialists
                             SET photo = ?
                             WHERE photo = ? OR photo LIKE ?");
        $st->execute(['/assets/images/' . $new, $old, '%' . $old]);
        $results[] = "$old → /assets/images/$new ({$st->rowCount()} rows)";
    }
    flash('Photos updated. Visit the home page to see updated images.', 'emerald');
}

// Show current values
$current = db()->query("SELECT id, name, role, photo FROM specialists ORDER BY order_no")->fetchAll();

admin_layout_open('Fix specialist photos');
admin_render_flash();
?>

<div class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
  <h2 class="font-bold text-lg mb-3">Current photo paths in DB</h2>
  <table class="w-full text-sm">
    <thead><tr class="text-xs uppercase text-slate-500 text-left"><th>Name</th><th>Role</th><th>Current photo path</th><th>Preview</th></tr></thead>
    <tbody>
      <?php foreach ($current as $c): ?>
        <tr class="border-t border-slate-100">
          <td class="py-2"><?= e($c['name']) ?></td>
          <td><?= e($c['role']) ?></td>
          <td class="font-mono text-xs"><?= e($c['photo']) ?></td>
          <td>
            <?php
              $p = $c['photo'];
              if ($p && strpos($p, '/') !== 0) $p = '/assets/images/' . $p;
            ?>
            <img src="<?= e($p) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:50%" onerror="this.style.opacity=0.2;this.title='File not found'">
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<form method="post" class="bg-amber-50 border border-amber-200 rounded-2xl p-6">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <h2 class="font-bold mb-2">Update to /assets/images/&lt;name&gt;.png</h2>
  <p class="text-sm text-slate-600 mb-4">
    This will rewrite all DB photo paths to match the .png files actually present on the server.
    Mapping:
  </p>
  <ul class="text-xs font-mono mb-4 space-y-1">
    <?php foreach ($mapping as $old => $new): ?>
      <li><?= e($old) ?> → /assets/images/<?= e($new) ?></li>
    <?php endforeach; ?>
  </ul>
  <button class="bg-amber-600 text-white px-5 py-2.5 rounded-lg font-semibold">Apply fix</button>
</form>

<?php admin_layout_close(); ?>
