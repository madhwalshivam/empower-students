<?php
require __DIR__ . '/_admin.php';

$pid = (int)($_GET['id'] ?? 0);
$pst = db()->prepare("SELECT * FROM parents WHERE id = ?");
$pst->execute([$pid]);
$parent = $pst->fetch();
if (!$parent) { flash('Parent not found.', 'rose'); header('Location: /admin/parents.php'); exit; }

// ─── Action handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'grant_credits') {
        $amt    = (int)$_POST['amount'];
        $reason = trim($_POST['reason'] ?? '') ?: 'Admin grant';
        if ($amt !== 0) {
            wallet_post($pid, $amt, 'admin_grant', null, $reason, 'admin:' . admin_user());
            flash(($amt > 0 ? "Granted +$amt" : "Deducted $amt") . " credits.");
        }
    }
    if ($action === 'reverse_ledger') {
        $lid = (int)$_POST['ledger_id'];
        $row = db()->prepare("SELECT * FROM wallet_ledger WHERE id=? AND parent_id=?");
        $row->execute([$lid, $pid]);
        $r = $row->fetch();
        if ($r) {
            // Idempotency — only if not already reversed
            $already = db()->prepare("SELECT id FROM wallet_ledger WHERE service_key='reversal' AND ref_id=? LIMIT 1");
            $already->execute([$lid]);
            if (!$already->fetch()) {
                wallet_post($pid, -(int)$r['amount'], 'reversal', $lid,
                            "Reversal of ledger #$lid", 'admin:' . admin_user());
                flash("Reversed entry #$lid.");
            } else flash("Already reversed.", 'amber');
        }
    }
    if ($action === 'send_feedback') {
        $body = trim($_POST['body'] ?? '');
        $cid  = (int)($_POST['child_id'] ?? 0) ?: null;
        if ($body !== '') {
            db()->prepare("INSERT INTO parent_feedback (parent_id, child_id, author, body) VALUES (?, ?, ?, ?)")
               ->execute([$pid, $cid, admin_user(), $body]);
            flash('Feedback sent — parent will see it on their wallet/dashboard.');
        }
    }
    if ($action === 'toggle_vip') {
        db()->prepare("UPDATE parents SET is_vip = 1 - COALESCE(is_vip,0) WHERE id = ?")->execute([$pid]);
        flash('VIP flag toggled.');
    }
    if ($action === 'toggle_block') {
        db()->prepare("UPDATE parents SET is_blocked = 1 - COALESCE(is_blocked,0) WHERE id = ?")->execute([$pid]);
        flash('Block flag toggled.');
    }
    if ($action === 'mark_feedback_seen') {
        $fid = (int)$_POST['fb_id'];
        db()->prepare("UPDATE parent_feedback SET seen_by_parent = 1 WHERE id = ? AND parent_id = ?")
           ->execute([$fid, $pid]);
        flash('Marked as read.');
    }
    header('Location: /admin/parent.php?id=' . $pid); exit;
}

// ─── Data ───
$pst->execute([$pid]); $parent = $pst->fetch(); // refresh
$children   = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id"); $children->execute([$pid]); $children = $children->fetchAll();
$ledger     = wallet_history($pid, 100);
$orders     = db()->prepare("SELECT * FROM payment_orders WHERE parent_id = ? ORDER BY id DESC LIMIT 30"); $orders->execute([$pid]); $orders = $orders->fetchAll();
$feedback   = db()->prepare("SELECT * FROM parent_feedback WHERE parent_id = ? ORDER BY id DESC LIMIT 30"); $feedback->execute([$pid]); $feedback = $feedback->fetchAll();
$assessments = db()->prepare("SELECT a.*, c.name AS cname FROM assessments a JOIN children c ON c.id = a.child_id WHERE c.parent_id = ? ORDER BY a.id DESC LIMIT 50");
$assessments->execute([$pid]); $assessments = $assessments->fetchAll();

admin_layout_open('Parent · ' . ($parent['name'] ?: $parent['whatsapp']));
admin_render_flash();
?>
<a href="/admin/parents.php" class="text-sm text-indigo-600 hover:underline">&larr; All parents</a>

<div class="bg-white rounded-2xl border border-slate-200 p-5 mt-3 mb-5 flex flex-wrap items-start gap-4 justify-between">
  <div>
    <div class="flex items-center gap-2 flex-wrap">
      <h1 class="text-2xl font-bold"><?= e($parent['name'] ?: '— unnamed —') ?></h1>
      <?php if ((int)$parent['is_vip']): ?><span class="text-xs bg-amber-100 text-amber-800 rounded px-2 py-0.5">VIP</span><?php endif; ?>
      <?php if ((int)$parent['is_blocked']): ?><span class="text-xs bg-rose-100 text-rose-800 rounded px-2 py-0.5">BLOCKED</span><?php endif; ?>
    </div>
    <p class="text-sm text-slate-500 mt-1 font-mono"><?= e($parent['whatsapp']) ?> · <?= e($parent['email'] ?: '—') ?> · <?= e($parent['city'] ?: '—') ?></p>
    <p class="text-xs text-slate-400 mt-1">Joined <?= e(substr($parent['created_at'], 0, 16)) ?> · last login <?= e(substr($parent['last_login'] ?? '—', 0, 16)) ?></p>
  </div>
  <div class="text-right">
    <div class="text-xs uppercase text-slate-500">Balance</div>
    <div class="text-3xl font-bold"><?= (int)$parent['credits'] ?> <span class="text-base text-slate-400">cr</span></div>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="toggle_vip">
      <button class="text-xs underline text-amber-700 mr-2"><?= (int)$parent['is_vip'] ? 'Remove VIP' : 'Mark VIP' ?></button>
    </form>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="toggle_block">
      <button class="text-xs underline text-rose-700"><?= (int)$parent['is_blocked'] ? 'Unblock' : 'Block' ?></button>
    </form>
  </div>
</div>

<div class="grid lg:grid-cols-2 gap-4">

  <!-- Grant credits -->
  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">💰 Grant or deduct credits</h2>
    <form method="post" class="space-y-2">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="grant_credits">
      <div class="flex gap-2">
        <input type="number" name="amount" placeholder="e.g. 100 (or -50)" required class="flex-1 border border-slate-200 rounded-lg p-2 text-sm">
        <button class="bg-emerald-600 text-white text-sm px-4 rounded-lg">Apply</button>
      </div>
      <input type="text" name="reason" placeholder="Reason (visible to parent in ledger)" class="w-full border border-slate-200 rounded-lg p-2 text-sm">
      <p class="text-xs text-slate-400">Positive = credit; negative = deduct. Goes through the ledger.</p>
    </form>
  </section>

  <!-- Send feedback -->
  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">💬 Send feedback</h2>
    <form method="post" class="space-y-2">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="send_feedback">
      <select name="child_id" class="w-full border border-slate-200 rounded-lg p-2 text-sm">
        <option value="">— general (about the parent) —</option>
        <?php foreach ($children as $c): ?>
          <option value="<?= (int)$c['id'] ?>">About <?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <textarea name="body" rows="3" placeholder="Your message — appears as a green note on the parent's wallet page." required class="w-full border border-slate-200 rounded-lg p-2 text-sm"></textarea>
      <button class="bg-indigo-600 text-white text-sm px-4 py-2 rounded-lg">Send</button>
    </form>
  </section>

  <!-- Children -->
  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">👶 Children (<?= count($children) ?>)</h2>
    <ul class="text-sm space-y-2">
      <?php foreach ($children as $c):
        $age = calc_age_years($c['dob']);
      ?>
        <li class="border border-slate-100 rounded-lg p-3">
          <strong><?= e($c['name']) ?></strong>
          <span class="text-xs text-slate-500"> · <?= $age !== null ? round($age, 1) . ' yrs' : '—' ?> · <?= e($c['gender'] ?: '—') ?></span>
          <?php if ($c['diagnosis']): ?><div class="text-xs text-rose-600 mt-1">Dx: <?= e($c['diagnosis']) ?></div><?php endif; ?>
          <div class="text-xs text-slate-500 mt-1">DOB <?= e($c['dob']) ?> · <?= e($c['school'] ?: '—') ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$children): ?><li class="text-slate-400">No children registered yet.</li><?php endif; ?>
    </ul>
  </section>

  <!-- Feedback history -->
  <section class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-3">📨 Feedback log</h2>
    <ul class="text-sm space-y-2">
      <?php foreach ($feedback as $fb): ?>
        <li class="border border-slate-100 rounded-lg p-3">
          <div class="flex justify-between gap-2">
            <strong><?= e($fb['author']) ?></strong>
            <span class="text-xs text-slate-400"><?= e(substr($fb['created_at'], 0, 16)) ?></span>
          </div>
          <div class="whitespace-pre-line text-slate-700 mt-1"><?= e($fb['body']) ?></div>
          <div class="text-xs mt-1 <?= (int)$fb['seen_by_parent'] ? 'text-emerald-600' : 'text-amber-600' ?>">
            <?= (int)$fb['seen_by_parent'] ? '✓ Seen by parent' : '◯ Unread' ?>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$feedback): ?><li class="text-slate-400">No messages yet.</li><?php endif; ?>
    </ul>
  </section>
</div>

<!-- Assessments -->
<section class="bg-white rounded-2xl border border-slate-200 p-5 mt-4">
  <h2 class="font-semibold mb-3">📋 Assessments (latest 50)</h2>
  <table class="w-full text-sm">
    <thead class="text-xs uppercase text-slate-500 text-left">
      <tr><th class="py-1">Child</th><th>Module</th><th>Score</th><th>Flags</th><th>When</th></tr>
    </thead>
    <tbody>
    <?php foreach ($assessments as $a):
      $fl = json_decode($a['flags'] ?? '[]', true) ?: [];
    ?>
      <tr class="border-t border-slate-100">
        <td class="py-1"><?= e($a['cname']) ?></td>
        <td><?= e($a['module']) ?></td>
        <td><?= $a['score'] !== null ? round((float)$a['score'], 1) : '—' ?></td>
        <td><?= count($fl) ? '<span class="text-amber-600">' . count($fl) . '</span>' : '0' ?></td>
        <td class="text-xs text-slate-500"><?= e(substr($a['completed_at'] ?? $a['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$assessments): ?><tr><td colspan="5" class="text-center text-slate-400 py-3">None yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<!-- Payment orders -->
<section class="bg-white rounded-2xl border border-slate-200 p-5 mt-4">
  <h2 class="font-semibold mb-3">💳 Payment orders</h2>
  <table class="w-full text-sm">
    <thead class="text-xs uppercase text-slate-500 text-left">
      <tr><th class="py-1">Order ID</th><th>Amount</th><th>Status</th><th>Credited</th><th>When</th></tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr class="border-t border-slate-100">
        <td class="py-1 font-mono text-xs"><?= e($o['order_id']) ?></td>
        <td>₹<?= (int)$o['amount'] ?></td>
        <td>
          <span class="text-xs px-2 py-0.5 rounded
            <?= $o['status']==='success' ? 'bg-emerald-100 text-emerald-700' : ($o['status']==='pending' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') ?>">
            <?= e($o['status']) ?>
          </span>
        </td>
        <td><?= (int)$o['credited'] ? '✓' : '—' ?></td>
        <td class="text-xs text-slate-500"><?= e(substr($o['completed_at'] ?? $o['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="5" class="text-center text-slate-400 py-3">No orders.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<!-- Ledger -->
<section class="bg-white rounded-2xl border border-slate-200 p-5 mt-4">
  <h2 class="font-semibold mb-3">📜 Wallet ledger (latest 100)</h2>
  <div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="text-xs uppercase text-slate-500 text-left">
      <tr><th class="py-1">When</th><th>Service</th><th>Reason</th><th class="text-right">Δ</th><th class="text-right">After</th><th>By</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($ledger as $l): ?>
      <tr class="border-t border-slate-100">
        <td class="py-1 text-xs text-slate-500"><?= e(substr($l['created_at'], 0, 16)) ?></td>
        <td><?= e($l['service_key'] ?: '—') ?></td>
        <td class="text-slate-600"><?= e($l['reason']) ?></td>
        <td class="text-right <?= ((int)$l['amount']) > 0 ? 'text-emerald-700' : 'text-rose-700' ?> font-mono">
          <?= ((int)$l['amount']) > 0 ? '+' : '' ?><?= (int)$l['amount'] ?>
        </td>
        <td class="text-right text-slate-500"><?= (int)$l['balance_after'] ?></td>
        <td class="text-xs text-slate-400"><?= e($l['created_by']) ?></td>
        <td>
          <?php if (((int)$l['amount']) !== 0 && $l['service_key'] !== 'reversal'): ?>
            <form method="post" onsubmit="return confirm('Reverse this entry?');" class="inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reverse_ledger">
              <input type="hidden" name="ledger_id" value="<?= (int)$l['id'] ?>">
              <button class="text-xs text-rose-600 hover:underline">reverse</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger): ?><tr><td colspan="7" class="text-center text-slate-400 py-3">No ledger entries.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php admin_layout_close(); ?>
