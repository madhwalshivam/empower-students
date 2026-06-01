<?php
/**
 * admin/evaluations.php — site-wide list of all reflection sessions
 *
 * Each row: parent · child · started · status · areas covered · cost · partner · report
 * Per-row actions: View report · Force regenerate PDF · Refund · Send WhatsApp · Mark followed-up
 *
 * Filters: status, date range, partner, has-report
 */
require __DIR__ . '/_admin.php';

// ─── Handle actions ────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if (!$sid) {
        flash('Bad session id', 'rose');
        header('Location: /admin/evaluations.php'); exit;
    }

    // Fetch the session + parent for context
    $st = db()->prepare("SELECT s.*, p.name AS parent_name, p.whatsapp AS parent_whatsapp, p.id AS pid
                          FROM parent_reflect_sessions s
                          LEFT JOIN parents p ON p.id = s.parent_id
                          WHERE s.id = ?");
    $st->execute([$sid]);
    $sess = $st->fetch();
    if (!$sess) { flash('Session not found', 'rose'); header('Location: /admin/evaluations.php'); exit; }
    $pid = (int)$sess['parent_id'];
    $cost = (int)$sess['cost_paid'];

    if ($action === 'force_regen') {
        // Re-generate the v3 report immediately
        try {
            if (file_exists(__DIR__ . '/../includes/comprehensive_report_v3.php')) {
                require_once __DIR__ . '/../includes/parent_eval_v3.php';
                require_once __DIR__ . '/../includes/comprehensive_report_v3.php';
                @pr_v3_generate_listing($sid, true);
                $r = comprehensive_v3_generate($sid, true);
                if (!empty($r['ok'])) {
                    flash("✓ Report regenerated: {$r['pdf_path']}", 'emerald');
                } else {
                    flash("Regen failed: " . ($r['error'] ?? '?'), 'rose');
                }
            } else {
                flash('v3 generator not installed', 'rose');
            }
        } catch (Throwable $e) {
            flash('Exception: ' . $e->getMessage(), 'rose');
        }
    }

    elseif ($action === 'refund') {
        if (!empty($sess['refunded_at'])) {
            flash('Already refunded', 'amber');
        } elseif ($cost <= 0) {
            flash('Session had no charge', 'amber');
        } else {
            try {
                $new_bal = wallet_post($pid, $cost, 'refund_unfinished_eval', $sid,
                                       "Admin refund: session #$sid", admin_user());
                db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN refunded_at TEXT");
            } catch (Throwable $_) {}
            try {
                db()->prepare("UPDATE parent_reflect_sessions SET refunded_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$sid]);
                flash("✓ Refunded ₹$cost to parent #$pid (new balance ₹$new_bal)", 'emerald');
            } catch (Throwable $e) {
                flash('Refund post failed: ' . $e->getMessage(), 'rose');
            }
        }
    }

    elseif ($action === 'mark_followup') {
        try {
            // Add followed_up_at column if missing
            $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
            if (!in_array('followed_up_at', array_column($cols, 'name'), true)) {
                @db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN followed_up_at TEXT");
                @db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN followed_up_by TEXT");
                @db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN followup_notes TEXT");
            }
            db()->prepare("UPDATE parent_reflect_sessions SET followed_up_at = CURRENT_TIMESTAMP, followed_up_by = ?, followup_notes = ? WHERE id = ?")
               ->execute([admin_user(), trim((string)($_POST['notes'] ?? '')), $sid]);
            flash("✓ Marked as followed-up", 'emerald');
        } catch (Throwable $e) {
            flash('Mark failed: ' . $e->getMessage(), 'rose');
        }
    }

    elseif ($action === 'gen_wa_link') {
        // Build a WhatsApp share link
        $report_url = (string)($sess['report_pdf_path'] ?? '');
        if (!$report_url) {
            flash('No report URL yet — generate the report first', 'amber');
        } else {
            $host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
            $full = 'https://' . $host . $report_url;
            $msg = "Namaste " . ($sess['parent_name'] ?: 'Parent') . " — आपकी parenting evaluation report तैयार है। यहाँ देखें: $full · Team EmpowerStudents.in";
            $waphone = preg_replace('/\D+/', '', (string)$sess['parent_whatsapp']);
            $url = "https://wa.me/$waphone?text=" . urlencode($msg);
            $_SESSION['admin_flash'] = ['msg' => 'WhatsApp link generated below ↓', 'tone' => 'emerald', 'wa_url' => $url];
        }
    }

    header('Location: /admin/evaluations.php' . (!empty($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}

// ─── Filters ────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_q = trim((string)($_GET['q'] ?? ''));
$filter_partner = (int)($_GET['partner'] ?? 0);
$filter_has_report = $_GET['has_report'] ?? '';

$where = [];
$params = [];
if ($filter_status) {
    $where[] = 's.status = ?';
    $params[] = $filter_status;
}
if ($filter_q !== '') {
    $where[] = '(LOWER(p.name) LIKE ? OR p.whatsapp LIKE ?)';
    $params[] = '%' . strtolower($filter_q) . '%';
    $params[] = '%' . $filter_q . '%';
}
if ($filter_partner > 0) {
    $where[] = 'p.partner_id = ?';
    $params[] = $filter_partner;
}
if ($filter_has_report === 'yes') {
    $where[] = "s.report_pdf_path IS NOT NULL AND s.report_pdf_path != ''";
} elseif ($filter_has_report === 'no') {
    $where[] = "(s.report_pdf_path IS NULL OR s.report_pdf_path = '')";
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Defensive: refunded_at + followed_up_at may not exist
$session_cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
$cn = array_column($session_cols, 'name');
$has_refunded = in_array('refunded_at', $cn, true);
$has_followup = in_array('followed_up_at', $cn, true);

$sel_extras = '';
if ($has_refunded) $sel_extras .= ', s.refunded_at';
if ($has_followup) $sel_extras .= ', s.followed_up_at, s.followed_up_by';

$sql = "SELECT s.id, s.parent_id, s.status, s.cost_paid, s.started_at, s.completed_at, s.report_pdf_path,
               s.turn_count, s.v3_listing_json $sel_extras,
               p.name AS parent_name, p.whatsapp AS parent_whatsapp, p.partner_id,
               pt.name AS partner_name, pt.referral_code AS partner_code
        FROM parent_reflect_sessions s
        LEFT JOIN parents p ON p.id = s.parent_id
        LEFT JOIN partners pt ON pt.id = p.partner_id
        $where_sql
        ORDER BY s.id DESC LIMIT 200";

$rows = db()->prepare($sql);
$rows->execute($params);
$rows = $rows->fetchAll();

// Partner dropdown
$partners = [];
try {
    $partners = db()->query("SELECT id, name, referral_code FROM partners WHERE status = 'active' ORDER BY name")->fetchAll();
} catch (Throwable $_) {}

// Stats
$total = count($rows);
$completed = 0; $with_report = 0; $refunded = 0; $followed = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'completed') $completed++;
    if (!empty($r['report_pdf_path'])) $with_report++;
    if ($has_refunded && !empty($r['refunded_at'])) $refunded++;
    if ($has_followup && !empty($r['followed_up_at'])) $followed++;
}

admin_layout_open('Evaluations');
admin_render_flash();

// Show generated WA link if just produced
if (!empty($_SESSION['admin_flash_wa_url'])) {
    $wa = $_SESSION['admin_flash_wa_url']; unset($_SESSION['admin_flash_wa_url']);
    echo '<div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 mb-4">';
    echo '<a href="' . e($wa) . '" target="_blank" class="text-emerald-700 font-bold underline">Open WhatsApp share link →</a>';
    echo '</div>';
}
?>

<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-bold">🎙 Evaluations</h1>
    <p class="text-xs text-slate-500 mt-1">All reflection sessions, newest first. Showing up to 200.</p>
  </div>
  <div class="flex gap-2 text-xs">
    <a href="?status=completed" class="bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full <?= $filter_status === 'completed' ? 'ring-2 ring-emerald-400' : '' ?>">✓ Completed (<?= $completed ?>)</a>
    <a href="?status=in_progress" class="bg-amber-50 text-amber-700 px-3 py-1.5 rounded-full <?= $filter_status === 'in_progress' ? 'ring-2 ring-amber-400' : '' ?>">⏸ In progress</a>
    <a href="?status=abandoned" class="bg-rose-50 text-rose-700 px-3 py-1.5 rounded-full <?= $filter_status === 'abandoned' ? 'ring-2 ring-rose-400' : '' ?>">✕ Abandoned</a>
    <a href="?has_report=no" class="bg-slate-100 text-slate-700 px-3 py-1.5 rounded-full <?= $filter_has_report === 'no' ? 'ring-2 ring-slate-400' : '' ?>">No PDF yet</a>
    <?php if ($filter_status || $filter_q || $filter_partner || $filter_has_report): ?>
      <a href="evaluations.php" class="text-rose-600 hover:underline px-3 py-1.5">clear</a>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="bg-white border border-slate-200 rounded-xl p-4 mb-5 flex flex-wrap gap-3 items-end">
  <div>
    <label class="block text-xs font-semibold text-slate-600 mb-1">Parent name / WhatsApp</label>
    <input type="text" name="q" value="<?= e($filter_q) ?>" placeholder="Jyoti or +9198…" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm w-56">
  </div>
  <div>
    <label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
    <select name="status" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
      <option value="">All</option>
      <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
      <option value="in_progress" <?= $filter_status === 'in_progress' ? 'selected' : '' ?>>In progress</option>
      <option value="abandoned" <?= $filter_status === 'abandoned' ? 'selected' : '' ?>>Abandoned</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-semibold text-slate-600 mb-1">Referring partner</label>
    <select name="partner" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
      <option value="0">All</option>
      <?php foreach ($partners as $pt): ?>
        <option value="<?= (int)$pt['id'] ?>" <?= $filter_partner === (int)$pt['id'] ? 'selected' : '' ?>>
          <?= e($pt['name']) ?> (<?= e($pt['referral_code']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-semibold text-slate-600 mb-1">Report</label>
    <select name="has_report" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
      <option value="">All</option>
      <option value="yes" <?= $filter_has_report === 'yes' ? 'selected' : '' ?>>Has PDF</option>
      <option value="no" <?= $filter_has_report === 'no' ? 'selected' : '' ?>>No PDF yet</option>
    </select>
  </div>
  <button class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm font-bold">Apply</button>
</form>

<!-- Quick stats -->
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
  <div class="bg-white border border-slate-200 rounded-xl p-3 text-center">
    <div class="text-2xl font-bold text-slate-900"><?= $total ?></div>
    <div class="text-xs text-slate-500">Total shown</div>
  </div>
  <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center">
    <div class="text-2xl font-bold text-emerald-700"><?= $completed ?></div>
    <div class="text-xs text-emerald-600">Completed</div>
  </div>
  <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 text-center">
    <div class="text-2xl font-bold text-indigo-700"><?= $with_report ?></div>
    <div class="text-xs text-indigo-600">Has PDF</div>
  </div>
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center">
    <div class="text-2xl font-bold text-amber-700"><?= $refunded ?></div>
    <div class="text-xs text-amber-600">Refunded</div>
  </div>
  <div class="bg-cyan-50 border border-cyan-200 rounded-xl p-3 text-center">
    <div class="text-2xl font-bold text-cyan-700"><?= $followed ?></div>
    <div class="text-xs text-cyan-600">Followed-up</div>
  </div>
</div>

<!-- Table -->
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
        <th class="text-left px-3 py-2">#</th>
        <th class="text-left px-3 py-2">Parent</th>
        <th class="text-left px-3 py-2">Started</th>
        <th class="text-left px-3 py-2">Status</th>
        <th class="text-center px-3 py-2">Areas</th>
        <th class="text-right px-3 py-2">Cost</th>
        <th class="text-left px-3 py-2">Partner</th>
        <th class="text-left px-3 py-2">Report</th>
        <th class="text-right px-3 py-2">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $sid = (int)$r['id'];
        $listing = json_decode((string)($r['v3_listing_json'] ?? ''), true) ?: [];
        $covered = 0;
        if (!empty($listing['areas'])) {
            foreach ($listing['areas'] as $a) if (!empty($a['covered'])) $covered++;
        }
        $turns = (int)$r['turn_count'];
        $is_refunded = $has_refunded && !empty($r['refunded_at']);
        $is_followed = $has_followup && !empty($r['followed_up_at']);

        $status_color = [
          'completed'   => 'bg-emerald-100 text-emerald-700',
          'in_progress' => 'bg-amber-100 text-amber-700',
          'abandoned'   => 'bg-rose-100 text-rose-700',
        ][$r['status']] ?? 'bg-slate-100 text-slate-700';
      ?>
      <tr class="border-b border-slate-100 hover:bg-slate-50 <?= $is_refunded ? 'opacity-60' : '' ?>">
        <td class="px-3 py-2 font-mono text-xs">#<?= $sid ?></td>
        <td class="px-3 py-2">
          <a href="/admin/parent.php?id=<?= (int)$r['parent_id'] ?>" class="text-indigo-600 hover:underline font-semibold">
            <?= e($r['parent_name'] ?: '— unnamed —') ?>
          </a>
          <div class="text-[10px] text-slate-500"><?= e($r['parent_whatsapp']) ?></div>
        </td>
        <td class="px-3 py-2 text-xs text-slate-600">
          <?= e(date('M j, H:i', strtotime((string)$r['started_at']))) ?>
          <?php if ($r['completed_at']): ?>
            <div class="text-[10px] text-emerald-600">done <?= e(date('M j, H:i', strtotime((string)$r['completed_at']))) ?></div>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2">
          <span class="<?= $status_color ?> px-2 py-0.5 rounded-full text-[10px] font-bold uppercase"><?= e($r['status']) ?></span>
          <?php if ($is_refunded): ?>
            <div class="text-[10px] text-rose-600 mt-1">↩ refunded</div>
          <?php endif; ?>
          <?php if ($is_followed): ?>
            <div class="text-[10px] text-cyan-600 mt-1">✓ followed-up</div>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 text-center">
          <?php if ($covered > 0): ?>
            <span class="font-bold <?= $covered >= 8 ? 'text-emerald-700' : ($covered >= 5 ? 'text-amber-700' : 'text-rose-700') ?>">
              <?= $covered ?>/9
            </span>
            <div class="text-[10px] text-slate-400"><?= $turns ?>t</div>
          <?php else: ?>
            <span class="text-slate-400"><?= $turns ?>t</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 text-right font-mono">₹<?= (int)$r['cost_paid'] ?></td>
        <td class="px-3 py-2 text-xs">
          <?php if ($r['partner_code']): ?>
            <span class="bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded text-[10px] font-bold"><?= e($r['partner_code']) ?></span>
            <div class="text-[10px] text-slate-500 mt-0.5"><?= e($r['partner_name']) ?></div>
          <?php else: ?>
            <span class="text-slate-300">direct</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2">
          <?php if ($r['report_pdf_path']): ?>
            <a href="<?= e($r['report_pdf_path']) ?>" target="_blank" class="text-emerald-600 hover:underline text-xs font-semibold">View →</a>
          <?php else: ?>
            <span class="text-slate-400 text-xs">—</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 text-right">
          <details class="inline-block">
            <summary class="cursor-pointer text-xs text-slate-500 hover:text-indigo-600 select-none">⋯ actions</summary>
            <div class="absolute bg-white border border-slate-200 rounded-lg shadow-lg p-3 mt-1 right-4 z-10 min-w-[200px] space-y-2 text-xs">
              <form method="POST" class="m-0">
                <input type="hidden" name="session_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="force_regen">
                <button class="w-full text-left px-2 py-1 hover:bg-indigo-50 rounded text-indigo-700">🔄 Regenerate report (v3)</button>
              </form>
              <?php if ($r['report_pdf_path']): ?>
              <form method="POST" class="m-0">
                <input type="hidden" name="session_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="gen_wa_link">
                <button class="w-full text-left px-2 py-1 hover:bg-emerald-50 rounded text-emerald-700">💬 WhatsApp share link</button>
              </form>
              <?php endif; ?>
              <?php if (!$is_refunded && (int)$r['cost_paid'] > 0): ?>
              <form method="POST" class="m-0" onsubmit="return confirm('Refund ₹<?= (int)$r['cost_paid'] ?> to parent?')">
                <input type="hidden" name="session_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="refund">
                <button class="w-full text-left px-2 py-1 hover:bg-rose-50 rounded text-rose-700">↩ Refund ₹<?= (int)$r['cost_paid'] ?></button>
              </form>
              <?php endif; ?>
              <?php if (!$is_followed): ?>
              <form method="POST" class="m-0" onsubmit="this.querySelector('[name=notes]').value = prompt('Follow-up notes (optional):', ''); return this.querySelector('[name=notes]').value !== null;">
                <input type="hidden" name="session_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="mark_followup">
                <input type="hidden" name="notes" value="">
                <button class="w-full text-left px-2 py-1 hover:bg-cyan-50 rounded text-cyan-700">✓ Mark followed-up</button>
              </form>
              <?php endif; ?>
              <a href="/admin/parent.php?id=<?= (int)$r['parent_id'] ?>" class="block px-2 py-1 hover:bg-slate-50 rounded text-slate-700">→ Open parent</a>
            </div>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No evaluations match these filters.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
// Stash any WA url from this turn into a flash for the next page load
if (!empty($_SESSION['admin_flash']['wa_url'])) {
    $_SESSION['admin_flash_wa_url'] = $_SESSION['admin_flash']['wa_url'];
    unset($_SESSION['admin_flash']['wa_url']);
}
admin_layout_close();
