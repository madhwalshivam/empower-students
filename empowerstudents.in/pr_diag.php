<?php
/**
 * pr_diag.php?key=nci2026admin&sid=15
 *
 * Quick diagnostic for a parent_reflect session. Shows:
 *   - Session row from parent_reflect_sessions
 *   - All turn rows from parent_reflect_turns (incl. transcripts)
 *   - All sessions for the same parent (to see if there's another one)
 *
 * Read-only. Safe to run.
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();

$sid = (int)($_GET['sid'] ?? 15);

echo "=== Diagnostic for parent_reflect session $sid ===\n\n";

// 1. Session row
echo "--- SESSION ROW ---\n";
$st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ?");
$st->execute([$sid]);
$s = $st->fetch();
if (!$s) {
    echo "❌ NO SESSION with id=$sid\n\n";
} else {
    foreach ($s as $k => $v) {
        if (is_int($k)) continue;
        if (strlen((string)$v) > 200) $v = substr((string)$v, 0, 200) . '… (truncated)';
        echo str_pad($k, 22) . " = $v\n";
    }
    echo "\n";

    // 2. All turns for this session
    echo "--- TURNS for session $sid (chronological) ---\n";
    $tn = db()->prepare("SELECT id, turn_no, phase, question, transcript, question_intent, created_at,
                                length(transcript) as tlen
                         FROM parent_reflect_turns WHERE session_id = ? ORDER BY turn_no ASC");
    $tn->execute([$sid]);
    $turns = $tn->fetchAll();

    if (!$turns) {
        echo "❌ NO TURNS in DB for this session\n\n";
    } else {
        echo "Total turns: " . count($turns) . "\n\n";
        foreach ($turns as $t) {
            echo "  Turn " . $t['turn_no'] . " · phase " . $t['phase']
               . " · transcript: " . ($t['tlen'] > 0 ? '"' . substr($t['transcript'], 0, 60) . ($t['tlen'] > 60 ? '…"' : '"') : '(EMPTY — open turn)')
               . " · " . $t['created_at']
               . "\n";
            echo "    Q: " . substr($t['question'], 0, 80) . (strlen($t['question']) > 80 ? '…' : '') . "\n";
        }
        echo "\n";
    }

    // 3. Other sessions for the same parent
    $pid = (int)($s['parent_id'] ?? 0);
    if ($pid) {
        echo "--- ALL SESSIONS for parent_id=$pid ---\n";
        $os = db()->prepare("SELECT id, status, turn_count, current_phase, started_at, completed_at
                              FROM parent_reflect_sessions WHERE parent_id = ? ORDER BY id DESC");
        $os->execute([$pid]);
        $sessions = $os->fetchAll();
        foreach ($sessions as $row) {
            $marker = ($row['id'] == $sid) ? ' ←current' : '';
            echo "  session $row[id]: status=$row[status] turns=$row[turn_count] phase=$row[current_phase] "
               . "started=$row[started_at] completed=" . ($row['completed_at'] ?: '-') . $marker . "\n";
        }
        echo "\n";

        // Parent details
        echo "--- PARENT row ---\n";
        $pp = db()->prepare("SELECT id, whatsapp, name, credits FROM parents WHERE id = ?");
        $pp->execute([$pid]);
        $par = $pp->fetch();
        if ($par) {
            foreach ($par as $k => $v) {
                if (is_int($k)) continue;
                echo "  $k = $v\n";
            }
        }
    }
}

echo "\nDone. Append ?sid=N to inspect a different session.\n";
echo "DELETE this file after use.\n";
