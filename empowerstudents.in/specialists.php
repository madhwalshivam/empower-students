<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Our Panel';
$specialists = db()->query('SELECT * FROM specialists WHERE active=1 ORDER BY order_no ASC')->fetchAll();
require __DIR__ . '/includes/header.php';
?>

<header class="mb-10">
  <h1 class="text-3xl sm:text-4xl font-extrabold mb-2" data-i18n="panel.heading">Our multi-disciplinary panel</h1>
  <p class="text-slate-600 max-w-3xl" data-i18n="panel.subhead">Every assessment we do is reviewed in the light of these specialties. If your child needs a deeper look, we connect you to the right professional &mdash; in person at our partner clinics or via tele-consultation.</p>
</header>

<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
<?php foreach ($specialists as $sp): ?>
  <article class="bg-white rounded-2xl overflow-hidden border border-slate-100 shadow-sm">
    <div class="aspect-[4/3] bg-slate-100 flex items-center justify-center text-slate-400">
      <?php if ($sp['photo'] && file_exists(__DIR__ . '/assets/images/' . $sp['photo'])): ?>
        <img src="/assets/images/<?= e($sp['photo']) ?>" alt="<?= e($sp['name']) ?>" class="w-full h-full object-cover">
      <?php else: ?>
        <div class="text-center px-4">
          <p class="text-sm">[ Upload <code class="bg-slate-200 px-1 rounded"><?= e($sp['photo']) ?></code> ]</p>
          <p class="text-xs mt-1 opacity-70">/assets/images/</p>
        </div>
      <?php endif; ?>
    </div>
    <div class="p-5">
      <p class="text-xs uppercase tracking-wide text-indigo-600 font-semibold"><?= e($sp['role']) ?></p>
      <h2 class="text-lg font-semibold mt-1"><?= e($sp['name']) ?></h2>
      <p class="text-sm text-slate-500 mt-1"><?= e($sp['qualifications']) ?></p>
      <p class="text-sm text-slate-600 mt-3"><?= e($sp['bio']) ?></p>
    </div>
  </article>
<?php endforeach; ?>
</div>

<div class="mt-12 bg-indigo-50 border border-indigo-100 rounded-2xl p-6 text-center">
  <p class="text-sm text-indigo-900">Want to refer a child or join our panel?
    <a href="https://wa.me/<?= preg_replace('/\D/','', SITE_SUPPORT_WA) ?>" class="font-semibold underline">WhatsApp us</a>
    or call <a href="tel:<?= e(SITE_SUPPORT_PH) ?>" class="font-semibold underline"><?= e(SITE_SUPPORT_PH) ?></a>.
  </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
