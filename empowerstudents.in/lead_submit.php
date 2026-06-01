<?php
/**
 * lead_submit.php  ── NEW BEHAVIOUR (Nov 2026)
 *
 * The homepage "Book My Free Evaluation" form no longer routes to an admin
 * who calls back. Instead:
 *
 *   1. Validate the 4 fields (parent name, phone, age range, concern)
 *   2. Save a row in `leads` (still useful for marketing analytics)
 *   3. Send a 6-digit WhatsApp OTP to the parent
 *   4. Stash the form data in $_SESSION['signup_lead'] for /lead_verify.php
 *   5. Return JSON { ok: true, step: 'otp', phone: '+91...' }
 *
 * /lead_verify.php finishes the signup: creates parents row, auto-creates a
 * child from the age range + concern, grants 100 welcome credits AND sets
 * `parents.free_module_credit = 1` so the parent's FIRST module run is free.
 *
 * Errors still return { ok: false, error: '…' } as before.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sms.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Idempotent table create ───────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS leads (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_name   TEXT NOT NULL,
        phone         TEXT NOT NULL,
        child_age     TEXT,
        concern       TEXT,
        message       TEXT,
        source        TEXT,
        utm_source    TEXT,
        utm_medium    TEXT,
        utm_campaign  TEXT,
        utm_content   TEXT,
        utm_term      TEXT,
        referrer      TEXT,
        user_agent    TEXT,
        ip            TEXT,
        status        TEXT DEFAULT 'new',
        notes         TEXT,
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
    );");
} catch (Throwable $_) {}

// ── CSRF (soft-check, same as before) ─────────────────────────
$csrf = $_POST['csrf'] ?? '';
if (!csrf_check($csrf)) {
    error_log('[lead_submit] CSRF mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
}

// ── Honeypot ──────────────────────────────────────────────────
if (!empty($_POST['website']) || !empty($_POST['url'])) {
    echo json_encode(['ok' => true, 'step' => 'done', 'lead_id' => 0]);
    exit;
}

// ── Per-IP rate limit (6/hour, session-based) ─────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();
$bucket = $_SESSION['lead_rate'] ?? [];
$bucket = array_filter($bucket, fn($t) => $t > $now - 3600);
if (count($bucket) >= 6) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many submissions. Please WhatsApp us directly on 9311883132.']);
    exit;
}
$bucket[] = $now;
$_SESSION['lead_rate'] = $bucket;

// ── Validate inputs ────────────────────────────────────────────
$parent_name = trim((string)($_POST['parent_name'] ?? ''));
$phone_raw   = trim((string)($_POST['phone'] ?? ''));
$child_age   = trim((string)($_POST['child_age'] ?? ''));
$concern     = trim((string)($_POST['concern'] ?? ''));

if ($parent_name === '' || mb_strlen($parent_name) < 2 || mb_strlen($parent_name) > 80) {
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid name.']);
    exit;
}

$phone = normalize_phone($phone_raw);
if (!preg_match('/^\+\d{10,15}$/', $phone)) {
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid WhatsApp number.']);
    exit;
}

$valid_ages = ['0-2', '2-4', '5-7', '8-10', '11-14', '15+'];
if (!in_array($child_age, $valid_ages, true)) {
    echo json_encode(['ok' => false, 'error' => 'Please pick your child\'s age range.']);
    exit;
}

$valid_concerns = ['speech', 'behaviour', 'autism', 'learning', 'adhd', 'sensory_motor', 'not_sure'];
if (!in_array($concern, $valid_concerns, true)) {
    echo json_encode(['ok' => false, 'error' => 'Please pick your main concern.']);
    exit;
}

$source       = (string)($_POST['source'] ?? 'homepage');
$utm_source   = (string)($_POST['utm_source']   ?? '');
$utm_medium   = (string)($_POST['utm_medium']   ?? '');
$utm_campaign = (string)($_POST['utm_campaign'] ?? '');
$utm_content  = (string)($_POST['utm_content']  ?? '');
$utm_term     = (string)($_POST['utm_term']     ?? '');
$referrer     = $_SERVER['HTTP_REFERER']    ?? '';
$user_agent   = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ── Save lead for analytics (no email sent) ───────────────────
$lead_id = null;
try {
    $st = db()->prepare("INSERT INTO leads
        (parent_name, phone, child_age, concern, source,
         utm_source, utm_medium, utm_campaign, utm_content, utm_term,
         referrer, user_agent, ip, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $parent_name, $phone, $child_age, $concern, $source,
        $utm_source, $utm_medium, $utm_campaign, $utm_content, $utm_term,
        substr($referrer, 0, 500), substr($user_agent, 0, 300), $ip,
        'self_signup_pending'
    ]);
    $lead_id = (int)db()->lastInsertId();
} catch (Throwable $e) {
    error_log('[lead_submit] DB error: ' . $e->getMessage());
    // Non-fatal — continue with OTP send. Lead saving is for analytics only.
}

// ── OTP send (throttle resends 30s) ──────────────────────────
$last = db()->prepare("SELECT sent_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
$last->execute([$phone]);
$row = $last->fetch();
if ($row && (time() - strtotime($row['sent_at'])) < OTP_RESEND_GAP) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Please wait ' . OTP_RESEND_GAP . ' seconds before requesting another OTP.',
    ]);
    exit;
}

$code = generate_otp_code();
$hash = password_hash($code, PASSWORD_DEFAULT);
$exp  = date('Y-m-d H:i:s', time() + OTP_TTL_SECS);

try {
    db()->prepare("INSERT INTO otps (whatsapp, code_hash, expires_at) VALUES (?,?,?)")
        ->execute([$phone, $hash, $exp]);
} catch (Throwable $e) {
    error_log('[lead_submit] OTP insert failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not start OTP. Please try again.']);
    exit;
}

$send = send_otp_message($phone, $code);

// ── Twilio failed? Tell the user honestly (don't pretend OTP arrived) ──
if (!is_array($send) || empty($send['ok'])) {
    error_log('[lead_submit] OTP send failed for ' . $phone . ': ' .
              ($send['message'] ?? 'unknown') . ' | OTP_MODE=' . OTP_MODE);
    // Mark the OTP row as already expired so user can request a fresh one immediately
    db()->prepare("UPDATE otps SET expires_at = ? WHERE whatsapp = ? AND used_at IS NULL")
        ->execute([date('Y-m-d H:i:s', time() - 60), $phone]);
    echo json_encode([
        'ok'    => false,
        'error' => 'Could not send WhatsApp OTP right now. Please try again in a minute or WhatsApp us on 9311883132.',
    ]);
    exit;
}

// ── Stash form data for /lead_verify.php ─────────────────────
$_SESSION['signup_lead'] = [
    'phone'       => $phone,
    'parent_name' => $parent_name,
    'child_age'   => $child_age,
    'concern'     => $concern,
    'lead_id'     => $lead_id,
    'started_at'  => time(),
];
$_SESSION['otp_phone'] = $phone;

// ── Return ────────────────────────────────────────────────────
$response = [
    'ok'      => true,
    'step'    => 'otp',
    'phone'   => $phone,
    'lead_id' => $lead_id,
];
// Demo mode (config OTP_MODE='demo') — echo OTP so dev can test
if (defined('OTP_MODE') && OTP_MODE === 'demo') {
    $response['demo_otp'] = $code;
}
echo json_encode($response);
