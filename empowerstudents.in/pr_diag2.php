<?php
/**
 * pr_diag2.php?key=nci2026admin
 *
 * Improved diagnostic:
 *   - Lists ALL sessions for parent_id=1 (Isha)
 *   - Shows turn count per session
 *   - For each session with turns, lists each turn's Q + A (truncated)
 *
 * Read-only. Safe.
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

$pid = (int)($_GET['pid'] ?? 1);

echo "=== All parent_reflect sessions for parent_id=$pid ===\n\n";

// Parent info
$pp = db()->prepare("SELECT id, whatsapp, name FROM parents WHERE id = ?");
$pp->execute([$pid]);
$par = $pp->fetch();
if (!$par) { echo "❌ No parent with id=$pid\n"; exit; }
echo "Parent: " . $par['name'] . " · " . $par['whatsapp'] . "\n\n";

// All sessions for this parent
$st = db()->prepare("SELECT id, status, turn_count, current_phase, started_at, completed_at, cost_paid
                     FROM parent_reflect_sessions
                     WHERE parent_id = ?
                     ORDER BY id ASC");
$st->execute([$pid]);
$sessions = $st->fetchAll();
if (!$sessions) { echo "❌ No sessions found\n"; exit; }

echo "Total sessions: " . count($sessions) . "\n\n";

foreach ($sessions as $s) {
    $sid = (int)$s['id'];
    echo "──────────────────────────────────────────────────────\n";
    echo "Session $sid · status=$s[status] · turn_count(stored)=$s[turn_count] · phase=$s[current_phase]\n";
    echo "  started: $s[started_at]  |  completed: " . ($s['completed_at'] ?: '-') . "  |  paid: ₹$s[cost_paid]\n";

    // Actual turns in DB
    $tn = db()->prepare("SELECT id, turn_no, phase, question, transcript, answered_at, length(transcript) as tlen
                         FROM parent_reflect_turns
                         WHERE session_id = ?
                         ORDER BY turn_no ASC");
    $tn->execute([$sid]);
    $turns = $tn->fetchAll();
    echo "  Actual turns in DB: " . count($turns) . "\n";

    if ($turns) {
        foreach ($turns as $t) {
            $a = ($t['tlen'] > 0)
                ? '"' . substr($t['transcript'], 0, 70) . ($t['tlen'] > 70 ? '…"' : '"')
                : '(OPEN, no answer)';
            echo "    Turn $t[turn_no] · phase $t[phase]\n";
            echo "      Q: " . substr($t['question'], 0, 90) . (strlen($t['question']) > 90 ? '…' : '') . "\n";
            echo "      A: $a\n";
        }
    }
    echo "\n";
}

echo "Done. Tip: an 'abandoned' session was paused/discarded; 'in_progress' is resumable; 'completed' is finished.\n";
echo "DELETE this file after use.\n";
