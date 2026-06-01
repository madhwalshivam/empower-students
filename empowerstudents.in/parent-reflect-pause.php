<?php
/**
 * parent-reflect-pause.php
 *
 * POST endpoint to mark an in-progress reflection session as paused.
 * The session stays 'in_progress' — but with v3_paused = 1, so the
 * parent can resume from the same point later.
 *
 * Input: csrf, session_id
 * Output JSON: { ok: true } or { ok: false, error: ... }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_eval_v3.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

if (!csrf_check($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
}

$sid = (int)($_POST['session_id'] ?? 0);
if ($sid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session_id']);
    exit;
}

_pr_v3_ensure_columns();

$st = db()->prepare("SELECT id, status FROM parent_reflect_sessions WHERE id = ? AND parent_id = ?");
$st->execute([$sid, $parent_id]);
$row = $st->fetch();
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Session not found']);
    exit;
}
if ($row['status'] !== 'in_progress') {
    echo json_encode(['ok' => false, 'error' => 'Session is not in progress (status=' . $row['status'] . ')']);
    exit;
}

db()->prepare("UPDATE parent_reflect_sessions SET v3_paused = 1, v3_paused_at = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$sid]);

echo json_encode(['ok' => true, 'session_id' => $sid]);
