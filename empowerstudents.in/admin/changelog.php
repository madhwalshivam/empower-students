<?php
/**
 * admin/changelog.php — recent file changes + patch markers
 *
 * Two things:
 *   1. Lists PHP files sorted by mtime (newest first)
 *   2. Scans each for known patch-version markers (e.g. // partial-v1:, // worker-v3:)
 *      so you can see "what was added when"
 *
 * Search box filters by filename.
 */
require __DIR__ . '/_admin.php';

// Scan recursively for *.php and *.md files
function scan_files(string $root, int $limit = 200): array {
    $out = [];
    $skip_dirs = ['vendor', 'node_modules', 'data', 'logs', '.git', 'tmp', 'cache', 'uploads', 'reports', 'leda'];
    $rii = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($file, $key, $iterator) use ($skip_dirs) {
                $bn = $file->getBasename();
                if ($bn[0] === '.') return false;
                if ($file->isDir() && in_array($bn, $skip_dirs, true)) return false;
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'md', 'js', 'css', 'html'], true)) continue;
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/\\');
        $out[] = [
            'path'  => $file->getPathname(),
            'rel'   => $rel,
            'size'  => $file->getSize(),
            'mtime' => $file->getMTime(),
        ];
    }
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    return array_slice($out, 0, $limit);
}

// Find patch markers in a file (e.g. // partial-v1:, // worker-v3:, // home-course-v1)
function extract_markers(string $path, int $max = 20): array {
    if (!file_exists($path) || filesize($path) > 600000) return [];
    $content = @file_get_contents($path);
    if (!$content) return [];
    // Pattern: // {something}-v\d+: or /* {something}-v\d+: */ or <!-- {something}-v\d+ -->
    preg_match_all('#(?://|/\*|<!--)\s*([a-z][a-z0-9_-]*-v\d+(?::|\b))#i', $content, $m);
    $unique = array_unique($m[1] ?? []);
    return array_slice($unique, 0, $max);
}

$root = dirname(__DIR__);
$filter = trim((string)($_GET['q'] ?? ''));
$rows = scan_files($root, 300);

if ($filter !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($filter) {
        return stripos($r['rel'], $filter) !== false;
    }));
}

admin_layout_open('Changelog');
admin_render_flash();
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-bold">📋 Recent changes</h1>
    <p class="text-xs text-slate-500 mt-1">Files sorted by last modified. Patch markers (like <code>// partial-v1:</code>) show which release added what.</p>
  </div>
  <form method="GET" class="flex items-center gap-2">
    <input type="text" name="q" value="<?= e($filter) ?>" placeholder="Filter by filename…"
           class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-64">
    <button class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Filter</button>
    <?php if ($filter !== ''): ?>
      <a href="changelog.php" class="text-xs text-slate-500 hover:text-rose-600 underline">clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- KNOWN PATCHES at a glance -->
<details class="bg-white border border-slate-200 rounded-xl p-4 mb-5">
  <summary class="font-bold cursor-pointer text-sm">🏷 Known patch markers in this project (click to expand)</summary>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3 text-xs">
    <?php
    $known = [
      'eval-v2'             => 'Pricing pivot to ₹1000 evaluation + ₹4000 course',
      'worker-v3'           => 'Report worker switched to comprehensive_v3_generate',
      'no-recharge-v1'      => "Reflection start reuses in-progress session (doesn't double-charge)",
      'partial-v1'          => 'End button → modal: Save & continue later / Generate report now',
      'timeout-fix-v1'      => 'Trim conversation history at turn 10+ to avoid Claude timeout',
      'end-redirect-v1'     => 'End now redirects to /evaluation-result.php (not /dashboard.php)',
      'home-course-v1'      => '7-day home course module',
      'home-climate'        => 'Emotion analysis hook after reflection',
      'v3-engine'           => 'V3 9-area structured listing engine',
      'p-flow'              => 'Pediatrician inline signup flow (p.php + p-api.php)',
    ];
    foreach ($known as $m => $desc): ?>
      <div class="bg-slate-50 rounded-lg p-2.5">
        <code class="text-indigo-700 font-bold"><?= e($m) ?></code><br>
        <span class="text-slate-600"><?= e($desc) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</details>

<!-- FILE LIST -->
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
        <th class="text-left px-4 py-2">File</th>
        <th class="text-left px-4 py-2">Last modified</th>
        <th class="text-right px-4 py-2">Size</th>
        <th class="text-left px-4 py-2">Patch markers</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $age = time() - $r['mtime'];
        $age_label = $age < 3600 ? round($age / 60) . ' min ago'
                  : ($age < 86400 ? round($age / 3600) . ' hr ago'
                  : ($age < 86400 * 7 ? round($age / 86400) . ' d ago'
                  : date('M j, Y', $r['mtime'])));
        $age_class = $age < 86400 ? 'text-emerald-700 font-semibold'
                  : ($age < 86400 * 3 ? 'text-amber-700'
                  : 'text-slate-500');
        $size_kb = $r['size'] < 1024 ? $r['size'] . ' B' : round($r['size'] / 1024, 1) . ' KB';
        $markers = extract_markers($r['path']);
      ?>
      <tr class="border-b border-slate-100 hover:bg-slate-50">
        <td class="px-4 py-2 font-mono text-xs"><?= e($r['rel']) ?></td>
        <td class="px-4 py-2 <?= $age_class ?>"><?= e($age_label) ?>
          <span class="text-slate-400 text-[10px] block"><?= date('Y-m-d H:i', $r['mtime']) ?></span>
        </td>
        <td class="px-4 py-2 text-right text-slate-500"><?= $size_kb ?></td>
        <td class="px-4 py-2">
          <?php if ($markers): ?>
            <?php foreach ($markers as $m): ?>
              <code class="inline-block bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded text-[10px] font-bold mr-1 mb-1"><?= e(rtrim($m, ':')) ?></code>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="text-slate-300">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">No files match.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="text-xs text-slate-500 mt-4">
  Showing top <?= count($rows) ?> files. Skip dirs: vendor, data, logs, .git, uploads, reports, leda.
</p>

<?php admin_layout_close();
