<?php
/**
 * course-day.php
 *
 * POST endpoint: submit today's anchor-sentence recording for a course.
 *
 * Input (multipart/form-data):
 *   csrf, course_id, day_no, transcript, parent_note (optional)
 *   acoustic[transcript_confidence|wpm|silence_ratio|pause_count|time_to_first_speech_sec|duration_sec]
 *   audio (file, optional)
 *
 * Output (JSON):
 *   {
 *     ok: true,
 *     snapshot: { articulation: 80, fluency: 65, ... },
 *     next_day_no: 2 | null (if course complete),
 *     course_status: 'active' | 'completed',
 *     redirect: '/course.php?id=N' (always — page handles state)
 *   }
 *   OR
 *   { ok: false, error: '...' }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
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
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF — please reload']);
    exit;
}

$course_id = (int)($_POST['course_id'] ?? 0);
$day_no = (int)($_POST['day_no'] ?? 0);
$transcript = trim((string)($_POST['transcript'] ?? ''));
$parent_note = trim((string)($_POST['parent_note'] ?? ''));

// Verify course ownership
$st = db()->prepare("SELECT * FROM eval_courses WHERE id = ? AND parent_id = ?");
$st->execute([$course_id, $parent_id]);
$course = $st->fetch();
if (!$course) {
    echo json_encode(['ok' => false, 'error' => 'Course not found']);
    exit;
}

// Verify status + day_no
if ($course['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => "Course is {$course['status']}, cannot record",
                      'redirect' => '/course.php?id=' . $course_id]);
    exit;
}

$today = course_today_day($course_id);
if ($today['status'] === 'failed') {
    echo json_encode(['ok' => false, 'error' => $today['failed_reason'],
                      'redirect' => '/course.php?id=' . $course_id]);
    exit;
}
if ((int)$day_no !== (int)$today['day_no']) {
    echo json_encode(['ok' => false,
        'error' => "You're on Day {$today['day_no']}, not Day {$day_no}. Please refresh.",
        'redirect' => '/course.php?id=' . $course_id]);
    exit;
}

// Empty transcript fallback
if ($transcript === '') {
    $transcript = '(no response)';
}

// Build acoustic features
$acoustic = [];
foreach (['transcript_confidence','duration_sec','wpm','volume_variance',
          'silence_ratio','pause_count','time_to_first_speech_sec'] as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') {
        $acoustic[$k] = (strpos($k, 'count') !== false) ? (int)$_POST[$k] : (float)$_POST[$k];
    }
}

// Optional audio upload
$audio_path = null;
if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $size = (int)$_FILES['audio']['size'];
    if ($size >= 1000 && $size <= 5 * 1024 * 1024) {
        $upload_dir = __DIR__ . '/uploads/course';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0775, true);
        $fname = 'c' . $course_id . '_d' . $day_no . '_' . time() . '.webm';
        $dest  = $upload_dir . '/' . $fname;
        if (@move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
            $audio_path = '/uploads/course/' . $fname;
        }
    }
}

// Score the recording
$result = course_score_daily_recording($course_id, $day_no, $audio_path, $transcript, $acoustic, $parent_note);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not save recording']);
    exit;
}

// Reload course to check if newly-completed
$st->execute([$course_id, $parent_id]);
$updated = $st->fetch();

echo json_encode([
    'ok'              => true,
    'snapshot'        => $result['snapshot'],
    'course_status'   => $updated['status'],
    'is_course_done'  => $updated['status'] === 'completed',
    'next_day_no'     => $updated['status'] === 'completed' ? null : ($day_no + 1),
    'redirect'        => '/course.php?id=' . $course_id,
]);
