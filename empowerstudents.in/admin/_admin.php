<?php
/**
 * admin/_admin.php — shared admin auth + layout helpers.
 * Every admin page begins with `require __DIR__ . '/_admin.php';`
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/cashfree.php';

// already started by config.php — but harmless if re-checked
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id']) && basename($_SERVER['SCRIPT_NAME']) !== 'login.php') {
    header('Location: /admin/login.php'); exit;
}

function admin_user(): string {
    return $_SESSION['admin_user'] ?? 'admin';
}

function admin_layout_open(string $title) {
    // Count pending partner applications — badge in nav
    $pending_app_count = 0;
    try {
        $pending_app_count = (int)db()->query("SELECT COUNT(*) FROM partner_applications WHERE status='pending'")->fetchColumn();
    } catch (Throwable $_) {}
    ?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($title) ?> · Admin · Empower Students</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Inter',system-ui,sans-serif}</style></head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
<header class="bg-white border-b border-slate-200">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between flex-wrap gap-3">
    <strong class="text-base">⚙️ Empower Students · Admin</strong>
    <nav class="flex flex-wrap gap-4 text-sm">
      <a href="/admin/index.php"      class="hover:text-indigo-600 relative">
        Overview
        <?php if ($pending_app_count > 0): ?>
          <span class="absolute -top-2 -right-3 bg-indigo-500 text-white text-[10px] rounded-full px-1.5 py-0.5 font-bold"><?= $pending_app_count ?></span>
        <?php endif; ?>
      </a>
      <a href="/admin/leads.php"      class="hover:text-indigo-600">Leads</a>
      <a href="/admin/evaluations.php"class="hover:text-indigo-600">Evaluations</a>
      <a href="/admin/parents.php"    class="hover:text-indigo-600">Parents</a>
      <a href="/admin/orders.php"     class="hover:text-indigo-600">Payments</a>
      <a href="/admin/specialists.php"class="hover:text-indigo-600">Specialists</a>
      <a href="/admin/partners.php"   class="hover:text-indigo-600">Partners</a>
      <a href="/admin/services.php"   class="hover:text-indigo-600">Pricing</a>
      <a href="/admin/settings.php"   class="hover:text-indigo-600">Settings</a>
      <a href="/" class="text-slate-500 hover:text-indigo-600">↗ Site</a>
      <a href="/admin/logout.php" class="text-rose-600 hover:underline">Logout</a>
    </nav>
  </div>
</header>
<main class="max-w-7xl mx-auto px-4 py-6"><?php
}

function admin_layout_close() {
    echo '</main></body></html>';
}

function flash(string $msg, string $tone = 'emerald') {
    $_SESSION['admin_flash'] = ['msg' => $msg, 'tone' => $tone];
}
function admin_render_flash() {
    if (empty($_SESSION['admin_flash'])) return;
    $f = $_SESSION['admin_flash']; unset($_SESSION['admin_flash']);
    $colors = [
        'emerald' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
        'amber'   => 'bg-amber-50 border-amber-200 text-amber-800',
        'rose'    => 'bg-rose-50 border-rose-200 text-rose-800',
    ];
    $c = $colors[$f['tone']] ?? $colors['emerald'];
    echo '<div class="' . $c . ' border rounded-lg px-4 py-3 mb-4 text-sm">' . e($f['msg']) . '</div>';
}
