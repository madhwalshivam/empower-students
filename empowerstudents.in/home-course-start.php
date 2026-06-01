<?php
/**
 * home-course-start.php — hardened v2
 *
 * Every step wrapped in exception trap to surface the real error in JSON
 * instead of dying with a 500.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Global trap — any PHP fatal becomes a JSON error
set_exception_handler(function($e) {
    error_log('[home-course-start FATAL] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace_top' => explode("\n", $e->getTraceAsString())[0] ?? '',
        ],
    ]);
    exit;
});
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => 'PHP fatal: ' . $err['message'],
            'debug' => ['file' => basename($err['file']), 'line' => $err['line']],
        ]);
    }
});

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/parent_reflect_schema.php';
require_once __DIR__ . '/includes/parent_reflect_home_climate.php';
require_once __DIR__ . '/includes/home_course_engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

if (!csrf_check($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF — please reload']);
    exit;
}

$sku = (string)($_POST['sku'] ?? '');
$reflect_session_id = !empty($_POST['reflect_session_id']) ? (int)$_POST['reflect_session_id'] : null;

if (!in_array($sku, ['home_course_2min', 'home_course_5min', 'home_course_10min'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Pick a valid course']);
    exit;
}

if ($reflect_session_id) {
    try {
        $st = db()->prepare("SELECT id FROM parent_reflect_sessions
                              WHERE id = ? AND parent_id = ? AND status = 'completed'");
        $st->execute([$reflect_session_id, $parent_id]);
        if (!$st->fetch()) {
            $reflect_session_id = null;
        }
    } catch (Throwable $e) {
        error_log('[home-course-start verify_reflect] ' . $e->getMessage());
        $reflect_session_id = null;
    }
}

_home_course_ensure_schema();

$price_check = wallet_service_price($sku);
if ($price_check === null) {
    echo json_encode(['ok' => false, 'error' => "SKU $sku not configured"]);
    exit;
}

// Idempotency
try {
    $dup_st = db()->prepare("SELECT id FROM home_courses
                              WHERE parent_id = ? AND sku = ?
                                AND started_at > datetime('now', '-5 minutes')
                              ORDER BY id DESC LIMIT 1");
    $dup_st->execute([$parent_id, $sku]);
    $dup = $dup_st->fetchColumn();
    if ($dup) {
        echo json_encode([
            'ok'           => true,
            'course_id'    => (int)$dup,
            'redirect'     => '/home-course.php?id=' . (int)$dup,
            'was_existing' => true,
            'message'      => 'Resuming your recent course'
        ]);
        exit;
    }
} catch (Throwable $e) {
    error_log('[home-course-start idempotency] ' . $e->getMessage());
}

$ref_id = $reflect_session_id ?: 0;
$charge = wallet_charge_for_service($parent_id, $sku, $ref_id);

$status = (string)($charge['status'] ?? '');
$price = (int)($charge['price'] ?? 0);

if ($status === 'insufficient') {
    echo json_encode([
        'ok'       => false,
        'error'    => "You need ₹{$price} in wallet (you have ₹" . (int)($charge['credits'] ?? 0) . "). Top up to continue.",
        'redirect' => '/wallet.php?need=' . $price,
    ]);
    exit;
}
if (!in_array($status, ['charged', 'first_free', 'already_charged', 'free'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Could not start: status=' . $status . ' msg=' . ($charge['message'] ?? '')]);
    exit;
}

$create = home_course_create($parent_id, $sku, $reflect_session_id);
if (!$create['ok']) {
    error_log("[home-course-start] create failed: " . ($create['error'] ?? '?'));
    if ($status === 'charged') {
        try {
            wallet_post($parent_id, $price, $sku, $ref_id, 'Refund: home course creation failed', 'system');
        } catch (Throwable $_) {}
    }
    echo json_encode(['ok' => false, 'error' => 'Could not create: ' . ($create['error'] ?? 'unknown')]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'course_id'  => $create['course_id'],
    'redirect'   => '/home-course.php?id=' . $create['course_id'],
    'price_paid' => $price,
    'was_free'   => $status === 'first_free' || $status === 'free',
]);
