<?php
/**
 * /cart.php — multi-module cart with auto-applied bundle discount.
 *
 * Cart lives in $_SESSION['cart'] = ['service_key', ...].
 * One child context per checkout (parents can buy modules per-child;
 * cart is scoped to a single child to keep ownership clean).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/wallet.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Determine child context
$parent = current_parent();
$child  = null;
$cid    = (int)($_GET['cid'] ?? $_POST['cid'] ?? $_SESSION['cart_cid'] ?? 0);
if ($parent) {
    if ($cid > 0) {
        $child = child_for_parent($cid);
    } else {
        $st = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
        $st->execute([(int)$parent['id']]);
        $child = $st->fetch() ?: null;
    }
    if ($child) {
        $cid = (int)$child['id'];
        $_SESSION['cart_cid'] = $cid;
    }
}

// ─── POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $key = trim((string)($_POST['key'] ?? ''));
        if ($key !== '' && module_meta($key)) {
            if (!in_array($key, $_SESSION['cart'], true)) {
                $_SESSION['cart'][] = $key;
                $_SESSION['flash_ok'] = 'Added to cart.';
            } else {
                $_SESSION['flash_error'] = 'Already in cart.';
            }
        }
        header('Location: /cart.php' . ($cid ? '?cid=' . $cid : ''));
        exit;
    }

    if ($action === 'remove') {
        $key = trim((string)($_POST['key'] ?? ''));
        $_SESSION['cart'] = array_values(array_filter($_SESSION['cart'], function ($k) use ($key) { return $k !== $key; }));
        header('Location: /cart.php' . ($cid ? '?cid=' . $cid : ''));
        exit;
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        header('Location: /cart.php');
        exit;
    }

    if ($action === 'checkout') {
        if (!$parent) { header('Location: /login.php?next=/cart.php'); exit; }
        if (!$child)  { $_SESSION['flash_error'] = 'Add a child first.'; header('Location: /add_child.php'); exit; }
        if (empty($_SESSION['cart'])) { header('Location: /catalogue.php'); exit; }

        $total = cart_total($_SESSION['cart']);
        $bal   = wallet_balance((int)$parent['id']);
        if ($bal < $total['total']) {
            $_SESSION['flash_error'] = "Need ₹{$total['total']} — wallet has ₹{$bal}. Top up to continue.";
            header('Location: /wallet.php?need=' . $total['total']);
            exit;
        }

        // Charge each line individually so partner attribution and ledger
        // reasons stay clean. Discount is distributed proportionally — but
        // simpler: apply the discount as a single negative-cost adjustment
        // line, then charge each module at full price. This keeps existing
        // charge logic untouched.
        //
        // Approach: charge each line at full price idempotently
        // (ref_id = child_id), then post a single discount credit reason.
        $charged_total = 0;
        $charged_lines = [];
        foreach ($total['lines'] as $line) {
            $key   = $line['service_key'];
            $price = (int)$line['price'];

            // Skip if already owned for this child (idempotent)
            $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                                 WHERE parent_id = ? AND service_key = ? AND ref_id = ? AND amount < 0");
            $st->execute([(int)$parent['id'], $key, $cid]);
            if ((int)$st->fetchColumn() > 0) continue;

            $r = wallet_charge_for_service((int)$parent['id'], $key, $cid);
            if ($r['status'] === 'charged') {
                $charged_total += $price;
                $charged_lines[] = $key;
            }
        }

        // Apply discount as a credit (refund) so the ledger reads:
        //   -399 module_math
        //   -399 module_lang
        //   -399 module_ga
        //   -399 module_mp
        //    +319 cart_discount_30pct
        if ($total['discount'] > 0 && $charged_total > 0) {
            $applied = (int) floor($charged_total * $total['discount_pct'] / 100);
            wallet_post((int)$parent['id'], $applied, 'cart_discount', $cid,
                        "Auto bundle discount: {$total['discount_pct']}% off cart of " . count($charged_lines) . " modules",
                        'system');
        }

        // Bundle expansion (if a pack was in cart)
        foreach ($charged_lines as $key) {
            $bundle_keys = pack_member_keys($key);
            if (!empty($bundle_keys) && $bundle_keys[0] !== 'choice') {
                foreach ($bundle_keys as $member_key) {
                    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                                         WHERE parent_id = ? AND service_key = ? AND ref_id = ? AND amount <= 0");
                    $st->execute([(int)$parent['id'], $member_key, $cid]);
                    if ((int)$st->fetchColumn() === 0) {
                        wallet_post((int)$parent['id'], 0, $member_key, $cid,
                                    "Included in {$key}", 'system');
                    }
                }
            }
            // Consult packs grant balance
            if ($key === 'consult_pack_5')  consult_grant((int)$parent['id'], 5,  'consult_pack_5 purchase');
            if ($key === 'consult_pack_15') consult_grant((int)$parent['id'], 15, 'consult_pack_15 purchase');
        }

        $_SESSION['cart'] = [];
        $_SESSION['flash_ok'] = 'Checkout complete. Your modules are unlocked.';
        header('Location: /child.php?id=' . $cid);
        exit;
    }
}

$page_title = 'Cart — Empower Students';
$total = cart_total($_SESSION['cart']);

require __DIR__ . '/includes/header.php';

if (!empty($_SESSION['flash_ok']))    { echo '<div class="max-w-3xl mx-auto px-4 mt-4"><div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm">' . e($_SESSION['flash_ok']) . '</div></div>'; unset($_SESSION['flash_ok']); }
if (!empty($_SESSION['flash_error'])) { echo '<div class="max-w-3xl mx-auto px-4 mt-4"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm">' . e($_SESSION['flash_error']) . '</div></div>'; unset($_SESSION['flash_error']); }
?>

<div class="max-w-3xl mx-auto px-4 py-6">
  <h1 class="text-2xl font-bold mb-4">Your cart</h1>

  <?php if (empty($_SESSION['cart'])): ?>
    <div class="bg-white border border-slate-200 rounded-xl p-8 text-center">
      <p class="text-3xl mb-2">🛒</p>
      <p class="text-slate-600 mb-4">Your cart is empty.</p>
      <a href="/catalogue.php<?= $cid ? '?cid=' . $cid : '' ?>" class="inline-block brand-grad text-white px-6 py-2 rounded-lg font-semibold">Browse modules</a>
    </div>
  <?php else: ?>

    <?php if ($child): ?>
      <p class="text-sm text-slate-600 mb-3">Buying for <strong><?= e($child['name']) ?></strong></p>
    <?php endif; ?>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
      <?php foreach ($total['lines'] as $line):
          $meta = module_meta($line['service_key']);
      ?>
        <div class="p-4 border-b border-slate-100 flex items-center gap-3">
          <div class="text-2xl"><?= e($meta['icon'] ?? '📦') ?></div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-slate-900 truncate"><?= e($line['label']) ?></div>
            <div class="text-xs text-slate-500"><?= e(tier_label($line['tier'] ?? '')) ?></div>
          </div>
          <div class="text-right">
            <div class="font-semibold">₹<?= number_format((int)$line['price']) ?></div>
            <form method="post" class="m-0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="key" value="<?= e($line['service_key']) ?>">
              <button class="text-xs text-rose-600 hover:underline">Remove</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="p-4 bg-slate-50">
        <div class="flex justify-between text-sm text-slate-600 mb-1">
          <span>Subtotal</span>
          <span>₹<?= number_format($total['subtotal']) ?></span>
        </div>
        <?php if ($total['discount'] > 0): ?>
          <div class="flex justify-between text-sm text-emerald-700 mb-1">
            <span>Bundle discount (<?= $total['discount_pct'] ?>%, <?= $total['module_count'] ?> modules)</span>
            <span>– ₹<?= number_format($total['discount']) ?></span>
          </div>
        <?php endif; ?>
        <div class="flex justify-between text-base font-bold mt-2 pt-2 border-t border-slate-200">
          <span>Total</span>
          <span>₹<?= number_format($total['total']) ?></span>
        </div>

        <?php if ($total['module_count'] >= 1 && $total['module_count'] < 5): ?>
          <p class="text-xs text-amber-700 mt-3">
            <?php
            $next_threshold = $total['module_count'] < 2 ? 2 : ($total['module_count'] < 3 ? 3 : 5);
            $next_pct = $next_threshold === 2 ? 10 : ($next_threshold === 3 ? 20 : 30);
            $need = $next_threshold - $total['module_count'];
            ?>
            💡 Add <?= $need ?> more module<?= $need > 1 ? 's' : '' ?> to unlock <?= $next_pct ?>% off.
            <a href="/catalogue.php<?= $cid ? '?cid=' . $cid : '' ?>" class="text-indigo-600 hover:underline">Browse</a>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 mt-5">
      <?php if ($parent): ?>
        <p class="text-xs text-slate-500 w-full mb-2">
          Wallet balance: ₹<?= number_format(wallet_balance((int)$parent['id'])) ?>
          <?php if (wallet_balance((int)$parent['id']) < $total['total']): ?>
            <a href="/wallet.php?need=<?= $total['total'] ?>" class="text-indigo-600 hover:underline ml-2">Top up first</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <form method="post" class="m-0">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="checkout">
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90">
          Checkout — ₹<?= number_format($total['total']) ?>
        </button>
      </form>

      <a href="/catalogue.php<?= $cid ? '?cid=' . $cid : '' ?>" class="inline-block px-5 py-3 bg-white border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50">
        ← Add more modules
      </a>

      <form method="post" class="m-0 ml-auto">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear">
        <button class="text-xs text-slate-500 hover:text-rose-600 hover:underline px-2 py-3">Clear cart</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
