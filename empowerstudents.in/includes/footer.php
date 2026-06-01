</main>

<footer class="bg-slate-900 text-slate-300 mt-16">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-10 grid md:grid-cols-3 gap-8">
    <div>
      <div class="flex items-center gap-2 mb-3">
        <span class="brand-grad text-white w-8 h-8 rounded-lg flex items-center justify-center font-bold">E</span>
        <span class="font-bold text-lg text-white"><?= e(SITE_NAME) ?></span>
      </div>
      <p class="text-sm text-slate-400"><?= e(SITE_TAGLINE) ?></p>
    </div>
    <div class="text-sm">
      <h4 class="text-white font-semibold mb-2">Contact</h4>
      <p>Call: <a href="tel:<?= e(SITE_SUPPORT_PH) ?>" class="hover:text-white"><?= e(SITE_SUPPORT_PH) ?></a></p>
      <p>WhatsApp: <a href="https://wa.me/<?= preg_replace('/\D/','', SITE_SUPPORT_WA) ?>" class="hover:text-white"><?= e(SITE_SUPPORT_WA) ?></a></p>
      <p>Email: <a href="mailto:<?= e(SITE_SUPPORT_EMAIL) ?>" class="hover:text-white"><?= e(SITE_SUPPORT_EMAIL) ?></a></p>
    </div>
    <div class="text-sm">
      <h4 class="text-white font-semibold mb-2">Quick links</h4>
      <ul class="space-y-1">
        <li><a href="/specialists.php" class="hover:text-white">Our Panel</a></li>
        <li><a href="/about.php" class="hover:text-white">About</a></li>
        <li><a href="/login.php" class="hover:text-white">Parent Login</a></li>
      </ul>
    </div>
  </div>
  <div class="border-t border-slate-800 py-4 text-center text-xs text-slate-500">
    &copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. All rights reserved.
  </div>
</footer>
</body>
</html>
