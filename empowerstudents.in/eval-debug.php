<?php
/**
 * eval-debug.php — diagnostic page for the eval pipeline.
 *
 * Visit https://empowerstudents.in/eval-debug.php while logged in.
 * Runs each step of an eval turn and times it precisely so we can see
 * exactly where the latency is.
 *
 * Admin/parent only — does NOT charge wallet.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/eval_engine.php';

require_parent();
$parent  = current_parent();
$parent_id = (int)$parent['id'];

// Find a child to use (first one)
$cs = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
$cs->execute([$parent_id]);
$child = $cs->fetch();

header('Content-Type: text/plain; charset=utf-8');

if (!$child) {
    echo "❌ No child found for parent $parent_id\n";
    exit;
}

echo "▶ Starting timing diagnostics\n";
echo "   Parent: " . $parent['whatsapp'] . " (id=$parent_id)\n";
echo "   Child:  " . $child['name'] . " (id=" . $child['id'] . ", mt=" . $child['mother_tongue'] . ")\n\n";

// Step 1: Create or reuse a test session
$st = db()->prepare("SELECT id FROM eval_sessions WHERE parent_id = ? AND child_id = ? AND module = 'mod_speech_basic' AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
$st->execute([$parent_id, (int)$child['id']]);
$existing = (int)$st->fetchColumn();
if ($existing) {
    $sid = $existing;
    echo "🔁 Reusing existing in-progress session $sid\n\n";
} else {
    $sid = eval_start_session($parent_id, (int)$child['id'], 'mod_speech_basic', false, 0);
    echo "✨ Created fresh session $sid\n\n";
}

// Step 2: Time the question generation (eval_next_question — voice mode)
echo "⏱  TEST 1: eval_next_question() — fresh question generation via Haiku\n";
$t0 = microtime(true);
$q = eval_next_question($sid, true);
$dt = microtime(true) - $t0;
printf("   → %.2fs\n", $dt);
if ($q) {
    echo "   ✓ Generated: type={$q['type']} L{$q['level']}\n";
    echo "   Prompt: " . mb_substr($q['prompt'], 0, 80) . "\n";
} else {
    echo "   ✗ FAILED — eval_next_question returned null\n";
}
echo "\n";

if (!$q) { echo "Aborting — Q1 generation failed.\n"; exit; }

// Step 3: Time the COMBINED call
echo "⏱  TEST 2: eval_score_and_next() — combined score + next via Haiku (voice mode)\n";
$ac = [
    'transcript_confidence' => 0.85,
    'duration_sec'          => 2.5,
    'wpm'                   => 80,
    'volume_variance'       => 0.06,
    'silence_ratio'         => 0.10,
    'pause_count'           => 0,
    'time_to_first_speech_sec' => 0.5,
];
$t0 = microtime(true);
$r = eval_score_and_next($sid, (int)$q['question_id'], 'roti', 4, $ac);
$dt = microtime(true) - $t0;
printf("   → %.2fs\n", $dt);
if ($r['question']) {
    echo "   ✓ Got next Q: type={$r['question']['type']} L{$r['question']['level']}\n";
    echo "   Prompt: " . mb_substr($r['question']['prompt'], 0, 80) . "\n";
} else {
    echo "   ✗ should_stop=" . ($r['scoring']['should_stop'] ? 'YES' : 'no') . " reason=" . $r['scoring']['reason'] . "\n";
}
echo "\n";

// Step 4: Direct Haiku ping (just the API call, no parsing)
echo "⏱  TEST 3: Direct Haiku API ping (no app logic, raw curl)\n";
$payload = [
    'model'       => 'claude-haiku-4-5-20251001',
    'max_tokens'  => 50,
    'temperature' => 0,
    'system'      => 'You output exactly the word OK and nothing else.',
    'messages'    => [['role' => 'user', 'content' => 'Hi']],
];
$t0 = microtime(true);
$ch = curl_init(ANTHROPIC_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'content-type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: ' . ANTHROPIC_VERSION,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
$starttransfer_time = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
curl_close($ch);
$dt = microtime(true) - $t0;
printf("   → wall:    %.2fs\n", $dt);
printf("   → curl total:    %.2fs\n", $total_time);
printf("   → curl connect:  %.2fs\n", $connect_time);
printf("   → curl ttfb:     %.2fs (time to first byte from API)\n", $starttransfer_time);
echo "   → HTTP code: $code\n";
if ($err) echo "   ✗ ERROR: $err\n";
echo "   Response: " . substr((string)$resp, 0, 200) . "\n\n";

// Step 5: Server info
echo "▶ Server info\n";
echo "   PHP: " . PHP_VERSION . "\n";
echo "   Time: " . date('c') . "\n";
echo "   Memory: " . round(memory_get_usage()/1048576, 1) . "MB used / " . ini_get('memory_limit') . " limit\n";
echo "   max_execution_time: " . ini_get('max_execution_time') . "s\n";
echo "   default_socket_timeout: " . ini_get('default_socket_timeout') . "s\n";
echo "   ANTHROPIC_API_URL: " . ANTHROPIC_API_URL . "\n";
echo "   ANTHROPIC_MODEL: " . ANTHROPIC_MODEL . "\n";

// Step 6: Clean up — abandon this test session so it doesn't pollute future evals
db()->prepare("UPDATE eval_sessions SET status = 'abandoned' WHERE id = ?")->execute([$sid]);
echo "\n🧹 Test session $sid marked abandoned (won't be resumed by real eval)\n";

echo "\n▶ Done. The most useful number is TEST 3's curl ttfb — that's how long the Anthropic API itself takes from this server.\n";
echo "   If TEST 3 ttfb > 5s, the API is slow today (Anthropic load).\n";
echo "   If TEST 3 ttfb < 2s but TEST 1/2 are slow, the slowness is in our code (DB? prompt building?).\n";
