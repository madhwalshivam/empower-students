<?php
/**
 * view_my_latest_eval.php?key=nci2026admin
 *
 * Shows your most recent completed eval + a clickable link to /evaluation-result.php
 * Also shows whether v3 listing has been generated, whether the comprehensive PDF
 * is in the queue, and where the PDF will appear.
 *
 * DELETE after use.
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403);
    echo "forbidden\n"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Eval status</title>";
echo "<style>body{font-family:system-ui;padding:24px;max-width:760px;margin:0 auto;background:#f9fafb}";
echo ".card{background:white;border-radius:8px;padding:16px;margin:8px 0;border:1px solid #e5e7eb}";
echo "a{color:#059669;font-weight:bold}h3{margin:6px 0}";
echo "code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}";
echo "</style></head><body>";

echo "<h1>Eval status for {$parent['name']} (#$parent_id)</h1>";

$st = db()->prepare("SELECT id, status, cost_paid, started_at, completed_at,
                            (v3_listing_json IS NOT NULL AND v3_listing_json != '') AS has_listing,
                            (summary_audio_hi IS NOT NULL AND summary_audio_hi != '') AS has_audio,
                            report_pdf_path,
                            pdf_queued_at, pdf_generated_at, refunded_at
                      FROM parent_reflect_sessions
                      WHERE parent_id = ?
                      ORDER BY id DESC LIMIT 10");
$st->execute([$parent_id]);
$rows = $st->fetchAll();

if (empty($rows)) {
    echo "<p>No sessions yet.</p>"; exit;
}

foreach ($rows as $r) {
    $sid = (int)$r['id'];
    $is_done = $r['status'] === 'completed';
    $color = $is_done ? '#059669' : '#94a3b8';

    echo "<div class='card'>";
    echo "<h3 style='color:$color'>Session #$sid — {$r['status']}</h3>";
    echo "<p>Started: {$r['started_at']}  ·  Cost: ₹{$r['cost_paid']}";
    if ($r['refunded_at']) echo "  ·  <em>refunded {$r['refunded_at']}</em>";
    echo "</p>";

    if ($is_done) {
        echo "<p>✓ v3 listing: " . ($r['has_listing'] ? 'YES' : 'NO (will generate on first view of result page)') . "</p>";
        echo "<p>✓ Leda voice: " . ($r['has_audio'] ? 'YES' : 'NO (will generate ~1hr after eval by cron)') . "</p>";
        echo "<p>✓ Comprehensive PDF: " . ($r['report_pdf_path'] ? "YES → <a href='" . htmlspecialchars($r['report_pdf_path']) . "' target='_blank'>view</a>" : "NO") . "</p>";
        if (!$r['report_pdf_path']) {
            echo "<p>PDF queued at: " . ($r['pdf_queued_at'] ?? '—') . "</p>";
            echo "<p>PDF generated at: " . ($r['pdf_generated_at'] ?? '—') . "</p>";
        }
        echo "<p><a href='/evaluation-result.php?session_id=$sid' target='_blank'>→ View v3 structured listing</a></p>";
    }
    echo "</div>";
}

// Show the report_queue rows
echo "<h2>PDF queue</h2>";
$qst = db()->prepare("SELECT * FROM report_queue
                       WHERE session_id IN (SELECT id FROM parent_reflect_sessions WHERE parent_id = ?)
                       ORDER BY id DESC");
$qst->execute([$parent_id]);
$qrows = $qst->fetchAll();
if (empty($qrows)) {
    echo "<p>No queued PDFs. Worker hasn't been told about any of your sessions yet.</p>";
} else {
    foreach ($qrows as $q) {
        echo "<div class='card'>";
        echo "<p>q#{$q['id']} for session #{$q['session_id']}</p>";
        echo "<p>queued_at: {$q['queued_at']} · due_at: <strong>{$q['due_at']}</strong></p>";
        echo "<p>started_at: " . ($q['started_at'] ?: '—') . " · completed_at: " . ($q['completed_at'] ?: '—') . "</p>";
        echo "<p>attempts: {$q['attempts']} · last_error: " . htmlspecialchars($q['last_error'] ?? '—') . "</p>";
        echo "</div>";
    }
}

echo "<p style='margin-top:24px'><strong>To force PDF generation now (don't wait the hour):</strong><br>";
echo "<code>https://empowerstudents.in/generate-report-worker.php?key=nci2026admin</code> — also moves due_at to now first</p>";

echo "</body></html>";
