<?php
/**
 * /api/history.php
 *
 * Returns JSON list of all "done" assessments for a (child, module) pair
 * scoped to the logged-in parent. Used by the dashboard's history modal.
 *
 * Query params:
 *   cid     = child_id   (must belong to current parent)
 *   module  = module_key (e.g. "math", "mind_power")
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$parent = current_parent();
if (!$parent) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$cid    = (int)($_GET['cid'] ?? 0);
$module = trim((string)($_GET['module'] ?? ''));

// Validate child belongs to parent
$child = child_for_parent($cid);
if (!$child) {
    http_response_code(404);
    echo json_encode(['error' => 'Child not found']);
    exit;
}
if ($module === '' || !preg_match('/^[a-z_]{2,40}$/', $module)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid module']);
    exit;
}

$st = db()->prepare(
    "SELECT id, score, completed_at, ai_summary, level_reached
     FROM assessments
     WHERE child_id = ? AND module = ? AND status = 'done'
     ORDER BY completed_at DESC"
);
$st->execute([$cid, $module]);
$rows = $st->fetchAll();

$attempts = [];
foreach ($rows as $r) {
    $attempts[] = [
        'id'                  => (int)$r['id'],
        'score'               => $r['score'] !== null ? (float)$r['score'] : null,
        'level'               => $r['level_reached'],
        'completed_at'        => $r['completed_at'],
        'completed_at_short'  => substr($r['completed_at'] ?? '', 0, 16),
        'ai_summary'          => $r['ai_summary'] ?: '',
    ];
}

echo json_encode(['module' => $module, 'child_id' => $cid, 'attempts' => $attempts]);
