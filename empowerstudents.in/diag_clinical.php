<?php
/**
 * diag_clinical.php — one-shot diagnostic for failed clinical analysis.
 *
 * Loads the most recent completed session for the logged-in parent,
 * re-runs eval_clinical_analyse() with verbose error capture, and shows
 * exactly where it fails.
 *
 * Usage:
 *   1. Upload to /empowerstudents.in/
 *   2. Log in as the parent whose session has clinical_report_json = null
 *   3. Visit https://empowerstudents.in/diag_clinical.php
 *   4. Read the diagnostic output
 *   5. DELETE this file from server
 */

// Capture all errors verbosely
ini_set('display_errors', '1');
error_reporting(E_ALL);
ob_implicit_flush(true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/eval_engine.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

header('Content-Type: text/plain; charset=utf-8');

echo "=== diag_clinical.php — Clinical Analysis Diagnostic ===\n";
echo "Parent: " . ($parent['name'] ?: $parent['whatsapp']) . " (id=$parent_id)\n";
echo "Time: " . date('Y-m-d H:i:s') . " IST\n\n";

// 1. Find the latest completed session
$st = db()->prepare("SELECT * FROM eval_sessions
                     WHERE parent_id = ? AND status = 'completed'
                     ORDER BY id DESC LIMIT 5");
$st->execute([$parent_id]);
$sessions = $st->fetchAll();

if (empty($sessions)) {
    echo "❌ No completed sessions found for this parent.\n";
    exit;
}

echo "=== Last 5 completed sessions ===\n";
foreach ($sessions as $s) {
    echo sprintf("  #%d — completed %s — level L%d, %d%% accuracy, %d questions\n",
        $s['id'], $s['completed_at'],
        (int)$s['final_level'], (int)$s['final_pct'],
        (int)$s['questions_asked']
    );
    echo sprintf("        clinical_report_json: %s\n",
        empty($s['clinical_report_json']) ? '❌ NULL' : '✓ present (' . strlen($s['clinical_report_json']) . ' bytes)'
    );
}

$target = $sessions[0];
$sid = (int)$target['id'];
echo "\n=== Diagnosing session #$sid (most recent) ===\n";

// 2. Check column exists
echo "\n--- Column check ---\n";
$cols = db()->query("PRAGMA table_info(eval_sessions)")->fetchAll();
$names = array_column($cols, 'name');
$has_clinical_col = in_array('clinical_report_json', $names, true);
$has_summary_col = in_array('clinical_axes_summary', $names, true);
echo "  clinical_report_json column exists: " . ($has_clinical_col ? '✓ YES' : '❌ NO') . "\n";
echo "  clinical_axes_summary column exists: " . ($has_summary_col ? '✓ YES' : '❌ NO') . "\n";

// 3. Check eval_clinical.php exists
echo "\n--- File check ---\n";
$clinical_path = __DIR__ . '/includes/eval_clinical.php';
echo "  eval_clinical.php exists: " . (file_exists($clinical_path) ? '✓ YES' : '❌ NO') . " ($clinical_path)\n";

if (!file_exists($clinical_path)) {
    echo "\n❌ FATAL: includes/eval_clinical.php is missing. Upload it to fix.\n";
    exit;
}

// 4. Check function loads cleanly
echo "\n--- Function load check ---\n";
try {
    require_once $clinical_path;
    echo "  require_once succeeded: ✓\n";
} catch (Throwable $e) {
    echo "  ❌ require_once FAILED: " . $e->getMessage() . "\n";
    exit;
}
echo "  eval_clinical_analyse function exists: " . (function_exists('eval_clinical_analyse') ? '✓' : '❌') . "\n";
echo "  _clinical_compute_priors function exists: " . (function_exists('_clinical_compute_priors') ? '✓' : '❌') . "\n";
echo "  _clinical_call_claude function exists: " . (function_exists('_clinical_call_claude') ? '✓' : '❌') . "\n";

// 5. Check eval_questions data
echo "\n--- eval_questions data for session #$sid ---\n";
$qs = db()->prepare("SELECT id, seq_no, level, question_type, user_answer, is_correct, time_seconds, acoustic_json
                     FROM eval_questions WHERE session_id = ? ORDER BY seq_no");
$qs->execute([$sid]);
$questions = $qs->fetchAll();
echo "  Total question rows: " . count($questions) . "\n";
$scored = 0;
foreach ($questions as $q) {
    if ($q['is_correct'] !== null) $scored++;
}
echo "  Scored question rows: $scored\n";
echo "\n  Sample questions:\n";
foreach (array_slice($questions, 0, 3) as $q) {
    echo sprintf("    Q%d (L%d, %s): '%s' → '%s' [%s, %ds]\n",
        $q['seq_no'], $q['level'], $q['question_type'],
        mb_substr($q['user_answer'] ?? '', 0, 50),
        $q['is_correct'] === null ? 'NULL' : ($q['is_correct'] ? 'correct' : 'wrong'),
        $q['is_correct'] === null ? '?' : ($q['is_correct'] ? '✓' : '✗'),
        (int)$q['time_seconds']
    );
}

if ($scored < 2) {
    echo "\n❌ Only $scored scored questions. Clinical analysis needs at least 2.\n";
    exit;
}

// 6. Now try to re-run the analysis with full error capture
echo "\n=== Re-running eval_clinical_analyse(\$sid=$sid, force=true) ===\n";

// Hook into error_log to capture messages
$captured_errors = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$captured_errors) {
    $captured_errors[] = "  PHP $errno: $errstr in " . basename($errfile) . ":$errline";
    return true;  // suppress default handler
});

// Try the call
$start = microtime(true);
try {
    $result = eval_clinical_analyse($sid, true);
    $elapsed = round(microtime(true) - $start, 1);
    echo "\n  Returned: " . ($result ? '✓ TRUE' : '❌ FALSE') . " (took {$elapsed}s)\n";
} catch (Throwable $e) {
    echo "\n  ❌ THREW EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  At: " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n";
}

restore_error_handler();

if (!empty($captured_errors)) {
    echo "\n--- PHP warnings/notices captured during run ---\n";
    foreach ($captured_errors as $e) echo "$e\n";
}

// 7. Re-check the row
echo "\n=== Post-run state check ===\n";
$row = db()->prepare("SELECT clinical_report_json, clinical_axes_summary FROM eval_sessions WHERE id = ?");
$row->execute([$sid]);
$r = $row->fetch();
$cr = $r['clinical_report_json'] ?? null;
echo "  clinical_report_json now: " . (empty($cr) ? '❌ STILL NULL' : '✓ POPULATED (' . strlen($cr) . ' bytes)') . "\n";
if (!empty($cr)) {
    $j = json_decode($cr, true);
    if (is_array($j)) {
        echo "  Parsed JSON keys: " . implode(', ', array_keys($j)) . "\n";
        if (isset($j['headline'])) echo "  Headline: " . $j['headline'] . "\n";
        if (isset($j['overall']['score'])) echo "  Overall score: " . $j['overall']['score'] . "\n";
        foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $k) {
            $s = $j[$k]['score'] ?? 'null';
            echo "  $k score: $s\n";
        }
    } else {
        echo "  ⚠ JSON did not parse: " . substr($cr, 0, 200) . "\n";
    }
}

echo "\n=== Done. Delete diag_clinical.php from server after reading. ===\n";
