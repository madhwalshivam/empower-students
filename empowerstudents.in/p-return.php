<?php
/**
 * p-return.php — Cashfree return handler for the pediatrician landing flow.
 *
 * After Cashfree redirects here with ?order_id=X:
 *   1. Credit the order (wallet_post via credit_order_if_paid)
 *   2. Show a brief "✓ Payment received — starting your evaluation…" page
 *   3. Auto-redirect to /parent-reflect.php?fresh=1 after ~1.5 seconds
 *
 * Keeps parent on the pediatrician-aware flow without dumping them onto
 * the generic /payment_return.php screen.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/cashfree.php';

$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    http_response_code(400);
    echo 'Missing order_id'; exit;
}

// Try to credit
$result = credit_order_if_paid($order_id);
$status = $result['status'] ?? '';
$amount = (int)($result['order']['amount'] ?? 0);

// Pediatrician code from session (set by p-api.php during create_order)
$pcode = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $_SESSION['p_flow_ref'] ?? ''));

// Try to fetch partner branding (for the success-screen header) without crashing
$partner_name = '';
$doctor_name  = '';
if ($pcode !== '') {
    try {
        $st = db()->prepare("SELECT name, contact_name FROM partners WHERE referral_code = ? AND status = 'active'");
        $st->execute([$pcode]);
        $p = $st->fetch();
        if ($p) {
            $partner_name = (string)($p['name'] ?? '');
            $doctor_name  = (string)($p['contact_name'] ?? '');
        }
    } catch (Throwable $_) {}
}

$success = in_array($status, ['credited', 'already_credited'], true);

// If success → auto-redirect to evaluation start
// If pending → keep on this page and let JS poll
// If failed → show error + back to /p/CODE

$page_title = $success ? 'Payment received — starting evaluation' : 'Payment status';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; }
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  .spin { animation: spin 1s linear infinite; }
  @keyframes fadeup { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
  .fade-up { animation: fadeup 0.5s ease-out; }
</style>
<?php if ($success): ?>
  <meta http-equiv="refresh" content="2;url=/parent-reflect.php?fresh=1">
<?php endif; ?>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8 fade-up">
    <?php if ($partner_name): ?>
    <div class="text-center mb-4">
      <div class="inline-block text-[10px] uppercase tracking-widest font-bold text-emerald-700 mb-1">
        Referred by
      </div>
      <div class="text-sm font-bold text-slate-900"><?= htmlspecialchars($partner_name) ?></div>
      <?php if ($doctor_name): ?>
        <div class="text-xs text-slate-600"><?= htmlspecialchars($doctor_name) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <!-- SUCCESS -->
      <div class="text-center">
        <div class="w-16 h-16 mx-auto bg-emerald-100 rounded-full flex items-center justify-center text-3xl mb-4">✓</div>
        <h1 class="text-xl font-bold text-slate-900 mb-2">Payment received</h1>
        <p class="text-sm text-slate-700 mb-4">
          ₹<?= $amount ?> credited.
          <?= $status === 'already_credited' ? 'Already credited earlier.' : '' ?>
        </p>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-sm text-emerald-900">
          <span class="inline-block w-4 h-4 border-2 border-emerald-600 border-t-transparent rounded-full spin align-middle mr-2"></span>
          Starting your evaluation…
        </div>
        <noscript>
          <p class="mt-3 text-xs text-slate-500">
            JavaScript disabled. <a href="/parent-reflect.php?fresh=1" class="text-emerald-600 underline">Click here to start your evaluation →</a>
          </p>
        </noscript>
        <script>
          setTimeout(() => { window.location.href = '/parent-reflect.php?fresh=1'; }, 1800);
        </script>
      </div>
    <?php elseif ($status === 'pending'): ?>
      <!-- PENDING -->
      <div class="text-center">
        <div class="w-12 h-12 mx-auto border-4 border-amber-500 border-t-transparent rounded-full spin mb-4"></div>
        <h1 class="text-xl font-bold text-slate-900 mb-2">Payment processing</h1>
        <p class="text-sm text-slate-700 mb-4">
          Cashfree is confirming your payment. This page will refresh automatically.
        </p>
        <p class="text-xs text-slate-500">Order ID: <code><?= htmlspecialchars($order_id) ?></code></p>
        <script>setTimeout(() => location.reload(), 4000);</script>
      </div>
    <?php else: ?>
      <!-- FAILED / ERROR -->
      <div class="text-center">
        <div class="w-16 h-16 mx-auto bg-rose-100 rounded-full flex items-center justify-center text-3xl mb-4">✕</div>
        <h1 class="text-xl font-bold text-slate-900 mb-2">Payment didn't go through</h1>
        <p class="text-sm text-slate-700 mb-1">
          <?= $status === 'failed' ? 'The payment was declined or cancelled.' : 'We could not verify the payment status.' ?>
        </p>
        <?php if (!empty($result['error'])): ?>
          <p class="text-xs text-slate-500 mb-3"><?= htmlspecialchars($result['error']) ?></p>
        <?php endif; ?>
        <?php if ($pcode !== ''): ?>
          <a href="/p.php?code=<?= htmlspecialchars($pcode) ?>"
             class="inline-block mt-3 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-sm">
            ← Back to evaluation page
          </a>
        <?php else: ?>
          <a href="/dashboard.php"
             class="inline-block mt-3 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-sm">
            Go to dashboard
          </a>
        <?php endif; ?>
        <p class="text-xs text-slate-500 mt-4">Order ID: <code><?= htmlspecialchars($order_id) ?></code></p>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
