<?php
require __DIR__ . '/_admin.php';

$action = $_GET['action'] ?? '';
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            trim($_POST['name'] ?? ''),
            trim($_POST['role'] ?? ''),
            trim($_POST['qualifications'] ?? ''),
            trim($_POST['bio'] ?? ''),
            trim($_POST['photo'] ?? ''),
            (int)($_POST['order_no'] ?? 100),
            isset($_POST['active']) ? 1 : 0,
        ];
        if ($id) {
            $data[] = $id;
            db()->prepare("UPDATE specialists SET name=?, role=?, qualifications=?, bio=?, photo=?, order_no=?, active=? WHERE id=?")
                ->execute($data);
            flash('Specialist updated.');
        } else {
            db()->prepare("INSERT INTO specialists (name,role,qualifications,bio,photo,order_no,active) VALUES (?,?,?,?,?,?,?)")
                ->execute($data);
            flash('Specialist added.');
        }
        header('Location: /admin/specialists.php'); exit;
    }
}

if ($action === 'del' && isset($_GET['id'])) {
    db()->prepare("DELETE FROM specialists WHERE id = ?")->execute([(int)$_GET['id']]);
    flash('Deleted.');
    header('Location: /admin/specialists.php'); exit;
}
if ($action === 'edit' && isset($_GET['id'])) {
    $st = db()->prepare("SELECT * FROM specialists WHERE id = ?");
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

$specs = db()->query("SELECT * FROM specialists ORDER BY order_no, id")->fetchAll();

admin_layout_open('Specialists');
admin_render_flash();
?>
<section class="bg-white rounded-2xl border border-slate-200 p-5 mb-4">
  <h2 class="font-semibold mb-3"><?= $edit ? 'Edit specialist' : 'Add new specialist' ?></h2>
  <form method="post" class="grid sm:grid-cols-2 gap-3 text-sm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <input name="name"           placeholder="Name"           required value="<?= e($edit['name'] ?? '') ?>"           class="border border-slate-200 rounded-lg p-2">
    <input name="role"           placeholder="Role"           required value="<?= e($edit['role'] ?? '') ?>"           class="border border-slate-200 rounded-lg p-2">
    <input name="qualifications" placeholder="Qualifications"          value="<?= e($edit['qualifications'] ?? '') ?>" class="border border-slate-200 rounded-lg p-2">
    <input name="photo"          placeholder="Photo filename"          value="<?= e($edit['photo'] ?? '') ?>"          class="border border-slate-200 rounded-lg p-2">
    <textarea name="bio" rows="2" placeholder="Short bio" class="border border-slate-200 rounded-lg p-2 sm:col-span-2"><?= e($edit['bio'] ?? '') ?></textarea>
    <input type="number" name="order_no" placeholder="Order" value="<?= (int)($edit['order_no'] ?? 100) ?>" class="border border-slate-200 rounded-lg p-2">
    <label class="flex items-center gap-2"><input type="checkbox" name="active" <?= (!isset($edit) || $edit['active']) ? 'checked' : '' ?>> Active</label>
    <button class="sm:col-span-2 bg-indigo-600 text-white py-2 rounded-lg"><?= $edit ? 'Update' : 'Add' ?></button>
    <?php if ($edit): ?>
      <a href="/admin/specialists.php" class="sm:col-span-2 text-center text-xs text-slate-500 underline">Cancel and add new</a>
    <?php endif; ?>
  </form>
</section>

<section class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-xs uppercase text-slate-500 text-left"><tr>
      <th class="px-3 py-2">#</th><th>Name</th><th>Role</th><th>Photo</th><th>Active</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($specs as $s): ?>
      <tr class="border-t border-slate-100">
        <td class="px-3 py-2"><?= (int)$s['order_no'] ?></td>
        <td><?= e($s['name']) ?></td>
        <td><?= e($s['role']) ?></td>
        <td class="text-xs text-slate-500"><?= e($s['photo']) ?></td>
        <td><?= (int)$s['active'] ? '✓' : '—' ?></td>
        <td class="flex gap-3 py-2">
          <a href="?action=edit&id=<?= (int)$s['id'] ?>" class="text-indigo-600 text-xs">edit</a>
          <a href="?action=del&id=<?= (int)$s['id'] ?>" onclick="return confirm('Delete?')" class="text-rose-600 text-xs">delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php admin_layout_close(); ?>
