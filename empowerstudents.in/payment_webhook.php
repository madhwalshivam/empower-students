<?php
/**
 * payment_webhook.php — Cashfree server-to-server webhook.
 * Verifies HMAC-SHA256 signature, then credits wallet idempotently.
 * Configure URL in Cashfree dashboard: https://empowerstudents.in/payment_webhook.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/cashfree.php';
require_once __DIR__ . '/payment_return.php'; // for credit_order_if_paid()

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$ts  = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

if (!cf_verify_webhook_signature($raw, $ts, $sig)) {
    error_log("[cashfree webhook] signature failed ts=$ts");
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}
$payload = json_decode($raw, true);
$order_id = $payload['data']['order']['order_id'] ?? '';
if (!$order_id) {
    echo json_encode(['status' => 'ignored', 'reason' => 'no order_id']);
    exit;
}
$result = credit_order_if_paid($order_id);
error_log("[cashfree webhook] order=$order_id result=" . ($result['status'] ?? 'unknown'));

// Apply bonus credits if applicable (matches wallet page packs)
if (($result['status'] ?? '') === 'credited' && !empty($result['order'])) {
    $bonus_table = [100 => 0, 250 => 25, 500 => 75, 1000 => 200];
    $amt = (int)$result['order']['amount'];
    $bonus = $bonus_table[$amt] ?? 0;
    if ($bonus > 0) {
        $st = db()->prepare("SELECT id FROM wallet_ledger WHERE service_key='topup_bonus' AND ref_id=? LIMIT 1");
        $st->execute([(int)$result['order']['id']]);
        if (!$st->fetch()) {
            wallet_post(
                (int)$result['order']['parent_id'],
                $bonus,
                'topup_bonus',
                (int)$result['order']['id'],
                "Bonus credits for ₹$amt pack",
                'cashfree'
            );
        }
    }
}
echo json_encode(['status' => $result['status'] ?? 'ok']);
