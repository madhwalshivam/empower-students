<?php
require_once __DIR__ . '/config.php';

/**
 * Send a Claude messages request.
 *
 * @param string $system   system prompt
 * @param array  $messages [['role'=>'user','content'=>'...'], ...]
 * @param int    $max_tokens
 * @param float  $temperature
 * @return string  text content of first text-block in response, or '' on error
 */
function claude_chat($system, $messages, $max_tokens = 1024, $temperature = 0.4) {
    $payload = [
        'model'       => ANTHROPIC_MODEL,
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'system'      => $system,
        'messages'    => $messages,
    ];

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
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
    curl_close($ch);

    if ($err || $code >= 400) {
        error_log('[claude] HTTP ' . $code . ' ' . $err . ' :: ' . substr((string)$resp, 0, 400));
        return '';
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['content'])) return '';
    foreach ($data['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text') {
            return (string)$block['text'];
        }
    }
    return '';
}

/**
 * Ask Claude to return JSON only. We strip code fences if present.
 *
 * @return array|null
 */
function claude_json($system, $user_prompt, $max_tokens = 1024, $temperature = 0.2) {
    $sys = $system . "\n\nReturn ONLY valid minified JSON. No prose, no code fences.";
    $txt = claude_chat($sys, [['role' => 'user', 'content' => $user_prompt]], $max_tokens, $temperature);
    if ($txt === '') return null;

    // Strip code fences just in case
    $txt = trim($txt);
    if (strpos($txt, '```') === 0) {
        $txt = preg_replace('/^```(?:json)?/i', '', $txt);
        $txt = preg_replace('/```\s*$/', '', $txt);
        $txt = trim($txt);
    }
    // Try direct decode
    $j = json_decode($txt, true);
    if (is_array($j)) return $j;

    // Last-ditch: extract first {...} or [...]
    if (preg_match('/(\{.*\}|\[.*\])/s', $txt, $m)) {
        $j = json_decode($m[1], true);
        if (is_array($j)) return $j;
    }
    error_log('[claude_json] failed parse: ' . substr($txt, 0, 400));
    return null;
}
