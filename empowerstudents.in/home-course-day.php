<?php
/**
 * home-course-day.php
 *
 * POST: submit today's anchor recording for a home course.
 *
 * Input (multipart):
 *   csrf, course_id, day_no, transcript, parent_note (optional)
 *   acoustic fields: wpm, silence_ratio, pause_count, time_to_first_speech_sec, duration_sec, transcript_confidence, volume_variance
 *   audio (file, optional)
 *
 * Output JSON:
 *   { ok: true, snapshot: {sentiment, energy, openness, ...}, is_course_done, redirect }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
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

$course_id = (int)($_POST['course_id'] ?? 0);
$day_no = (int)($_POST['day_no'] ?? 0);
$transcript = trim((string)($_POST['transcript'] ?? ''));
$parent_note = trim((string)($_POST['parent_note'] ?? ''));

$st = db()->prepare("SELECT * FROM home_courses WHERE id = ? AND parent_id = ?");
$st->execute([$course_id, $parent_id]);
$course = $st->fetch();
if (!$course) {
    echo json_encode(['ok' => false, 'error' => 'Course not found']);
    exit;
}

if ($course['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => "Course is {$course['status']}", 'redirect' => '/home-course.php?id=' . $course_id]);
    exit;
}

$today = home_course_today_day($course_id);
if ($today['status'] === 'failed') {
    echo json_encode(['ok' => false, 'error' => $today['failed_reason'], 'redirect' => '/home-course.php?id=' . $course_id]);
    exit;
}
if ((int)$day_no !== (int)$today['day_no']) {
    echo json_encode([
        'ok' => false,
        'error' => "You're on Day {$today['day_no']}, not Day {$day_no}. Please refresh.",
        'redirect' => '/home-course.php?id=' . $course_id,
    ]);
    exit;
}

if ($transcript === '') $transcript = '(no response)';

$acoustic = [];
foreach (['transcript_confidence','duration_sec','wpm','volume_variance',
          'silence_ratio','pause_count','time_to_first_speech_sec'] as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') {
        $acoustic[$k] = (strpos($k, 'count') !== false) ? (int)$_POST[$k] : (float)$_POST[$k];
    }
}

$audio_path = null;
if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $size = (int)$_FILES['audio']['size'];
    if ($size >= 1000 && $size <= 5 * 1024 * 1024) {
        $upload_dir = __DIR__ . '/uploads/home_course';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0775, true);
        $fname = 'hc' . $course_id . '_d' . $day_no . '_' . time() . '.webm';
        $dest  = $upload_dir . '/' . $fname;
        if (@move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
            $audio_path = '/uploads/home_course/' . $fname;
        }
    }
}

$result = home_course_score_daily_recording($course_id, $day_no, $audio_path, $transcript, $acoustic, $parent_note);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Save failed']);
    exit;
}

$st->execute([$course_id, $parent_id]);
$updated = $st->fetch();

echo json_encode([
    'ok'             => true,
    'snapshot'       => $result['snapshot'],
    'course_status'  => $updated['status'],
    'is_course_done' => $updated['status'] === 'completed',
    'next_day_no'    => $updated['status'] === 'completed' ? null : ($day_no + 1),
    'redirect'       => '/home-course.php?id=' . $course_id,
]);
