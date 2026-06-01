<?php
/**
 * generate-report-worker.php
 *
 * Processes the report_queue — finds sessions whose PDFs are due,
 * generates them, marks them complete.
 *
 * Trigger:
 *   • Cron: every 5 min — `* /5 * * * * php /path/to/generate-report-worker.php`
 *   • OR HTTP webhook — visit /generate-report-worker.php?key=SECRET
 *
 * Idempotent — safe to call any time. Skips already-completed.
 * Max 3 sessions per run to avoid Claude rate limits and 30s timeouts.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/comprehensive_report.php';

@set_time_limit(120);

// Light auth — for HTTP triggers only
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $worker_key = defined('REPORT_WORKER_KEY') ? REPORT_WORKER_KEY : 'nci2026admin';
    if (($_GET['key'] ?? '') !== $worker_key) {
        http_response_code(403);
        echo "forbidden\n"; exit;
    }
}

$max_per_run = 3;

$st = db()->prepare("SELECT * FROM report_queue
                      WHERE completed_at IS NULL
                        AND failed_at IS NULL
                        AND due_at <= datetime('now')
                        AND attempts < 3
                      ORDER BY due_at ASC
                      LIMIT ?");
$st->bindValue(1, $max_per_run, PDO::PARAM_INT);
$st->execute();
$queued = $st->fetchAll();

echo "Worker run at " . date('Y-m-d H:i:s') . " IST\n";
echo "Queue items to process: " . count($queued) . "\n\n";

foreach ($queued as $q) {
    $sid = (int)$q['session_id'];
    echo "→ session $sid (attempt " . ($q['attempts'] + 1) . ")\n";

    db()->prepare("UPDATE report_queue SET started_at = CURRENT_TIMESTAMP,
                                          attempts = attempts + 1 WHERE id = ?")
       ->execute([(int)$q['id']]);

    try {
        $r = comprehensive_report_generate($sid);
        if ($r['ok']) {
            db()->prepare("UPDATE report_queue SET completed_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = ?")
               ->execute([(int)$q['id']]);
            echo "  ✓ generated: " . ($r['pdf_path'] ?? '?') . "\n";
        } else {
            db()->prepare("UPDATE report_queue SET last_error = ? WHERE id = ?")
               ->execute([(string)($r['error'] ?? '?'), (int)$q['id']]);
            echo "  ❌ " . ($r['error'] ?? '?') . "\n";
        }
    } catch (Throwable $e) {
        error_log('[report-worker] ' . $e->getMessage());
        db()->prepare("UPDATE report_queue SET last_error = ? WHERE id = ?")
           ->execute([$e->getMessage(), (int)$q['id']]);
        echo "  ❌ exception: " . $e->getMessage() . "\n";
    }
}

// Mark items with 3+ failed attempts as failed
db()->exec("UPDATE report_queue SET failed_at = CURRENT_TIMESTAMP
            WHERE completed_at IS NULL AND failed_at IS NULL AND attempts >= 3");

echo "\nDone.\n";
