<?php
/**
 * payment_return.php — User lands here after Cashfree checkout.
 * Verifies status with Cashfree and credits the wallet idempotently.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/cashfree.php';   // credit_order_if_paid() lives here now
require_parent();

$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    http_response_code(400);
    echo 'Missing order_id'; exit;
}

$result = credit_order_if_paid($order_id);

$page_title = 'Payment';
require __DIR__ . '/includes/header.php';

$status  = $result['status'] ?? '';
$amount  = (int)($result['order']['amount'] ?? 0);
$new_bal = $result['new_balance'] ?? null;

$icon = '⏳'; $title = 'Processing'; $msg = ''; $tone = 'amber';
if ($status === 'credited') {
    $icon = '✅'; $title = 'Payment successful'; $tone = 'emerald';
    $bonus_table = [100 => 0, 250 => 25, 500 => 75, 1000 => 200];
    $bonus = $bonus_table[$amount] ?? 0;
    if ($bonus > 0) {
        $st = db()->prepare("SELECT id FROM wallet_ledger WHERE service_key='topup_bonus' AND ref_id=? LIMIT 1");
        $st->execute([(int)$result['order']['id']]);
        if (!$st->fetch()) {
            $new_bal = wallet_post(
                (int)$result['order']['parent_id'],
                $bonus,
                'topup_bonus',
                (int)$result['order']['id'],
                "Bonus credits for ₹$amount pack",
                'cashfree'
            );
        }
    }
    $msg = "₹$amount paid. Your new balance is <strong>$new_bal credits</strong>.";
} elseif ($status === 'already_credited') {
    $icon = '✅'; $title = 'Already credited'; $tone = 'emerald';
    $msg = "This order was already credited (₹$amount).";
} elseif ($status === 'pending') {
    $icon = '⏳'; $title = 'Payment pending'; $tone = 'amber';
    $msg = "Bank hasn't confirmed yet. We'll credit your wallet automatically once it clears.";
} elseif ($status === 'failed') {
    $icon = '❌'; $title = 'Payment failed'; $tone = 'rose';
    $msg = "Your payment was not completed. You can try again from the wallet page.";
} else {
    $icon = '⚠️'; $title = ucfirst(str_replace('_', ' ', $status)); $tone = 'rose';
    $msg = e($result['error'] ?? 'Unknown status.');
}
?>
<a href="/wallet.php" class="text-sm text-indigo-600 hover:underline">&larr; Wallet</a>
<div class="bg-white rounded-2xl border border-slate-100 p-8 shadow-sm text-center mt-4 max-w-xl mx-auto">
  <div class="text-6xl mb-2"><?= $icon ?></div>
  <h1 class="text-xl font-bold text-<?= $tone ?>-700"><?= e($title) ?></h1>
  <p class="text-slate-700 mt-3"><?= $msg /* trusted - composed above */ ?></p>
  <p class="text-xs text-slate-400 mt-4 font-mono">Order: <?= e($order_id) ?></p>
  <div class="mt-5 flex gap-2 justify-center">
    <a href="/wallet.php" class="brand-grad text-white px-4 py-2 rounded-lg text-sm">🏦 Wallet</a>
    <a href="/dashboard.php" class="bg-slate-200 text-slate-800 px-4 py-2 rounded-lg text-sm">🏠 Dashboard</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
