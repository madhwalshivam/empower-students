<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/cashfree.php';
require_parent();

$parent = current_parent();
$bal = wallet_balance((int)$parent['id']);
$history = wallet_history((int)$parent['id'], 50);
$need = (int)($_GET['need'] ?? 0);

// Pre-set quick-pick top-up amounts. (1 credit = ₹1)
$packs = [
    ['amt' => 100,   'bonus' => 0,    'tag' => 'Starter'],
    ['amt' => 250,   'bonus' => 25,   'tag' => 'Most popular'],
    ['amt' => 500,   'bonus' => 75,   'tag' => 'Family'],
    ['amt' => 1000,  'bonus' => 200,  'tag' => 'Annual'],
];

// Active feedback from admin (unread)
$st = db()->prepare("SELECT * FROM parent_feedback WHERE parent_id = ? AND seen_by_parent = 0 ORDER BY id DESC");
$st->execute([$parent['id']]);
$unread_feedback = $st->fetchAll();

$page_title = 'Wallet';
require __DIR__ . '/includes/header.php';
?>
<a href="/dashboard.php" class="text-sm text-indigo-600 hover:underline">&larr; <span data-i18n="nav.dashboard">Dashboard</span></a>
<h1 class="text-2xl sm:text-3xl font-bold mt-3 mb-2" data-i18n="wal.title">Wallet</h1>
<p class="text-slate-600 mb-6" data-i18n="wal.note">Top up credits to run more assessments. <strong>1 credit = ₹1</strong>. New parents get <strong>100 free credits</strong>.</p>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-amber-900 text-sm">
    ⚠️ <?= e($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php foreach ($unread_feedback as $fb): ?>
  <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-3 text-emerald-900 text-sm">
    💬 <strong><span data-i18n="wal.feedback">From</span> <?= e($fb['author']) ?>:</strong>
    <div class="mt-1 whitespace-pre-line"><?= e($fb['body']) ?></div>
    <a href="?ack=<?= (int)$fb['id'] ?>" class="text-xs underline mt-2 inline-block" data-i18n="dash.markRead">Mark as read</a>
  </div>
<?php endforeach; ?>
<?php
if (!empty($_GET['ack'])) {
    db()->prepare("UPDATE parent_feedback SET seen_by_parent = 1 WHERE id = ? AND parent_id = ?")
       ->execute([(int)$_GET['ack'], $parent['id']]);
    header('Location: /wallet.php'); exit;
}
?>

<div class="bg-white rounded-2xl border border-slate-100 p-6 shadow-sm mb-6">
  <div class="text-xs uppercase text-slate-500" data-i18n="wal.balance">Current balance</div>
  <div class="text-4xl font-bold mt-1">
    <?= $bal ?> <span class="text-base font-medium text-slate-500" data-i18n="wal.credits">credits</span>
  </div>
  <?php if ($need): ?>
    <p class="text-amber-700 text-sm mt-3">
      <span data-i18n="wal.need.prefix">You need at least</span>
      <strong><?= $need ?></strong>
      <span data-i18n="wal.need.suffix">credits to continue.</span>
    </p>
  <?php endif; ?>
</div>

<h2 class="font-semibold mb-3" data-i18n="wal.topup.title">Top up</h2>
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-8" id="packs">
  <?php foreach ($packs as $p): ?>
    <button data-amt="<?= (int)$p['amt'] ?>" class="topup-btn text-left bg-white rounded-2xl border-2 border-slate-200 hover:border-indigo-400 p-5 shadow-sm transition">
      <div class="text-xs uppercase tracking-wide text-indigo-600 font-semibold"><?= e($p['tag']) ?></div>
      <div class="text-3xl font-bold mt-1">₹<?= (int)$p['amt'] ?></div>
      <div class="text-sm text-slate-500 mt-1">
        <?= (int)$p['amt'] ?> <span data-i18n="wal.credits">credits</span><?php if ($p['bonus']): ?>
          <span class="text-emerald-600 font-medium"> + <?= (int)$p['bonus'] ?> <span data-i18n="wal.bonus">bonus</span></span>
        <?php endif; ?>
      </div>
    </button>
  <?php endforeach; ?>
</div>

<div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm">
  <h2 class="font-semibold mb-3" data-i18n="wal.history">Activity</h2>
  <?php if (!$history): ?>
    <p class="text-slate-500 text-sm" data-i18n="wal.empty">No activity yet.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-xs uppercase text-slate-500 text-left">
        <tr><th class="py-2">When</th><th>Service</th><th>Reason</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr>
      </thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr class="border-t border-slate-100">
          <td class="py-2 text-xs text-slate-500"><?= e(substr($h['created_at'], 0, 16)) ?></td>
          <td><?= e($h['service_key'] ?: '—') ?></td>
          <td class="text-slate-600"><?= e($h['reason']) ?></td>
          <td class="text-right <?= ((int)$h['amount']) > 0 ? 'text-emerald-700' : 'text-rose-700' ?>">
            <?= ((int)$h['amount']) > 0 ? '+' : '' ?><?= (int)$h['amount'] ?>
          </td>
          <td class="text-right text-slate-500"><?= (int)$h['balance_after'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<!-- Cashfree Drop-in JS SDK -->
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script>
const CF_MODE = <?= json_encode(CASHFREE_ENV) ?>;
async function topUp(amount) {
  try {
    const res = await fetch('/api_topup_create.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF': <?= json_encode(csrf_token()) ?>},
      body: JSON.stringify({amount: amount})
    });
    const data = await res.json();
    if (!res.ok || !data.payment_session_id) {
      alert('Could not start payment: ' + (data.error || res.status));
      return;
    }
    const cashfree = Cashfree({ mode: CF_MODE === 'production' ? 'production' : 'sandbox' });
    cashfree.checkout({
      paymentSessionId: data.payment_session_id,
      redirectTarget: '_self'
    });
  } catch (e) {
    alert('Network error: ' + e.message);
  }
}
document.querySelectorAll('.topup-btn').forEach(b =>
  b.addEventListener('click', () => topUp(parseInt(b.dataset.amt, 10))));
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
