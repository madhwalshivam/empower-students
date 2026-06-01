<?php
/**
 * child-learn-unlock.php — purchases the 14-day daily-practice program for a child
 *
 * Charges the parent's wallet ₹999 (or whatever child_learn_program is priced at)
 * and creates a child_program_unlocks row with expires_at = +14 days.
 *
 * Idempotent: if an active unlock already exists for the child, redirects back
 * without re-charging.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /child-learn.php'); exit;
}

if (function_exists('csrf_check') && !csrf_check($_POST['csrf'] ?? '')) {
    $_SESSION['flash_error'] = 'Session expired. Please try again.';
    header('Location: /child-learn.php'); exit;
}

$cid = (int)($_POST['cid'] ?? 0);
$cst = db()->prepare("SELECT id, name FROM children WHERE id = ? AND parent_id = ?");
$cst->execute([$cid, $parent_id]);
$child = $cst->fetch();
if (!$child) {
    $_SESSION['flash_error'] = 'Child not found.';
    header('Location: /dashboard.php'); exit;
}

// Ensure schema
db()->exec("CREATE TABLE IF NOT EXISTS child_program_unlocks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id    INTEGER NOT NULL,
    parent_id   INTEGER NOT NULL,
    plan_sku    TEXT NOT NULL DEFAULT 'child_learn_14d',
    amount_paid INTEGER NOT NULL DEFAULT 0,
    started_at  TEXT DEFAULT CURRENT_TIMESTAMP,
    expires_at  TEXT NOT NULL,
    status      TEXT DEFAULT 'active'
)");

// Check for existing active unlock
$st = db()->prepare("SELECT id, expires_at FROM child_program_unlocks
                      WHERE child_id = ? AND status = 'active' AND expires_at > datetime('now')
                      LIMIT 1");
$st->execute([$cid]);
$existing = $st->fetch();
if ($existing) {
    $_SESSION['flash_ok'] = "Program already active for {$child['name']} — expires " . date('M j', strtotime((string)$existing['expires_at']));
    header('Location: /child-learn.php?cid=' . $cid); exit;
}

// Determine price
$price = (int)(wallet_service_price('child_learn_program') ?? 999);
if ($price === 0) $price = 999;

$bal = (int)$parent['credits'];
if ($bal < $price) {
    $_SESSION['flash_error'] = "Need ₹$price · you have ₹$bal. Top up first.";
    header('Location: /wallet.php?need=' . $price); exit;
}

// Charge
try {
    $new_bal = wallet_post(
        $parent_id,
        -$price,
        'child_learn_program',
        $cid,
        "14-day learning program for {$child['name']}",
        'parent:' . $parent_id
    );
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Charge failed: ' . $e->getMessage();
    header('Location: /child-learn.php?cid=' . $cid); exit;
}

// Create unlock row
$expires_at = date('Y-m-d H:i:s', time() + 86400 * 14);
db()->prepare("INSERT INTO child_program_unlocks (child_id, parent_id, plan_sku, amount_paid, expires_at, status)
               VALUES (?, ?, 'child_learn_14d', ?, ?, 'active')")
   ->execute([$cid, $parent_id, $price, $expires_at]);

$_SESSION['flash_ok'] = "🎉 14-day program unlocked for {$child['name']}! New balance: ₹$new_bal";
header('Location: /child-learn.php?cid=' . $cid);
exit;
