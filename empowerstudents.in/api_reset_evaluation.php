<?php
/**
 * api_reset_evaluation.php — bumps the child's current_round by +1.
 *
 * Body: { "child_id": N, "confirm": "RESET", "csrf": "..." }
 *
 * Old assessments + expert report stay in DB tagged with the previous round.
 * /child.php now shows zero "done" modules and offers a fresh expert report.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_round.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}

require_parent();
$parent = current_parent();
if (!$parent) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrf = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bad CSRF']); exit;
}

if (($body['confirm'] ?? '') !== 'RESET') {
    echo json_encode(['ok' => false, 'error' => 'Missing confirmation']); exit;
}

$child_id = (int)($body['child_id'] ?? 0);
if ($child_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Bad child id']); exit;
}

// Verify ownership: only the owning parent can reset
$child = child_for_parent($child_id);
if (!$child) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not your child']); exit;
}

$new_round = reset_evaluation_round((int)$parent['id'], $child_id);
if ($new_round <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Reset failed']); exit;
}

echo json_encode([
    'ok'        => true,
    'new_round' => $new_round,
    'child_id'  => $child_id,
]);
