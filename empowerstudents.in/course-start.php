<?php
/**
 * course-start.php
 *
 * POST endpoint that creates a new 7-day course for the parent.
 *
 * Input (form-encoded):
 *   csrf        — required
 *   sku         — 'course_speech_2min' | '5min' | '10min'
 *   child_id    — required, must belong to logged-in parent
 *   eval_session_id — optional but recommended; the eval whose clinical report informs the course
 *
 * Output (JSON):
 *   { ok: true, course_id: int, redirect: '/course.php?id=N' }
 *   OR
 *   { ok: false, error: '...' }
 *
 * Wallet charge:
 *   - Full price upfront via wallet_charge_for_service
 *   - Honors free_module_credit if available (new signups' first module free)
 *   - Idempotent: charging same SKU+child within 5 min returns the existing course
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/course_engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF — please reload']);
    exit;
}

$sku = (string)($_POST['sku'] ?? '');
$child_id = (int)($_POST['child_id'] ?? 0);
$eval_session_id = !empty($_POST['eval_session_id']) ? (int)$_POST['eval_session_id'] : null;

if (!in_array($sku, ['course_speech_2min', 'course_speech_5min', 'course_speech_10min'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Pick a valid course']);
    exit;
}

// Verify child belongs to parent
$cst = db()->prepare("SELECT id, name FROM children WHERE id = ? AND parent_id = ?");
$cst->execute([$child_id, $parent_id]);
$child = $cst->fetch();
if (!$child) {
    echo json_encode(['ok' => false, 'error' => 'Child not found']);
    exit;
}

// Verify eval_session_id belongs to parent (if provided)
if ($eval_session_id) {
    $vst = db()->prepare("SELECT id FROM eval_sessions WHERE id = ? AND parent_id = ?");
    $vst->execute([$eval_session_id, $parent_id]);
    if (!$vst->fetch()) {
        $eval_session_id = null;
    }
}

// Idempotency — if parent already started this SKU for this child within last 5 minutes,
// return the existing course (prevents accidental double-charge from double-clicks)
_course_ensure_schema();
$dup_st = db()->prepare("SELECT id FROM eval_courses
                          WHERE parent_id = ? AND child_id = ? AND sku = ?
                            AND started_at > datetime('now', '-5 minutes')
                          ORDER BY id DESC LIMIT 1");
$dup_st->execute([$parent_id, $child_id, $sku]);
$dup = $dup_st->fetchColumn();
if ($dup) {
    echo json_encode([
        'ok'          => true,
        'course_id'   => (int)$dup,
        'redirect'    => '/course.php?id=' . (int)$dup,
        'was_existing'=> true,
        'message'     => 'Resuming your recent course'
    ]);
    exit;
}

// Charge the wallet (uses free_module_credit if available — homepage signup freebie)
$charge = wallet_charge_for_service($parent_id, $sku, $child_id);
if ($charge['status'] === 'insufficient') {
    echo json_encode([
        'ok'       => false,
        'error'    => "You need ₹{$charge['price']} in your wallet (you have ₹{$charge['credits']}). Top up to continue.",
        'redirect' => '/wallet.php?need=' . (int)$charge['price'],
    ]);
    exit;
}
if (!in_array($charge['status'], ['charged', 'first_free', 'already_charged'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Could not start course: ' . ($charge['message'] ?? 'wallet error')]);
    exit;
}

// Create the course row (anchor sentence picked, weak axes captured)
$create = course_create($parent_id, $child_id, $sku, $eval_session_id);
if (!$create['ok']) {
    error_log("[course-start] course_create failed: " . $create['error']);
    // Refund: re-credit the wallet if we charged
    if ($charge['status'] === 'charged') {
        try {
            wallet_post($parent_id, $charge['price'], $sku, $child_id,
                        "Refund: course creation failed", 'system');
        } catch (Throwable $_) {}
    }
    echo json_encode(['ok' => false, 'error' => 'Could not create course: ' . $create['error']]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'course_id'  => $create['course_id'],
    'redirect'   => '/course.php?id=' . $create['course_id'],
    'price_paid' => (int)($charge['price'] ?? 0),
    'was_free'   => $charge['status'] === 'first_free',
]);
