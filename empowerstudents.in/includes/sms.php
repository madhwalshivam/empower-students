<?php
require_once __DIR__ . '/config.php';

/**
 * Send the OTP code via the configured channel.
 * In 'demo' mode this only logs and returns true; the OTP is shown in the UI for testing.
 *
 * @return array ['ok'=>bool, 'message'=>string]
 */
function send_otp_message($e164_phone, $code) {
    $mode = OTP_MODE;
    $msg  = "Your " . SITE_NAME . " login OTP is " . $code .
            ". Valid for 5 minutes. Do not share this code with anyone.";

    if ($mode === 'demo') {
        error_log('[OTP demo] ' . $e164_phone . ' -> ' . $code);
        return ['ok' => true, 'message' => 'demo mode'];
    }

    if ($mode === 'msg91') {
        $url = 'https://control.msg91.com/api/v5/otp?template_id=' . urlencode(MSG91_TEMPLATE)
             . '&mobile=' . urlencode(ltrim($e164_phone, '+'))
             . '&authkey=' . urlencode(MSG91_AUTHKEY)
             . '&otp=' . urlencode($code);
        return _http_get_json($url);
    }

    if ($mode === 'wati') {
        $url = rtrim(WATI_API_ENDPOINT, '/')
             . '/api/v1/sendTemplateMessage?whatsappNumber=' . urlencode(ltrim($e164_phone, '+'));
        $body = [
            'template_name' => WATI_TEMPLATE,
            'broadcast_name'=> 'login_otp',
            'parameters'    => [['name' => '1', 'value' => $code]],
        ];
        return _http_post_json($url, $body, [
            'Authorization: Bearer ' . WATI_API_TOKEN,
            'Content-Type: application/json',
        ]);
    }

    if ($mode === 'twilio_wa') {
        // Twilio WhatsApp Business API requires a pre-approved ContentSid.
        // Plain `Body` messages are silently dropped by Meta unless the user
        // has messaged you in the past 24 hrs. So always use ContentSid.
        // Template body should be:
        //   "Your Empower Students OTP is {{1}}. Verify at {{2}}. Do not share."
        if (!defined('TWILIO_CONTENT_SID') || !TWILIO_CONTENT_SID) {
            error_log('[OTP twilio_wa] TWILIO_CONTENT_SID is empty — cannot deliver. Falling back to demo log.');
            error_log('[OTP twilio_wa demo-fallback] ' . $e164_phone . ' -> ' . $code);
            return ['ok' => false, 'message' => 'TWILIO_CONTENT_SID not configured'];
        }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
        $verify_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                    . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN) . '/login.php';
        $form = http_build_query([
            'From'             => TWILIO_WA_FROM,
            'To'               => 'whatsapp:' . $e164_phone,
            'ContentSid'       => TWILIO_CONTENT_SID,
            'ContentVariables' => json_encode(['1' => $code, '2' => $verify_url], JSON_UNESCAPED_SLASHES),
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_TOKEN,
            CURLOPT_POSTFIELDS     => $form,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $code_http < 400, 'message' => substr((string)$resp, 0, 300)];
    }

    return ['ok' => false, 'message' => 'unknown OTP_MODE'];
}

function _http_get_json($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $code < 400, 'message' => substr((string)$resp, 0, 300)];
}

function _http_post_json($url, $body, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $code < 400, 'message' => substr((string)$resp, 0, 300)];
}

function generate_otp_code() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
