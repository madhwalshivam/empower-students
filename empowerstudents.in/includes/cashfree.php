<?php
/**
 * cashfree.php — Cashfree Payments v3 client (PHP port of clinic's cashfree.py).
 *
 * One-time payments only. Subscriptions can be added later.
 *
 * empowerstudents.in must be whitelisted in your Cashfree dashboard's
 * Allowed URLs / Webhook Origins.
 *
 * Webhook URL to set in Cashfree dashboard:
 *   https://empowerstudents.in/payment_webhook.php
 * Return URL is set per-order inside cf_create_order().
 */
require_once __DIR__ . '/config.php';

function cf_is_configured(): bool {
    return defined('CASHFREE_APP_ID') && CASHFREE_APP_ID !== ''
        && defined('CASHFREE_SECRET_KEY') && CASHFREE_SECRET_KEY !== '';
}

function cf_base_url(): string {
    return (CASHFREE_ENV === 'production')
         ? 'https://api.cashfree.com/pg'
         : 'https://sandbox.cashfree.com/pg';
}

function _cf_headers(): array {
    return [
        'Content-Type: application/json',
        'x-api-version: ' . CASHFREE_API_VERSION,
        'x-client-id: ' . CASHFREE_APP_ID,
        'x-client-secret: ' . CASHFREE_SECRET_KEY,
    ];
}

function _cf_request(string $method, string $endpoint, ?array $body = null, int $timeout = 30): array {
    $ch = curl_init(cf_base_url() . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => _cf_headers(),
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return [0, null, '', $err];
    $parsed = $raw ? json_decode($raw, true) : null;
    return [(int)$code, $parsed, (string)$raw, null];
}

/**
 * Create a Cashfree order. Returns the API response array.
 * Required keys: order_id, order_amount, customer_id, customer_name,
 *                customer_email, customer_phone.
 * Optional: currency, return_url, notify_url, note.
 */
function cf_create_order(array $p): array {
    if (!cf_is_configured()) {
        throw new RuntimeException('Cashfree not configured. Set CASHFREE_APP_ID and CASHFREE_SECRET_KEY.');
    }
    foreach (['order_id','order_amount','customer_id','customer_name','customer_email','customer_phone'] as $k) {
        if (empty($p[$k])) throw new RuntimeException("Missing Cashfree field: $k");
    }
    $body = [
        'order_id'       => (string)$p['order_id'],
        'order_amount'   => (float)$p['order_amount'],
        'order_currency' => $p['currency'] ?? 'INR',
        'customer_details' => [
            'customer_id'    => (string)$p['customer_id'],
            'customer_name'  => substr((string)$p['customer_name'], 0, 100),
            'customer_email' => substr((string)$p['customer_email'], 0, 100),
            'customer_phone' => substr((string)$p['customer_phone'], 0, 15),
        ],
        'order_meta' => [
            'return_url' => $p['return_url'] ?? '',
            'notify_url' => $p['notify_url'] ?? '',
        ],
        'order_note' => substr((string)($p['note'] ?? 'Empower Students wallet topup'), 0, 200),
    ];
    [$code, $js, $raw, $err] = _cf_request('POST', '/orders', $body);
    if ($err)         throw new RuntimeException("Cashfree network error: $err");
    if ($code !== 200) {
        $msg = ($js['message'] ?? '') ?: ($raw ?: 'Unknown error');
        throw new RuntimeException("Cashfree order creation failed (HTTP $code): $msg");
    }
    if (empty($js['payment_session_id'])) {
        throw new RuntimeException('Cashfree response missing payment_session_id');
    }
    return $js;
}

function cf_get_order_status(string $order_id): array {
    if (!cf_is_configured()) throw new RuntimeException('Cashfree not configured.');
    [$code, $js, $raw, $err] = _cf_request('GET', '/orders/' . urlencode($order_id));
    if ($err)         throw new RuntimeException("Cashfree network error: $err");
    if ($code !== 200) {
        $msg = ($js['message'] ?? '') ?: ($raw ?: 'Unknown error');
        throw new RuntimeException("Cashfree order fetch failed (HTTP $code): $msg");
    }
    return $js;
}

function cf_is_order_paid(?array $resp): bool {
    return is_array($resp) && (($resp['order_status'] ?? '') === 'PAID');
}

/**
 * Verify Cashfree webhook signature.
 * Cashfree sends:
 *   x-webhook-signature  = base64(HMAC-SHA256(timestamp + raw_body, secret_key))
 *   x-webhook-timestamp  = epoch seconds
 */
function cf_verify_webhook_signature(string $raw_body, string $timestamp, string $signature): bool {
    if (!CASHFREE_SECRET_KEY || !$signature || !$timestamp) return false;
    $payload  = $timestamp . $raw_body;
    $expected = base64_encode(hash_hmac('sha256', $payload, CASHFREE_SECRET_KEY, true));
    return hash_equals($expected, $signature);
}

function cf_make_order_id(int $parent_id): string {
    return sprintf('ESI%d_%d%04d', $parent_id, time(), random_int(0, 9999));
}

/**
 * Verify with Cashfree and credit the wallet idempotently.
 * Used by both /payment_return.php (parent flow) and /admin/orders.php
 * (manual re-verify by an admin).
 *
 * Idempotent — safe to call multiple times for the same order_id.
 *
 * Returns: ['status' => credited|already_credited|pending|failed|verify_error|unknown_order,
 *           'order'  => row, 'new_balance' => int|null, 'error' => string|null]
 */
function credit_order_if_paid(string $order_id): array {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/wallet.php';

    $st = db()->prepare("SELECT * FROM payment_orders WHERE order_id = ?");
    $st->execute([$order_id]);
    $po = $st->fetch();
    if (!$po) return ['status' => 'unknown_order'];
    if ((int)$po['credited'] === 1) {
        return ['status' => 'already_credited', 'order' => $po];
    }

    try {
        $cf = cf_get_order_status($order_id);
    } catch (Throwable $e) {
        return ['status' => 'verify_error', 'error' => $e->getMessage(), 'order' => $po];
    }

    $cf_status = $cf['order_status'] ?? 'UNKNOWN';
    if ($cf_status === 'PAID') {
        $new_bal = wallet_post(
            (int)$po['parent_id'],
            (int)$po['amount'],
            'wallet_topup',
            (int)$po['id'],
            "Top-up via Cashfree (order $order_id)",
            'cashfree'
        );
        db()->prepare("UPDATE payment_orders SET status='success', credited=1,
                       completed_at=CURRENT_TIMESTAMP, raw_response=? WHERE id=?")
           ->execute([substr(json_encode($cf), 0, 5000), (int)$po['id']]);
        $po['credited'] = 1;

        /* fresh-v9: auto-record partner commission on this top-up.
         * Need the ledger_id of the wallet_post we just made — look it up.
         * wallet_post writes service_key='wallet_topup' ref_id=payment_order_id. */
        try {
            @require_once __DIR__ . '/partners.php';
            if (function_exists('partner_record_topup_commission')) {
                $lst = db()->prepare("SELECT id FROM wallet_ledger
                                      WHERE parent_id = ? AND service_key = 'wallet_topup'
                                        AND ref_id = ? AND amount = ?
                                      ORDER BY id DESC LIMIT 1");
                $lst->execute([(int)$po['parent_id'], (int)$po['id'], (int)$po['amount']]);
                $ledger_id = (int)$lst->fetchColumn();
                if ($ledger_id > 0) {
                    $cr = partner_record_topup_commission(
                        (int)$po['parent_id'],
                        (int)$po['amount'],
                        $ledger_id
                    );
                    if (($cr['status'] ?? '') === 'created') {
                        error_log('[cashfree] partner commission Rs.' . $cr['commission']
                                . ' recorded for partner #' . $cr['partner_id']
                                . ' (parent #' . $po['parent_id'] . ', top-up Rs.' . $po['amount'] . ')');
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[cashfree] commission record failed: ' . $e->getMessage());
            /* never fail the credit on a commission error */
        }

        return ['status' => 'credited', 'order' => $po, 'new_balance' => $new_bal];
    }
    if ($cf_status === 'ACTIVE') return ['status' => 'pending', 'order' => $po];

    db()->prepare("UPDATE payment_orders SET status='failed', raw_response=? WHERE id=?")
       ->execute([substr(json_encode($cf), 0, 5000), (int)$po['id']]);
    return ['status' => 'failed', 'order' => $po, 'cf_status' => $cf_status];
}
