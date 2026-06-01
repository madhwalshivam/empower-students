<?php
/**
 * intake-tts.php
 *
 * Returns a cached Leda voice MP3 URL for an intake question.
 *
 * GET params:
 *   text  — the text to synthesize (URL-encoded)
 *   lang  — 'hi' or 'en'
 *
 * Returns JSON:
 *   { ok: true, url: '/uploads/leda/abc123.mp3' }
 *   or { ok: false, error: '...' }
 *
 * Requires the user to be logged in as a parent (so we don't expose
 * Leda synthesis as a free public TTS service).
 * Caches aggressively by sha256 of text+voice+speed — same question
 * across thousands of parents synthesizes once.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/leda_tts.php';

// Auth check — parent must be logged in
if (!function_exists('current_parent') || !current_parent()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$text = trim((string)($_GET['text'] ?? $_POST['text'] ?? ''));
$lang = strtolower(substr((string)($_GET['lang'] ?? $_POST['lang'] ?? 'hi'), 0, 2));

if ($text === '') {
    echo json_encode(['ok' => false, 'error' => 'No text']);
    exit;
}
if (mb_strlen($text) > 2000) {
    echo json_encode(['ok' => false, 'error' => 'Text too long']);
    exit;
}

if (!leda_tts_is_configured()) {
    echo json_encode(['ok' => false, 'error' => 'TTS not configured', 'fallback_to_browser' => true]);
    exit;
}

$url = leda_tts_synthesize($text, $lang);

if (!$url) {
    echo json_encode(['ok' => false, 'error' => 'TTS synthesis failed', 'fallback_to_browser' => true]);
    exit;
}

echo json_encode(['ok' => true, 'url' => $url]);
