<?php
/**
 * pediatrician.php — pediatrician dashboard (private to each partner)
 *
 * Requires authentication via cookie set by pediatrician-auth.php.
 * Renders TWO sections:
 *   1. Patients — list of parents referred by this partner with reports + actions
 *   2. Earnings — commission ledger
 *
 * Actions (POST same page):
 *   action=share_report   {session_id} → returns WhatsApp share URL
 *   action=gift_credits   {parent_id, amount} → posts wallet credit to that parent
 *   action=apply_discount {parent_id, percent, valid_days} → creates a discount code
 *   action=logout         → clears session
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/pediatrician-auth.php';

$me = ped_current_partner();
if (!$me) {
    header('Location: /pediatrician-auth.php');
    exit;
}
$partner_id = (int)$me['id'];

// ─── Action handlers ────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        _ped_destroy_session();
        header('Location: /pediatrician-auth.php');
        exit;
    }

    if ($action === 'share_report') {
        $sid = (int)($_POST['session_id'] ?? 0);
        // Verify session belongs to one of MY referred parents
        $st = db()->prepare("SELECT s.id, s.report_pdf_path, p.name AS parent_name, p.whatsapp
                              FROM parent_reflect_sessions s
                              JOIN parents p ON p.id = s.parent_id
                              WHERE s.id = ? AND p.partner_id = ?");
        $st->execute([$sid, $partner_id]);
        $r = $st->fetch();
        if (!$r) { $flash = ['tone' => 'rose', 'msg' => 'Session not found or not yours.']; }
        elseif (empty($r['report_pdf_path'])) { $flash = ['tone' => 'amber', 'msg' => 'Report not generated yet — usually ready 1 hour after evaluation.']; }
        else {
            $host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
            $url = 'https://' . $host . $r['report_pdf_path'];
            $msg = "Namaste " . ($r['parent_name'] ?: 'Parent') . " — आपकी parenting evaluation report तैयार है। यहाँ देखें: $url · Team EmpowerStudents.in";
            $waphone = preg_replace('/\D+/', '', (string)$r['whatsapp']);
            $share_url = "https://wa.me/$waphone?text=" . urlencode($msg);
            $flash = ['tone' => 'emerald', 'msg' => 'WhatsApp link ready below ↓', 'share_url' => $share_url];
        }
    }

    if ($action === 'gift_credits') {
        $pid = (int)($_POST['parent_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        // Verify parent is referred by me
        $st = db()->prepare("SELECT id, name FROM parents WHERE id = ? AND partner_id = ?");
        $st->execute([$pid, $partner_id]);
        $par = $st->fetch();
        if (!$par)       { $flash = ['tone' => 'rose', 'msg' => 'Parent not referred by you.']; }
        elseif ($amount <= 0 || $amount > 5000) { $flash = ['tone' => 'rose', 'msg' => 'Amount must be between ₹1 and ₹5000.']; }
        else {
            try {
                $new_bal = wallet_post(
                    $pid,
                    $amount,
                    'partner_gift',
                    $partner_id,
                    "Gift from " . ($me['contact_name'] ?: $me['name']),
                    'partner:' . $me['referral_code']
                );
                // Audit row in a partner_actions table
                _ped_log_action($partner_id, 'gift_credits', $pid, ['amount' => $amount]);
                $flash = ['tone' => 'emerald', 'msg' => "✓ Gifted ₹$amount to {$par['name']} (new balance: ₹$new_bal)"];
            } catch (Throwable $e) {
                $flash = ['tone' => 'rose', 'msg' => 'Gift failed: ' . $e->getMessage()];
            }
        }
    }

    if ($action === 'apply_discount') {
        $pid = (int)($_POST['parent_id'] ?? 0);
        $percent = (int)($_POST['percent'] ?? 0);
        $valid_days = max(1, min(30, (int)($_POST['valid_days'] ?? 7)));
        $st = db()->prepare("SELECT id, name FROM parents WHERE id = ? AND partner_id = ?");
        $st->execute([$pid, $partner_id]);
        $par = $st->fetch();
        if (!$par) { $flash = ['tone' => 'rose', 'msg' => 'Parent not referred by you.']; }
        elseif ($percent < 5 || $percent > 50) { $flash = ['tone' => 'rose', 'msg' => 'Discount must be 5% to 50%.']; }
        else {
            // Create discount row
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS partner_discounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code TEXT UNIQUE,
                    partner_id INTEGER,
                    parent_id INTEGER,
                    percent INTEGER,
                    valid_until TEXT,
                    used_at TEXT,
                    used_on_order TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )");
                $code = strtoupper($me['referral_code']) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $valid_until = date('Y-m-d H:i:s', time() + 86400 * $valid_days);
                db()->prepare("INSERT INTO partner_discounts (code, partner_id, parent_id, percent, valid_until) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$code, $partner_id, $pid, $percent, $valid_until]);
                _ped_log_action($partner_id, 'apply_discount', $pid, ['code' => $code, 'percent' => $percent, 'valid_until' => $valid_until]);
                $flash = ['tone' => 'emerald', 'msg' => "✓ Discount code <strong>$code</strong> created — $percent% off, valid till " . date('M j', strtotime($valid_until))];
            } catch (Throwable $e) {
                $flash = ['tone' => 'rose', 'msg' => 'Discount failed: ' . $e->getMessage()];
            }
        }
    }
}

function _ped_log_action(int $partner_id, string $type, int $parent_id, array $meta): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS partner_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            partner_id INTEGER NOT NULL,
            parent_id INTEGER,
            action_type TEXT,
            meta_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        db()->prepare("INSERT INTO partner_actions (partner_id, parent_id, action_type, meta_json) VALUES (?, ?, ?, ?)")
           ->execute([$partner_id, $parent_id, $type, json_encode($meta)]);
    } catch (Throwable $_) {}
}

// ─── Stats ────────────────────────────────────────
$stats_parents     = (int)db()->prepare("SELECT COUNT(*) FROM parents WHERE partner_id = ?")->execute([$partner_id]) ?: 0;
$stats_parents     = (int)db()->query("SELECT COUNT(*) FROM parents WHERE partner_id = $partner_id")->fetchColumn();
$stats_completed   = (int)db()->query("SELECT COUNT(*) FROM parent_reflect_sessions s JOIN parents p ON p.id = s.parent_id WHERE p.partner_id = $partner_id AND s.status = 'completed'")->fetchColumn();
$stats_in_progress = (int)db()->query("SELECT COUNT(*) FROM parent_reflect_sessions s JOIN parents p ON p.id = s.parent_id WHERE p.partner_id = $partner_id AND s.status = 'in_progress'")->fetchColumn();

// Earnings — from partner_payouts ledger
$stats_earned = 0; $stats_paid = 0;
try {
    $stats_earned = (float)db()->query("SELECT COALESCE(SUM(partner_amount), 0) FROM partner_payouts WHERE partner_id = $partner_id")->fetchColumn();
    $stats_paid   = (float)db()->query("SELECT COALESCE(SUM(partner_amount), 0) FROM partner_payouts WHERE partner_id = $partner_id AND paid_at IS NOT NULL")->fetchColumn();
} catch (Throwable $_) {}
$stats_owed = max(0, $stats_earned - $stats_paid);

// Active tab
$tab = $_GET['tab'] ?? 'patients';

// Parent list (always pulled, used by patients tab)
$st = db()->prepare("SELECT p.id, p.name, p.whatsapp, p.created_at AS reg_at,
                            (SELECT COUNT(*) FROM parent_reflect_sessions s WHERE s.parent_id = p.id AND s.status = 'completed') AS n_done,
                            (SELECT COUNT(*) FROM parent_reflect_sessions s WHERE s.parent_id = p.id AND s.status = 'in_progress') AS n_pending,
                            (SELECT id FROM parent_reflect_sessions s WHERE s.parent_id = p.id ORDER BY s.id DESC LIMIT 1) AS last_session_id,
                            (SELECT status FROM parent_reflect_sessions s WHERE s.parent_id = p.id ORDER BY s.id DESC LIMIT 1) AS last_status,
                            (SELECT report_pdf_path FROM parent_reflect_sessions s WHERE s.parent_id = p.id ORDER BY s.id DESC LIMIT 1) AS last_report
                      FROM parents p
                      WHERE p.partner_id = ?
                      ORDER BY p.id DESC");
$st->execute([$partner_id]);
$parents = $st->fetchAll();

// Earnings ledger
$earnings = [];
try {
    $st = db()->prepare("SELECT pp.*, p.name AS parent_name
                          FROM partner_payouts pp
                          LEFT JOIN parents p ON p.id = pp.parent_id
                          WHERE pp.partner_id = ?
                          ORDER BY pp.id DESC LIMIT 100");
    $st->execute([$partner_id]);
    $earnings = $st->fetchAll();
} catch (Throwable $_) {}

$doctor_display = $me['contact_name'] ?: $me['name'];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard · <?= htmlspecialchars($doctor_display) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>body{font-family:'DM Sans',system-ui,sans-serif}</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">

<!-- Top bar -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-10 shadow-sm">
  <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 bg-emerald-600 rounded-lg flex items-center justify-center text-white font-bold text-lg">E</div>
      <div>
        <div class="font-bold text-slate-900 text-sm leading-tight"><?= htmlspecialchars($me['name']) ?></div>
        <div class="text-xs text-slate-500"><?= htmlspecialchars($doctor_display) ?> · <span class="font-mono"><?= htmlspecialchars($me['referral_code']) ?></span></div>
      </div>
    </div>
    <form method="POST" class="m-0">
      <input type="hidden" name="action" value="logout">
      <button class="text-xs text-slate-500 hover:text-rose-600 underline">Sign out</button>
    </form>
  </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-5 space-y-4">

  <?php if ($flash): ?>
    <div class="bg-<?= htmlspecialchars($flash['tone']) ?>-50 border border-<?= htmlspecialchars($flash['tone']) ?>-200 text-<?= htmlspecialchars($flash['tone']) ?>-900 rounded-xl p-3 text-sm">
      <?= $flash['msg'] ?>
      <?php if (!empty($flash['share_url'])): ?>
        <a href="<?= htmlspecialchars($flash['share_url']) ?>" target="_blank" class="block mt-2 font-bold underline">→ Open WhatsApp share</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
    <div class="bg-white border border-slate-200 rounded-xl p-3 text-center">
      <div class="text-2xl font-bold text-slate-900"><?= $stats_parents ?></div>
      <div class="text-xs text-slate-500">Total referred</div>
    </div>
    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center">
      <div class="text-2xl font-bold text-emerald-700"><?= $stats_completed ?></div>
      <div class="text-xs text-emerald-600">Completed</div>
    </div>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center">
      <div class="text-2xl font-bold text-amber-700"><?= $stats_in_progress ?></div>
      <div class="text-xs text-amber-600">In progress</div>
    </div>
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 text-center">
      <div class="text-2xl font-bold text-indigo-700">₹<?= number_format($stats_earned) ?></div>
      <div class="text-xs text-indigo-600">Total earned</div>
    </div>
    <div class="bg-orange-50 border border-orange-200 rounded-xl p-3 text-center">
      <div class="text-2xl font-bold text-orange-700">₹<?= number_format($stats_owed) ?></div>
      <div class="text-xs text-orange-600">Owed to you</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="flex gap-2 border-b border-slate-200">
    <a href="?tab=patients" class="px-4 py-2 text-sm font-bold border-b-2 <?= $tab === 'patients' ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
      👥 Patients (<?= $stats_parents ?>)
    </a>
    <a href="?tab=earnings" class="px-4 py-2 text-sm font-bold border-b-2 <?= $tab === 'earnings' ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
      💰 Earnings (<?= count($earnings) ?>)
    </a>
    <a href="?tab=share" class="px-4 py-2 text-sm font-bold border-b-2 <?= $tab === 'share' ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
      📤 Share with patients
    </a>
  </div>

  <?php if ($tab === 'patients'): ?>

    <!-- PATIENTS TABLE -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
            <th class="text-left px-3 py-2">Parent</th>
            <th class="text-left px-3 py-2">Registered</th>
            <th class="text-left px-3 py-2">Latest status</th>
            <th class="text-left px-3 py-2">Report</th>
            <th class="text-right px-3 py-2">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($parents as $p):
            $reg = date('M j, Y', strtotime((string)$p['reg_at']));
            $last_status = $p['last_status'] ?: 'none';
            $status_color = ['completed' => 'bg-emerald-100 text-emerald-700', 'in_progress' => 'bg-amber-100 text-amber-700', 'abandoned' => 'bg-rose-100 text-rose-700'][$last_status] ?? 'bg-slate-100 text-slate-700';
          ?>
          <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="px-3 py-2">
              <div class="font-semibold text-slate-900"><?= htmlspecialchars($p['name'] ?: '— unnamed —') ?></div>
              <div class="text-[10px] text-slate-500"><?= htmlspecialchars($p['whatsapp']) ?></div>
            </td>
            <td class="px-3 py-2 text-xs text-slate-600"><?= htmlspecialchars($reg) ?></td>
            <td class="px-3 py-2">
              <span class="<?= $status_color ?> px-2 py-0.5 rounded-full text-[10px] font-bold uppercase"><?= htmlspecialchars($last_status) ?></span>
              <?php if ($p['n_done'] > 1): ?>
                <div class="text-[10px] text-slate-500 mt-1"><?= (int)$p['n_done'] ?> done</div>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2">
              <?php if ($p['last_report']): ?>
                <a href="<?= htmlspecialchars($p['last_report']) ?>" target="_blank" class="text-emerald-600 hover:underline text-xs font-semibold">View →</a>
              <?php else: ?>
                <span class="text-slate-400 text-xs">—</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-right">
              <details class="inline-block relative">
                <summary class="cursor-pointer text-xs text-slate-500 hover:text-emerald-700 select-none">⋯ actions</summary>
                <div class="absolute bg-white border border-slate-200 rounded-lg shadow-lg p-3 mt-1 right-0 z-10 min-w-[240px] space-y-1 text-xs">
                  <?php if ($p['last_session_id'] && $p['last_report']): ?>
                    <form method="POST" class="m-0">
                      <input type="hidden" name="action" value="share_report">
                      <input type="hidden" name="session_id" value="<?= (int)$p['last_session_id'] ?>">
                      <button class="w-full text-left px-2 py-1.5 hover:bg-emerald-50 rounded text-emerald-700">💬 Share report via WhatsApp</button>
                    </form>
                  <?php endif; ?>

                  <form method="POST" class="m-0" onsubmit="this.querySelector('[name=amount]').value = prompt('How many ₹ to gift to <?= htmlspecialchars(addslashes($p['name'] ?: 'this parent')) ?>?', '500'); return this.querySelector('[name=amount]').value !== null;">
                    <input type="hidden" name="action" value="gift_credits">
                    <input type="hidden" name="parent_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="amount" value="">
                    <button class="w-full text-left px-2 py-1.5 hover:bg-indigo-50 rounded text-indigo-700">🎁 Gift credits</button>
                  </form>

                  <form method="POST" class="m-0" onsubmit="var pct = prompt('Discount % (5 to 50)?', '20'); if (!pct) return false; this.querySelector('[name=percent]').value = pct; return true;">
                    <input type="hidden" name="action" value="apply_discount">
                    <input type="hidden" name="parent_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="percent" value="">
                    <input type="hidden" name="valid_days" value="7">
                    <button class="w-full text-left px-2 py-1.5 hover:bg-amber-50 rounded text-amber-700">💸 Apply discount</button>
                  </form>

                  <?php if ($p['whatsapp']): ?>
                    <a href="https://wa.me/<?= preg_replace('/\D/', '', $p['whatsapp']) ?>" target="_blank" class="block w-full text-left px-2 py-1.5 hover:bg-slate-50 rounded text-slate-700">📞 Open WhatsApp chat</a>
                  <?php endif; ?>
                </div>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($parents)): ?>
            <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">No patients yet. Share your link <code>/p/<?= htmlspecialchars($me['referral_code']) ?></code> with parents.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'earnings'): ?>

    <!-- EARNINGS LEDGER -->
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
            <th class="text-left px-3 py-2">Date</th>
            <th class="text-left px-3 py-2">Parent</th>
            <th class="text-left px-3 py-2">Service</th>
            <th class="text-right px-3 py-2">Gross</th>
            <th class="text-right px-3 py-2">Your share</th>
            <th class="text-center px-3 py-2">Paid?</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($earnings as $e): ?>
            <tr class="border-b border-slate-100">
              <td class="px-3 py-2 text-xs"><?= htmlspecialchars(date('M j, Y', strtotime((string)$e['created_at']))) ?></td>
              <td class="px-3 py-2"><?= htmlspecialchars($e['parent_name'] ?: '—') ?></td>
              <td class="px-3 py-2 text-xs text-slate-600"><?= htmlspecialchars((string)($e['service_key'] ?? '—')) ?></td>
              <td class="px-3 py-2 text-right text-xs">₹<?= number_format((float)($e['gross_amount'] ?? 0)) ?></td>
              <td class="px-3 py-2 text-right font-bold text-emerald-700">₹<?= number_format((float)$e['partner_amount']) ?></td>
              <td class="px-3 py-2 text-center text-xs">
                <?= !empty($e['paid_at']) ? '<span class="text-emerald-600">✓ ' . htmlspecialchars(date('M j', strtotime((string)$e['paid_at']))) . '</span>' : '<span class="text-slate-400">pending</span>' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($earnings)): ?>
            <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">No earnings yet. You earn <?= (int)round(((float)$me['revenue_share']) * 100) ?>% of every paid evaluation by parents you refer.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'share'): ?>

    <!-- SHARE WITH PATIENTS -->
    <div class="bg-white border border-slate-200 rounded-xl p-6">
      <h3 class="text-lg font-bold mb-2">Your patient referral link</h3>
      <p class="text-sm text-slate-600 mb-4">Share this with parents who visit your clinic. Every parent who registers via this link is attributed to you, and you earn <?= (int)round(((float)$me['revenue_share']) * 100) ?>% of every charge they make.</p>

      <?php
      $host = $_SERVER['HTTP_HOST'] ?? 'empowerstudents.in';
      $link = 'https://' . $host . '/p/' . $me['referral_code'];
      $sample_pdf = 'https://' . $host . '/sample_parent_evaluation_report.pdf';
      $wa_msg = "Namaste, here is a 13-minute AI parenting evaluation I recommend (made by EmpowerStudents.in's child psychologists): $link · See a sample report: $sample_pdf";
      ?>
      <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 font-mono text-xs break-all"><?= htmlspecialchars($link) ?></div>
      <div class="flex flex-wrap gap-2 mt-3">
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($link) ?>'); this.textContent='✓ Copied'; setTimeout(()=>this.textContent='📋 Copy link', 2000);"
                class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm rounded-lg">📋 Copy link</button>
        <a href="https://wa.me/?text=<?= urlencode($wa_msg) ?>" target="_blank" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-lg">💬 Share via WhatsApp</a>
        <a href="<?= htmlspecialchars($sample_pdf) ?>" target="_blank" class="px-4 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm rounded-lg">📄 View sample report</a>
      </div>

      <div class="mt-6 pt-4 border-t border-slate-200">
        <h4 class="font-bold text-sm mb-2">Pre-filled message to share</h4>
        <textarea readonly rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm font-mono"><?= htmlspecialchars($wa_msg) ?></textarea>
      </div>
    </div>

  <?php endif; ?>

</main>

<footer class="text-center py-5 text-xs text-slate-400">
  Pediatrician dashboard · EmpowerStudents.in · care@empowerstudents.in
</footer>

</body>
</html>
