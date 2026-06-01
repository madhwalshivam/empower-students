<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $st = db()->prepare("SELECT * FROM admins WHERE username = ?");
    $st->execute([$u]);
    $row = $st->fetch();
    if ($row && password_verify($p, $row['pass_hash'])) {
        $_SESSION['admin_id']   = (int)$row['id'];
        $_SESSION['admin_user'] = $row['username'];
        header('Location: /admin/index.php'); exit;
    }
    $err = 'Invalid username or password.';
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Admin login · Empower Students</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
<form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 w-full max-w-sm shadow">
  <h1 class="text-xl font-bold mb-4">⚙️ Admin login</h1>
  <?php if ($err): ?><p class="bg-rose-50 text-rose-700 text-sm p-2 rounded mb-3"><?= e($err) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label class="block text-sm font-medium text-slate-700">Username
    <input type="text" name="username" required class="mt-1 w-full border border-slate-200 rounded-lg p-2">
  </label>
  <label class="block text-sm font-medium text-slate-700 mt-3">Password
    <input type="password" name="password" required class="mt-1 w-full border border-slate-200 rounded-lg p-2">
  </label>
  <button class="mt-4 w-full bg-indigo-600 text-white py-2.5 rounded-lg font-medium">Sign in</button>
  <p class="text-xs text-slate-500 mt-3">Default: <code>admin</code> / <code>empower@2026</code> — change immediately from <em>Settings</em>.</p>
</form></body></html>
