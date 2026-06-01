<?php
/**
 * lead_verify.php  ── completes the homepage self-signup flow.
 *
 * POST { csrf, code }  (uses $_SESSION['signup_lead'] stashed by lead_submit.php)
 *
 * On success:
 *   - Verifies OTP against the most recent otps row for that phone
 *   - Creates (or updates) the parents row with name + city='homepage_signup'
 *   - Grants SIGNUP_FREE_CREDITS welcome bonus (idempotent)
 *   - Sets parents.free_module_credit = 1 (first module run is free)
 *   - Auto-creates a `children` row using the age range midpoint + concern as
 *     diagnosis hint, so the parent can immediately start a module
 *   - Issues the 60-day persistent-login cookie
 *   - Returns JSON { ok: true, redirect: '/child.php?id=<cid>' }
 *
 * On error: JSON { ok: false, error: '...', step: 'otp' | 'phone' }
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/wallet.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
if (!csrf_check($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Session expired. Please start again.', 'step' => 'phone']);
    exit;
}

// ── Pull stashed signup data ──────────────────────────────────
$lead = $_SESSION['signup_lead'] ?? null;
if (!$lead || empty($lead['phone'])) {
    echo json_encode(['ok' => false, 'error' => 'Session expired. Please request a new OTP.', 'step' => 'phone']);
    exit;
}

// Expire stashed signup after 15 minutes
if (time() - (int)($lead['started_at'] ?? 0) > 900) {
    unset($_SESSION['signup_lead'], $_SESSION['otp_phone']);
    echo json_encode(['ok' => false, 'error' => 'This signup expired. Please start again.', 'step' => 'phone']);
    exit;
}

$phone       = $lead['phone'];
$parent_name = $lead['parent_name'];
$child_age   = $lead['child_age'];
$concern     = $lead['concern'];
$lead_id     = (int)($lead['lead_id'] ?? 0);

$entered = preg_replace('/\D/','', $_POST['code'] ?? '');
if (strlen($entered) !== 6) {
    echo json_encode(['ok' => false, 'error' => 'Enter the 6-digit OTP.', 'step' => 'otp']);
    exit;
}

// ── Verify OTP ───────────────────────────────────────────────
$st = db()->prepare("SELECT * FROM otps WHERE whatsapp = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
$st->execute([$phone]);
$otp = $st->fetch();
if (!$otp) {
    echo json_encode(['ok' => false, 'error' => 'No active OTP. Please request a new one.', 'step' => 'phone']);
    exit;
}
if (strtotime($otp['expires_at']) < time()) {
    echo json_encode(['ok' => false, 'error' => 'OTP expired. Please request a new one.', 'step' => 'phone']);
    exit;
}
if ((int)$otp['attempts'] >= OTP_MAX_TRY) {
    echo json_encode(['ok' => false, 'error' => 'Too many wrong attempts. Please request a new OTP.', 'step' => 'phone']);
    exit;
}
if (!password_verify($entered, $otp['code_hash'])) {
    db()->prepare('UPDATE otps SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
    $left = OTP_MAX_TRY - (int)$otp['attempts'] - 1;
    echo json_encode(['ok' => false, 'error' => "Incorrect OTP. {$left} attempts left.", 'step' => 'otp']);
    exit;
}

db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$otp['id']]);

// ── Ensure schema has free_module_credit column (one-time migration) ──
try {
    $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
    $names = array_column($cols, 'name');
    if (!in_array('free_module_credit', $names, true)) {
        db()->exec("ALTER TABLE parents ADD COLUMN free_module_credit INTEGER DEFAULT 0");
    }
} catch (Throwable $e) { error_log('[lead_verify] migration: ' . $e->getMessage()); }

// ── Find-or-create parent ────────────────────────────────────
$p = db()->prepare('SELECT * FROM parents WHERE whatsapp = ?');
$p->execute([$phone]);
$parent = $p->fetch();

$first_time = false;
if (!$parent) {
    db()->prepare("INSERT INTO parents (whatsapp, name, city) VALUES (?,?,?)")
        ->execute([$phone, $parent_name, 'homepage_signup']);
    $pid = (int) db()->lastInsertId();
    $first_time = true;
} else {
    $pid = (int) $parent['id'];
    // Update name if missing
    if ($parent_name && empty($parent['name'])) {
        db()->prepare('UPDATE parents SET name = ? WHERE id = ?')->execute([$parent_name, $pid]);
    }
}
db()->prepare("UPDATE parents SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pid]);

// ── Welcome bonus (idempotent) ───────────────────────────────
$bonus_granted = wallet_grant_signup_credits_if_new($pid);

// ── Grant ONE free module run for first-time signups ─────────
if ($first_time) {
    db()->prepare("UPDATE parents SET free_module_credit = 1 WHERE id = ? AND COALESCE(free_module_credit,0) = 0")
        ->execute([$pid]);
}

// ── Partner attribution (silent no-op if no ?ref captured) ───
if (file_exists(__DIR__ . '/includes/partner_capture.php')) {
    require_once __DIR__ . '/includes/partner_capture.php';
    try { partner_capture_attribute_session_parent($pid); } catch (Throwable $_) {}
}

// ── Auto-create child from age range + concern ───────────────
// Age range → midpoint year → DOB = today minus midpoint years
$age_midpoint = [
    '0-2'   => 1,
    '2-4'   => 3,
    '5-7'   => 6,
    '8-10'  => 9,
    '11-14' => 12,
    '15+'   => 16,
];
$years_back = $age_midpoint[$child_age] ?? 6;
$dob = date('Y-m-d', strtotime("-{$years_back} years"));

$concern_label = [
    'speech'        => 'Parent-reported: speech / language delay',
    'behaviour'     => 'Parent-reported: behaviour / emotional concern',
    'autism'        => 'Parent-reported: autism / developmental concern',
    'learning'      => 'Parent-reported: learning difficulty',
    'adhd'          => 'Parent-reported: ADHD / focus concern',
    'sensory_motor' => 'Parent-reported: sensory / motor concern',
    'not_sure'      => 'Parent-reported: needs guidance',
][$concern] ?? '';

// Only create a child if this parent has none — don't spam duplicates for
// repeat signups from the same phone.
$existing = db()->prepare("SELECT id FROM children WHERE parent_id = ? LIMIT 1");
$existing->execute([$pid]);
$child_row = $existing->fetch();

if (!$child_row) {
    db()->prepare("INSERT INTO children (parent_id, name, dob, diagnosis, notes)
                   VALUES (?, ?, ?, ?, ?)")
        ->execute([
            $pid,
            'My child',                                     // parent can edit
            $dob,
            $concern_label,
            "Age range at signup: {$child_age}",
        ]);
    $cid = (int) db()->lastInsertId();
} else {
    $cid = (int) $child_row['id'];
}

// ── Update lead row status (so admin panel shows them converted) ──
if ($lead_id) {
    try {
        db()->prepare("UPDATE leads SET status = 'self_signed_up', notes = ? WHERE id = ?")
            ->execute(["Parent self-registered. parent_id={$pid}, child_id={$cid}.", $lead_id]);
    } catch (Throwable $_) {}
}

// ── Session + remember cookie ────────────────────────────────
$_SESSION['parent_id'] = $pid;
unset($_SESSION['otp_phone'], $_SESSION['signup_lead']);
set_remember_cookie($pid);

// ── Done — return redirect to the child page so they can start a module ─
echo json_encode([
    'ok'             => true,
    'redirect'       => '/child.php?id=' . $cid,
    'parent_id'      => $pid,
    'child_id'       => $cid,
    'welcome_credits'=> $bonus_granted ? (int)SIGNUP_FREE_CREDITS : 0,
    'first_module_free' => $first_time,
]);
