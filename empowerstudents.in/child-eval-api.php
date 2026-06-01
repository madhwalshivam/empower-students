<?php
/**
 * child-eval-api.php
 *
 * JSON API for the child-eval flow:
 *   POST action=next      session_id=N
 *   POST action=answer    session_id=N answer=<json or string>
 *   POST action=finalise  session_id=N
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
@set_time_limit(120);
@ini_set('max_execution_time', 120);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/child_eval_engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$session_id = (int)($_POST['session_id'] ?? 0);
if (!$session_id) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
}

// Verify session belongs to a child of this parent
$s = db()->prepare("SELECT s.id, c.parent_id FROM child_eval_sessions s
                     LEFT JOIN children c ON c.id = s.child_id
                     WHERE s.id = ?");
$s->execute([$session_id]);
$row = $s->fetch();
if (!$row || (int)$row['parent_id'] !== $parent_id) {
    echo json_encode(['ok' => false, 'error' => 'Session does not belong to you']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'next') {
    $r = ce_generate_next_question($session_id);
    echo json_encode($r);
    exit;
}

if ($action === 'answer') {
    $raw = (string)($_POST['answer'] ?? '');
    // Try JSON-decode; fall back to string
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = ['text' => $raw];
    $r = ce_submit_answer($session_id, $payload);
    echo json_encode($r);
    exit;
}

if ($action === 'finalise') {
    $r = ce_finalise_session($session_id);
    echo json_encode($r);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
