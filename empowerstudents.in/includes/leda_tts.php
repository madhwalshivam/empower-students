<?php
/**
 * includes/leda_tts.php — Google Cloud TTS (Chirp3-HD Leda voice)
 *
 * Synthesises Hindi or English text into MP3 using Google Cloud TTS
 * Chirp3-HD Leda voice (calm female, the same voice we use across the
 * mydoctor.ltd and dilkibaat stack). Output is MP3, playable directly
 * by <audio> tag. Aggressively cached on disk by sha256 of (text, voice, speed).
 *
 * Public API:
 *
 *   leda_tts_synthesize(string $text, string $lang = 'hi', float $speed = 0.85): ?string
 *     Returns a relative URL to the cached MP3 (e.g. /uploads/leda/abc123.mp3),
 *     or null on error.
 *
 *   leda_tts_is_configured(): bool
 *     True if the service account JSON is readable.
 *
 * Voice: hi-IN-Chirp3-HD-Leda / en-IN-Chirp3-HD-Leda
 * Speed: locked at 0.85 (slightly slower than default — more soothing)
 * Cache: /uploads/leda/{sha256}.mp3 (web-accessible; cached per text+voice+speed)
 *
 * Service account: define LEDA_SA_PATH or use auto-detection paths.
 */

require_once __DIR__ . '/db.php';

// ───────────────────────────────────────────────────────────
// Config — define before require, or it auto-detects
// ───────────────────────────────────────────────────────────
if (!defined('LEDA_SA_PATH')) {
    // Try common locations on this hosting
    $candidates = [
        '/home/pbsxsp7mle8b/private/nci-service-account.json',
        '/home/pbsxsp7mle8b/private/google-service-account.json',
        __DIR__ . '/../private/google-service-account.json',
    ];
    $found = null;
    foreach ($candidates as $p) {
        if (is_readable($p)) { $found = $p; break; }
    }
    define('LEDA_SA_PATH', $found ?: '');
}
if (!defined('LEDA_VOICE_HI')) define('LEDA_VOICE_HI', 'hi-IN-Chirp3-HD-Leda');
if (!defined('LEDA_VOICE_EN')) define('LEDA_VOICE_EN', 'en-IN-Chirp3-HD-Leda');
if (!defined('LEDA_SPEED'))    define('LEDA_SPEED',    0.85);
if (!defined('LEDA_CACHE_DIR_FS')) define('LEDA_CACHE_DIR_FS', __DIR__ . '/../leda');
if (!defined('LEDA_CACHE_DIR_WEB')) define('LEDA_CACHE_DIR_WEB', '/leda');
if (!defined('LEDA_MAX_CHARS')) define('LEDA_MAX_CHARS', 4500);
if (!defined('LEDA_TOKEN_CACHE_FS')) define('LEDA_TOKEN_CACHE_FS', __DIR__ . '/../data/leda_token.json');


function leda_tts_is_configured(): bool {
    return LEDA_SA_PATH !== '' && is_readable(LEDA_SA_PATH);
}


// ───────────────────────────────────────────────────────────
// JWT signing → OAuth2 access token (cached ~55 min on disk)
// ───────────────────────────────────────────────────────────
function _leda_b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function _leda_get_access_token(): ?string {
    // Disk cache (55 min)
    if (file_exists(LEDA_TOKEN_CACHE_FS)) {
        $cache = json_decode((string)@file_get_contents(LEDA_TOKEN_CACHE_FS), true);
        if (is_array($cache) && !empty($cache['token']) && (int)($cache['expires_at'] ?? 0) > time() + 60) {
            return $cache['token'];
        }
    }

    if (!leda_tts_is_configured()) {
        error_log('[leda_tts] service account not configured');
        return null;
    }

    $sa = json_decode((string)@file_get_contents(LEDA_SA_PATH), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        error_log('[leda_tts] service account JSON malformed');
        return null;
    }

    $now = time();
    $hdr = ['alg' => 'RS256', 'typ' => 'JWT'];
    $pld = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $segs = [
        _leda_b64url(json_encode($hdr, JSON_UNESCAPED_SLASHES)),
        _leda_b64url(json_encode($pld, JSON_UNESCAPED_SLASHES)),
    ];
    $sign_input = implode('.', $segs);

    $sig = '';
    if (!openssl_sign($sign_input, $sig, $sa['private_key'], 'SHA256')) {
        error_log('[leda_tts] JWT signing failed');
        return null;
    }
    $segs[] = _leda_b64url($sig);
    $jwt = implode('.', $segs);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) {
        error_log("[leda_tts] OAuth2 exchange failed code=$code body=" . substr((string)$resp, 0, 300));
        return null;
    }
    $token_data = json_decode((string)$resp, true);
    if (!is_array($token_data) || empty($token_data['access_token'])) {
        error_log('[leda_tts] OAuth2 response malformed');
        return null;
    }

    @mkdir(dirname(LEDA_TOKEN_CACHE_FS), 0775, true);
    @file_put_contents(LEDA_TOKEN_CACHE_FS, json_encode([
        'token'      => $token_data['access_token'],
        'expires_at' => $now + (int)($token_data['expires_in'] ?? 3600) - 60,
    ]));
    return $token_data['access_token'];
}


// ───────────────────────────────────────────────────────────
// Public: synthesize text → cached MP3 URL
// ───────────────────────────────────────────────────────────
function leda_tts_synthesize(string $text, string $lang = 'hi', float $speed = LEDA_SPEED): ?string {
    $text = trim($text);
    if ($text === '') return null;
    if (mb_strlen($text) > LEDA_MAX_CHARS) {
        $text = mb_substr($text, 0, LEDA_MAX_CHARS);
    }

    $lang_lower = strtolower(substr($lang, 0, 2));
    $voice = $lang_lower === 'en' ? LEDA_VOICE_EN : LEDA_VOICE_HI;
    $lang_code = $lang_lower === 'en' ? 'en-IN' : 'hi-IN';

    // Cache key — same text+voice+speed → same file
    $cache_key = hash('sha256', $voice . '|' . $speed . '|' . $text);
    $cache_file_fs  = LEDA_CACHE_DIR_FS  . '/' . $cache_key . '.mp3';
    $cache_file_web = LEDA_CACHE_DIR_WEB . '/' . $cache_key . '.mp3';

    if (file_exists($cache_file_fs) && filesize($cache_file_fs) > 100) {
        return $cache_file_web;
    }

    @mkdir(LEDA_CACHE_DIR_FS, 0775, true);

    $token = _leda_get_access_token();
    if (!$token) return null;

    $body = [
        'input'  => ['text' => $text],
        'voice'  => ['languageCode' => $lang_code, 'name' => $voice],
        'audioConfig' => [
            'audioEncoding' => 'MP3',
            'speakingRate'  => (float)$speed,
        ],
    ];

    $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("[leda_tts] synthesis failed code=$code voice=$voice body=" . substr((string)$resp, 0, 300));
        return null;
    }

    $j = json_decode((string)$resp, true);
    if (!is_array($j) || empty($j['audioContent'])) {
        error_log('[leda_tts] missing audioContent in response');
        return null;
    }

    $mp3 = base64_decode($j['audioContent']);
    if (!$mp3 || strlen($mp3) < 100) {
        error_log('[leda_tts] decoded MP3 too small');
        return null;
    }
    if (file_put_contents($cache_file_fs, $mp3) === false) {
        error_log('[leda_tts] could not write cache file: ' . $cache_file_fs);
        return null;
    }
    return $cache_file_web;
}


// ───────────────────────────────────────────────────────────
// Convenience: synthesise BOTH a Hindi and English version of a paragraph
// Returns ['hi' => '/url.mp3', 'en' => '/url.mp3', 'hi_text' => '...', 'en_text' => '...']
// ───────────────────────────────────────────────────────────
function leda_tts_bilingual(string $hi_text, string $en_text): array {
    $hi_url = leda_tts_synthesize($hi_text, 'hi');
    $en_url = leda_tts_synthesize($en_text, 'en');
    return [
        'hi'      => $hi_url,
        'en'      => $en_url,
        'hi_text' => $hi_text,
        'en_text' => $en_text,
    ];
}
