<?php
/**
 * admin/leads.php — view & manage homepage form leads
 */
require __DIR__ . '/_admin.php';

// Ensure leads table exists (in case admin opens this before any lead came in)
db()->exec("CREATE TABLE IF NOT EXISTS leads (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_name   TEXT NOT NULL,
    phone         TEXT NOT NULL,
    child_age     TEXT,
    concern       TEXT,
    message       TEXT,
    source        TEXT,
    utm_source    TEXT,
    utm_medium    TEXT,
    utm_campaign  TEXT,
    utm_content   TEXT,
    utm_term      TEXT,
    referrer      TEXT,
    user_agent    TEXT,
    ip            TEXT,
    status        TEXT DEFAULT 'new',
    notes         TEXT,
    created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);");

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'update_status' && $id > 0) {
        $status = $_POST['status'] ?? 'new';
        $notes  = trim((string)($_POST['notes'] ?? ''));
        $allowed = ['new','contacted','booked','converted','lost','spam'];
        if (!in_array($status, $allowed, true)) $status = 'new';
        db()->prepare('UPDATE leads SET status=?, notes=? WHERE id=?')->execute([$status, $notes, $id]);
        flash("Lead #$id updated.");
        header('Location: /admin/leads.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        db()->prepare('DELETE FROM leads WHERE id=?')->execute([$id]);
        flash("Lead #$id deleted.", 'rose');
        header('Location: /admin/leads.php');
        exit;
    }
}

// Filters
$filter = $_GET['filter'] ?? 'all';
$where  = '1=1';
$params = [];
if (in_array($filter, ['new','contacted','booked','converted','lost','spam'], true)) {
    $where = 'status = ?'; $params = [$filter];
}

$rows = db()->prepare("SELECT * FROM leads WHERE $where ORDER BY id DESC LIMIT 500");
$rows->execute($params);
$leads = $rows->fetchAll();

// Counts per status
$counts = [];
$cstmt = db()->query("SELECT status, COUNT(*) c FROM leads GROUP BY status");
foreach ($cstmt->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; }
$counts['all'] = array_sum($counts);

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="empowerstudents-leads-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Created','Status','Name','Phone','Child Age','Concern','Source','UTM Source','UTM Medium','UTM Campaign','Notes']);
    $allStmt = db()->prepare("SELECT * FROM leads WHERE $where ORDER BY id DESC");
    $allStmt->execute($params);
    foreach ($allStmt as $r) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['status'],
            $r['parent_name'], $r['phone'], $r['child_age'], $r['concern'],
            $r['source'], $r['utm_source'], $r['utm_medium'], $r['utm_campaign'],
            $r['notes']
        ]);
    }
    fclose($out);
    exit;
}

admin_layout_open('Leads');
admin_render_flash();
?>

<div class="flex items-center justify-between flex-wrap gap-3 mb-4">
  <div>
    <h1 class="text-2xl font-bold">📞 Leads</h1>
    <p class="text-sm text-slate-500">Free-evaluation form submissions from <strong>empowerstudents.in</strong></p>
  </div>
  <div class="flex gap-2">
    <a href="?filter=<?= e($filter) ?>&export=csv" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700">
      ⬇ Export CSV
    </a>
  </div>
</div>

<!-- Filter pills -->
<div class="flex flex-wrap gap-2 mb-5 bg-white border border-slate-200 rounded-xl p-3">
  <?php
  $tabs = [
    ['all','All','slate'],
    ['new','New','indigo'],
    ['contacted','Contacted','sky'],
    ['booked','Booked','violet'],
    ['converted','Converted','emerald'],
    ['lost','Lost','rose'],
    ['spam','Spam','slate'],
  ];
  foreach ($tabs as $t):
    [$key,$label,$color] = $t;
    $active = $filter === $key;
    $count = $counts[$key] ?? 0;
    $cls = $active
      ? "bg-{$color}-600 text-white"
      : "bg-{$color}-50 text-{$color}-700 hover:bg-{$color}-100";
  ?>
    <a href="?filter=<?= e($key) ?>" class="<?= $cls ?> px-3 py-1.5 rounded-full text-sm font-semibold flex items-center gap-2">
      <?= e($label) ?>
      <span class="bg-white/30 <?= $active ? 'text-white' : 'text-' . $color . '-700 bg-white' ?> rounded-full px-2 py-0.5 text-xs"><?= $count ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($leads)): ?>
  <div class="bg-white border border-slate-200 rounded-xl p-12 text-center">
    <div class="text-5xl mb-3">📭</div>
    <p class="text-slate-600">No leads in this view yet.</p>
    <p class="text-xs text-slate-400 mt-2">Leads from the homepage free-evaluation form will appear here, and an email will be sent to <strong>drpankajjha@gmail.com</strong>.</p>
  </div>
<?php else: ?>
  <div class="space-y-3">
    <?php foreach ($leads as $lead):
      $concern_label = [
        'speech'        => 'Speech / Language',
        'behaviour'     => 'Behaviour / Emotional',
        'autism'        => 'Autism / Developmental',
        'learning'      => 'Learning Difficulty',
        'adhd'          => 'ADHD / Focus',
        'sensory_motor' => 'Sensory / Motor',
        'not_sure'      => 'Needs guidance',
      ][$lead['concern']] ?? $lead['concern'];

      $status_color = [
        'new'       => 'indigo',
        'contacted' => 'sky',
        'booked'    => 'violet',
        'converted' => 'emerald',
        'lost'      => 'rose',
        'spam'      => 'slate',
      ][$lead['status']] ?? 'slate';

      $phone_clean = preg_replace('/\D/', '', $lead['phone']);
      $wa_link  = 'https://wa.me/' . $phone_clean
                . '?text=' . rawurlencode("Hi " . $lead['parent_name'] . ", this is from EmpowerStudents.in regarding your free evaluation request. When is a good time to call?");
      $tel_link = 'tel:' . $lead['phone'];
      $is_new   = $lead['status'] === 'new';
    ?>
      <div class="bg-white border <?= $is_new ? 'border-indigo-300 ring-2 ring-indigo-100' : 'border-slate-200' ?> rounded-xl p-4 sm:p-5 hover:shadow-md transition-shadow">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="text-lg font-bold text-slate-800"><?= e($lead['parent_name']) ?></h3>
              <span class="bg-<?= $status_color ?>-100 text-<?= $status_color ?>-700 text-xs font-bold uppercase tracking-wide px-2 py-0.5 rounded-full">
                <?= e($lead['status']) ?>
              </span>
              <?php if ($is_new): ?>
                <span class="bg-amber-100 text-amber-800 text-xs font-bold px-2 py-0.5 rounded-full">⏰ Reach within 2hr</span>
              <?php endif; ?>
            </div>
            <div class="text-sm text-slate-500 mt-0.5">
              #<?= (int)$lead['id'] ?> &middot; <?= e(date('d M, H:i', strtotime($lead['created_at']))) ?>
              <?php if ($lead['source']): ?> &middot; <?= e($lead['source']) ?><?php endif; ?>
            </div>
          </div>
          <div class="flex gap-2">
            <a href="<?= e($wa_link) ?>" target="_blank" rel="noopener"
               class="bg-emerald-500 text-white px-3 py-1.5 rounded-full text-sm font-semibold hover:bg-emerald-600 inline-flex items-center gap-1">
              💬 WhatsApp
            </a>
            <a href="<?= e($tel_link) ?>"
               class="bg-slate-800 text-white px-3 py-1.5 rounded-full text-sm font-semibold hover:bg-slate-900 inline-flex items-center gap-1">
              📞 Call
            </a>
          </div>
        </div>

        <div class="grid sm:grid-cols-3 gap-3 text-sm mb-3">
          <div><span class="text-slate-500">Phone:</span> <a href="<?= e($tel_link) ?>" class="font-mono text-indigo-700 hover:underline"><?= e($lead['phone']) ?></a></div>
          <div><span class="text-slate-500">Child age:</span> <strong><?= e($lead['child_age'] ?: '—') ?></strong></div>
          <div><span class="text-slate-500">Concern:</span> <strong class="text-rose-600"><?= e($concern_label ?: '—') ?></strong></div>
        </div>

        <?php if ($lead['utm_source'] || $lead['utm_campaign']): ?>
          <div class="text-xs text-slate-500 font-mono mb-3 bg-slate-50 px-2 py-1 rounded">
            🎯
            <?php if ($lead['utm_source']):   ?>src=<?= e($lead['utm_source']) ?> <?php endif; ?>
            <?php if ($lead['utm_medium']):   ?>med=<?= e($lead['utm_medium']) ?> <?php endif; ?>
            <?php if ($lead['utm_campaign']): ?>camp=<?= e($lead['utm_campaign']) ?> <?php endif; ?>
            <?php if ($lead['utm_content']):  ?>ad=<?= e($lead['utm_content']) ?><?php endif; ?>
          </div>
        <?php endif; ?>

        <details class="mt-2">
          <summary class="text-sm text-indigo-600 cursor-pointer font-semibold">Update status / add notes</summary>
          <form method="post" class="mt-3 grid sm:grid-cols-3 gap-2">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
            <select name="status" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <?php foreach (['new','contacted','booked','converted','lost','spam'] as $s): ?>
                <option value="<?= $s ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="notes" placeholder="Notes (optional)"
                   value="<?= e($lead['notes'] ?? '') ?>"
                   class="sm:col-span-1 border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <div class="flex gap-2">
              <button type="submit" class="bg-indigo-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-indigo-700">Save</button>
              <button type="submit" formaction="?action=delete" name="action" value="delete"
                      onclick="return confirm('Delete lead #<?= (int)$lead['id'] ?>?')"
                      class="bg-rose-100 text-rose-700 rounded-lg px-3 py-2 text-sm font-semibold hover:bg-rose-200">Delete</button>
            </div>
          </form>
          <?php if (!empty($lead['notes'])): ?>
            <p class="text-xs text-slate-500 mt-2"><strong>Note:</strong> <?= e($lead['notes']) ?></p>
          <?php endif; ?>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="text-xs text-slate-400 mt-6 text-center">
  Email alerts go to <strong>drpankajjha@gmail.com</strong> &middot;
  showing latest 500 records &middot; total: <?= (int)$counts['all'] ?>
</p>

<?php admin_layout_close();
