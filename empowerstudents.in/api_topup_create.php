<?php
/**
 * api_topup_create.php — POST {amount: int}
 * Creates a Cashfree order and returns the payment_session_id for the
 * client-side Drop-in checkout.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/cashfree.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']); exit;
}
require_parent();
$parent = current_parent();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($body['csrf'] ?? '');
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF check failed']); exit;
}
$amount = (int)($body['amount'] ?? 0);
if ($amount < 50)      { http_response_code(400); echo json_encode(['error' => 'Minimum top-up is ₹50']); exit; }
if ($amount > 100000)  { http_response_code(400); echo json_encode(['error' => 'Maximum per-transaction is ₹1,00,000']); exit; }

if (!cf_is_configured()) {
    http_response_code(503);
    echo json_encode(['error' => 'Payment gateway not configured. Set CASHFREE_APP_ID and CASHFREE_SECRET_KEY in includes/config.php']); exit;
}

// Compute bonus credits matching the wallet page's pack table
$bonus_table = [100 => 0, 250 => 25, 500 => 75, 1000 => 200];
$bonus = $bonus_table[$amount] ?? 0;

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
      . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN);
$return_url = $base . '/payment_return.php?order_id={order_id}';
$notify_url = $base . '/payment_webhook.php';

$order_id = cf_make_order_id((int)$parent['id']);

db()->prepare("INSERT INTO payment_orders (order_id, parent_id, amount, currency, status)
               VALUES (?, ?, ?, 'INR', 'pending')")
   ->execute([$order_id, (int)$parent['id'], $amount]);

$phone = preg_replace('/\D+/', '', $parent['whatsapp']);
if (strlen($phone) > 10) $phone = substr($phone, -10);
if (!$phone) $phone = '9999999999';

try {
    $resp = cf_create_order([
        'order_id'       => $order_id,
        'order_amount'   => $amount,
        'customer_id'    => 'parent_' . (int)$parent['id'],
        'customer_name'  => $parent['name'] ?: 'Parent',
        'customer_email' => $parent['email'] ?: 'noreply@empowerstudents.in',
        'customer_phone' => $phone,
        'return_url'     => $return_url,
        'notify_url'     => $notify_url,
        'note'           => "Empower Students wallet top-up ₹$amount" . ($bonus ? " (+$bonus bonus)" : ''),
    ]);
} catch (Throwable $e) {
    db()->prepare("UPDATE payment_orders SET status='failed', raw_response=? WHERE order_id=?")
       ->execute([substr('create_error: ' . $e->getMessage(), 0, 5000), $order_id]);
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]); exit;
}

db()->prepare("UPDATE payment_orders SET raw_response=? WHERE order_id=?")
   ->execute([substr(json_encode($resp), 0, 5000), $order_id]);

echo json_encode([
    'order_id' => $order_id,
    'payment_session_id' => $resp['payment_session_id'],
    'mode' => CASHFREE_ENV,
    'bonus' => $bonus,
]);
