<?php
/**
 * eval-speech-api.php
 *
 * JSON API for the voice-interview eval. The SPA (eval-speech.php) calls
 * this for every state transition: start, fetch next question, score answer,
 * finalise.
 *
 * Auth: parent session required.
 * Output: always JSON, never HTML.
 *
 * Actions (POST):
 *   start           — { child_id }                    → { session_id, question }
 *   answer          — multipart: { session_id, question_id, transcript,
 *                                  time_seconds, acoustic[*], audio (file) }
 *                                                       → { should_stop, question | report }
 *   cancel          — { session_id }                  → { ok: true }
 */

header('Content-Type: application/json; charset=utf-8');

// Trap fatal errors and warnings so we always return JSON, never HTML
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log("[eval-speech-api E$errno] $errstr in $errfile:$errline");
    // Don't echo here — let normal flow run; only fatals abort
    return true;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Wipe whatever HTML may have leaked
        if (ob_get_level()) ob_end_clean();
        echo json_encode([
            'error' => 'Server error: ' . $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line'],
        ]);
    }
});
ob_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/eval_engine.php';

// Discard any incidental output from requires (warnings etc.) — keep only our JSON
if (ob_get_level()) ob_clean();

// Force POST + auth
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

require_parent();
$parent  = current_parent();
$parent_id = (int)$parent['id'];

if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

// ─────────────────────────────────────────────────────────────
// Helper: build question DTO for client
// ─────────────────────────────────────────────────────────────
function build_question_dto(array $q): array {
    return [
        'question_id'   => (int)$q['question_id'],
        'seq_no'        => (int)$q['seq_no'],
        'level'         => (int)$q['level'],
        'type'          => (string)$q['type'],
        'prompt'        => (string)$q['prompt'],
        'image_concept' => $q['image_concept'] ?? null,
    ];
}

// ─────────────────────────────────────────────────────────────
// action=start
// ─────────────────────────────────────────────────────────────
if ($action === 'start') {
    $cid = (int)($_POST['child_id'] ?? 0);

    $st = db()->prepare("SELECT * FROM children WHERE id = ? AND parent_id = ?");
    $st->execute([$cid, $parent_id]);
    $child = $st->fetch();
    if (!$child) {
        echo json_encode(['error' => 'Please select a valid child.']);
        exit;
    }

    // Look for an in-progress session worth resuming.
    // We resume only if it's recent (< 30 min old) AND has at least 1 question already.
    // This prevents resuming polluted/orphaned sessions from previous attempts.
    $st = db()->prepare("SELECT id, started_at, questions_asked FROM eval_sessions
                         WHERE parent_id = ? AND child_id = ? AND module = 'mod_speech_basic'
                           AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $st->execute([$parent_id, $cid]);
    $row = $st->fetch();
    $existing = 0;
    if ($row) {
        $age_min = (time() - strtotime((string)$row['started_at'] . ' UTC')) / 60;
        if ($age_min < 30 && (int)$row['questions_asked'] > 0) {
            $existing = (int)$row['id'];
        } else {
            // Mark stale/empty session abandoned so it won't come back
            db()->prepare("UPDATE eval_sessions SET status = 'abandoned' WHERE id = ?")
               ->execute([(int)$row['id']]);
        }
    }

    if (!$existing) {
        // Free or charge ₹59
        $is_free = eval_free_eligible($parent_id);
        $cost_paid = 0;
        if (!$is_free) {
            $r = wallet_charge_for_service($parent_id, 'mod_speech_eval');
            if ($r['status'] === 'insufficient') {
                echo json_encode(['error' => 'Need ₹59 in your wallet. Please top up.', 'redirect' => '/wallet.php?need=' . (int)$r['price']]);
                exit;
            }
            if ($r['status'] !== 'charged' && $r['status'] !== 'already_charged') {
                echo json_encode(['error' => 'Could not start evaluation. Please try again.']);
                exit;
            }
            $cost_paid = 59;
        }
        $session_id = eval_start_session($parent_id, $cid, 'mod_speech_basic', $is_free, $cost_paid);
        if ($is_free) eval_consume_free($parent_id);
    } else {
        $session_id = $existing;
    }

    $q = eval_next_question($session_id, true);  // voice mode
    if (!$q) {
        // Fallback to canned bank so the eval can still start
        $sst = db()->prepare("SELECT s.*, c.mother_tongue AS child_mt FROM eval_sessions s JOIN children c ON c.id = s.child_id WHERE s.id = ?");
        $sst->execute([$session_id]);
        $sess = $sst->fetch();
        $is_hindi = $sess && (preg_match('/[\x{0900}-\x{097F}]/u', (string)$sess['child_mt'])
                  || stripos((string)$sess['child_mt'], 'hindi') !== false);
        $canned = eval_canned_question(3, (bool)$is_hindi);
        db()->prepare("INSERT INTO eval_questions
                       (session_id, seq_no, level, question_type, prompt, options_json, expected, image_concept)
                       VALUES (?, 1, 3, ?, ?, NULL, ?, NULL)")
           ->execute([$session_id, $canned['type'], $canned['prompt'], $canned['expected']]);
        $qid = (int) db()->lastInsertId();
        db()->prepare("UPDATE eval_sessions SET questions_asked = 1 WHERE id = ?")->execute([$session_id]);
        $q = [
            'question_id'   => $qid,
            'seq_no'        => 1,
            'level'         => 3,
            'type'          => $canned['type'],
            'prompt'        => $canned['prompt'],
            'options'       => null,
            'image_concept' => null,
        ];
    }

    echo json_encode([
        'session_id' => $session_id,
        'question'   => build_question_dto($q),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// action=answer  (multipart for audio file)
// ─────────────────────────────────────────────────────────────
if ($action === 'answer') {
    $_t0 = microtime(true);
    $_timings = [];
    $sid = (int)($_POST['session_id'] ?? 0);
    $qid = (int)($_POST['question_id'] ?? 0);
    $transcript = trim((string)($_POST['transcript'] ?? ''));
    $sec = max(1, min(600, (int)($_POST['time_seconds'] ?? 30)));

    // Verify session ownership
    $st = db()->prepare("SELECT * FROM eval_sessions WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $session = $st->fetch();
    $_timings['session_lookup'] = round((microtime(true) - $_t0) * 1000);
    if (!$session || $session['status'] !== 'in_progress') {
        echo json_encode(['error' => 'Session not found or already completed.', '_timings' => $_timings]);
        exit;
    }

    // Build acoustic features
    $acoustic = [];
    foreach (['transcript_confidence','duration_sec','wpm',
              'volume_variance','silence_ratio','pause_count',
              'time_to_first_speech_sec'] as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $acoustic[$k] = (strpos($k, 'count') !== false) ? (int)$_POST[$k] : (float)$_POST[$k];
        }
    }

    // Empty transcript fallback — accept the answer as empty so engine can mark it wrong+slow
    if ($transcript === '') {
        $transcript = '(no response)';
    }

    // Optional: store audio file
    $audio_path = null;
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $size = (int)$_FILES['audio']['size'];
        if ($size >= 1000 && $size <= 5 * 1024 * 1024) {
            $upload_dir = __DIR__ . '/uploads/eval';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0775, true);
            $fname = 'q' . $qid . '_' . time() . '.webm';
            $dest  = $upload_dir . '/' . $fname;
            if (@move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
                $audio_path = '/uploads/eval/' . $fname;
            }
        }
    }

    // ONE combined call: score + next-question generation in a single Haiku roundtrip.
    // ~50% latency reduction vs the legacy two-step.
    $_t1 = microtime(true);
    $combined = eval_score_and_next($sid, $qid, $transcript, $sec, $acoustic);
    $_timings['combined_call'] = round((microtime(true) - $_t1) * 1000);
    $r = $combined['scoring'];
    $next = $combined['question'];

    if ($audio_path) {
        db()->prepare("UPDATE eval_questions SET audio_path = ? WHERE id = ?")
           ->execute([$audio_path, $qid]);
    }

    // Hard time cap: if session has been running > 5 min, also stop.
    // SQLite's CURRENT_TIMESTAMP stores UTC. PHP strtotime() treats naive timestamps
    // as local time. Append " UTC" to force correct parsing.
    $started = strtotime((string)$session['started_at'] . ' UTC');
    $elapsed = time() - $started;
    if ($started && $elapsed > 300) {
        $r['should_stop'] = true;
        $r['reason'] = 'session exceeded 5 min cap';
        $next = null;
    }

    if ($r['should_stop']) {
        eval_finalise($sid);
        $st = db()->prepare("SELECT * FROM eval_sessions WHERE id = ?");
        $st->execute([$sid]);
        $s2 = $st->fetch();
        $_timings['total'] = round((microtime(true) - $_t0) * 1000);
        echo json_encode([
            'should_stop' => true,
            '_timings'    => $_timings,
            'report' => [
                'final_level'       => (int)$s2['final_level'],
                'final_pct'         => (int)$s2['final_pct'],
                'questions_asked'   => (int)$s2['questions_asked'],
                'report_md'         => (string)$s2['report_md'],
                'sample_exercise_md'=> (string)$s2['sample_exercise_md'],
                'final_level_name'  => eval_speech_level_desc((int)$s2['final_level'])['name'],
                'child_id'          => (int)$s2['child_id'],
            ]
        ]);
        exit;
    }

    if (!$next) {
        // Generation failed — finalise with what we have
        eval_finalise($sid);
        $st = db()->prepare("SELECT * FROM eval_sessions WHERE id = ?");
        $st->execute([$sid]);
        $s2 = $st->fetch();
        $_timings['total'] = round((microtime(true) - $_t0) * 1000);
        echo json_encode([
            'should_stop' => true,
            'reason'   => 'generation_failed',
            '_timings' => $_timings,
            'report' => [
                'final_level'       => (int)$s2['final_level'],
                'final_pct'         => (int)$s2['final_pct'],
                'questions_asked'   => (int)$s2['questions_asked'],
                'report_md'         => (string)$s2['report_md'],
                'sample_exercise_md'=> (string)$s2['sample_exercise_md'],
                'final_level_name'  => eval_speech_level_desc((int)$s2['final_level'])['name'],
                'child_id'          => (int)$s2['child_id'],
            ]
        ]);
        exit;
    }

    $_timings['total'] = round((microtime(true) - $_t0) * 1000);
    echo json_encode([
        'should_stop'  => false,
        'last_correct' => $r['is_correct'],
        '_timings'     => $_timings,
        'question'     => build_question_dto($next),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// action=cancel
// ─────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    $sid = (int)($_POST['session_id'] ?? 0);
    db()->prepare("UPDATE eval_sessions SET status = 'abandoned' WHERE id = ? AND parent_id = ?")
       ->execute([$sid, $parent_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// Unknown action
echo json_encode(['error' => 'Unknown action']);
exit;
