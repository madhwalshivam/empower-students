<?php
/**
 * wallet.php — credit ledger helpers (PHP port of the clinic Python helpers).
 *
 * Pricing model:
 *   1 credit = ₹1
 *   New parents: SIGNUP_FREE_CREDITS on first login (granted by auth.php)
 *   Each module charges its `service_prices.price` value on completion.
 *   Admin can grant or reverse any ledger entry.
 *
 * The ledger is the source of truth. parents.credits is a denormalised cache
 * for fast reads; every write goes through `wallet_post()` which keeps both
 * in sync atomically.
 */
require_once __DIR__ . '/db.php';

/** Current balance (cached in parents.credits for fast reads). */
function wallet_balance(int $parent_id): int {
    $st = db()->prepare("SELECT credits FROM parents WHERE id = ?");
    $st->execute([$parent_id]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Insert a ledger row and update parents.credits atomically.
 * Returns the new balance.
 *
 * @param int    $parent_id
 * @param int    $amount      signed; positive=credit, negative=charge
 * @param string $service_key e.g. 'health', 'wallet_topup', 'admin_grant'
 * @param int|null $ref_id     assessment_id / payment_orders.id / NULL
 * @param string $reason
 * @param string $created_by  'system' | 'admin:<user>' | 'cashfree'
 */
function wallet_post(int $parent_id, int $amount, string $service_key = '',
                     ?int $ref_id = null, string $reason = '',
                     string $created_by = 'system'): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE parents SET credits = COALESCE(credits, 0) + ? WHERE id = ?")
            ->execute([$amount, $parent_id]);
        $st = $pdo->prepare("SELECT credits FROM parents WHERE id = ?");
        $st->execute([$parent_id]);
        $bal = (int)($st->fetchColumn() ?: 0);

        $pdo->prepare("INSERT INTO wallet_ledger
            (parent_id, amount, balance_after, service_key, ref_id, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$parent_id, $amount, $bal, $service_key ?: null, $ref_id, $reason, $created_by]);
        $ledger_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        // ── Partner payout hook (silent no-op if partner schema not loaded) ──
        if ($amount < 0 && function_exists('partner_record_charge')) {
            try {
                partner_record_charge($parent_id, $ledger_id, (string)$service_key, abs($amount));
            } catch (Throwable $e) {
                error_log('[partner_record_charge] ' . $e->getMessage());
                // Never let payout-recording failures break the wallet
            }
        }

        return $bal;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Lookup price (credits) for a service for the parent audience.
 * Returns NULL if service is unknown / inactive.
 */
function wallet_service_price(string $service_key): ?int {
    $st = db()->prepare("SELECT price, is_active FROM service_prices WHERE service_key = ?");
    $st->execute([$service_key]);
    $r = $st->fetch();
    if (!$r || !$r['is_active']) return null;
    return (int)$r['price'];
}

/**
 * Charge for a completed module. Idempotent by (parent_id, service_key, ref_id).
 *
 * Returns ['status' => one_of(charged|free|already_charged|insufficient|skipped),
 *          'price'  => int,
 *          'credits'=> int,                     // balance after the call
 *          'message'=> human readable]
 *
 * `insufficient` does NOT charge — caller decides whether to show a top-up modal.
 */
function wallet_charge_for_service(int $parent_id, string $service_key, ?int $ref_id = null): array {
    $price = wallet_service_price($service_key);
    if ($price === null) {
        return ['status' => 'skipped', 'price' => 0,
                'credits' => wallet_balance($parent_id),
                'message' => 'Service is not billable'];
    }
    if ($price === 0) {
        return ['status' => 'free', 'price' => 0,
                'credits' => wallet_balance($parent_id),
                'message' => 'Free service'];
    }
    // Idempotency — same service+ref_id pair charged once
    if ($ref_id !== null) {
        $st = db()->prepare("SELECT id, balance_after FROM wallet_ledger
                             WHERE parent_id = ? AND service_key = ? AND ref_id = ? AND amount < 0
                             LIMIT 1");
        $st->execute([$parent_id, $service_key, $ref_id]);
        if ($prior = $st->fetch()) {
            return ['status' => 'already_charged',
                    'price' => $price,
                    'credits' => (int)$prior['balance_after'],
                    'message' => 'Already charged'];
        }
    }
    $bal = wallet_balance($parent_id);
    if ($bal < $price) {
        return ['status' => 'insufficient',
                'price' => $price, 'credits' => $bal,
                'message' => "Need {$price} credits — you have {$bal}"];
    }
    $new_bal = wallet_post($parent_id, -$price, $service_key, $ref_id,
                           "Charge: {$service_key}", 'system');
    return ['status' => 'charged', 'price' => $price,
            'credits' => $new_bal, 'message' => "Charged {$price} credits"];
}

/**
 * Hard cap helper — has parent_id already received N comprehensive reports
 * for this child? (The "max 1 advice per API key" rule.)
 */
function wallet_advice_count_for_child(int $child_id): int {
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE service_key = 'comprehensive_report'
                           AND ref_id = ? AND amount < 0");
    $st->execute([$child_id]);
    return (int)$st->fetchColumn();
}

function wallet_grant_signup_credits_if_new(int $parent_id): bool {
    // Avoid double-granting on every login — check ledger for an existing 'signup_bonus' entry
    $st = db()->prepare("SELECT id FROM wallet_ledger WHERE parent_id = ? AND service_key = 'signup_bonus' LIMIT 1");
    $st->execute([$parent_id]);
    if ($st->fetch()) return false;
    wallet_post($parent_id, (int)SIGNUP_FREE_CREDITS, 'signup_bonus', null,
                'Welcome bonus — ' . SIGNUP_FREE_CREDITS . ' free credits', 'system');
    return true;
}

function wallet_history(int $parent_id, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $st = db()->prepare("SELECT id, amount, balance_after, service_key, ref_id, reason, created_by, created_at
                         FROM wallet_ledger WHERE parent_id = ? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $parent_id, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
