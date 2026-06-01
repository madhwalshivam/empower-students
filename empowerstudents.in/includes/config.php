<?php
/**
 * Empower Students - Central Configuration
 * PHP 7.4 + SQLite
 *
 * Credentials below are pre-filled from your existing Neuro Care India /
 * mydoctor.ltd setup. Cashfree is the only thing you still need to fill in
 * (those credentials live on Railway in your other project — copy them from
 * Cashfree dashboard into the CASHFREE_* values below).
 */

// ---------- Site identity ----------
define('SITE_NAME',        'Empower Students');
define('SITE_TAGLINE',     'Holistic child assessment, nurture, and care');
define('SITE_DOMAIN',      'empowerstudents.in');
define('SITE_SUPPORT_PH',  '+91-9311696923');
define('SITE_SUPPORT_WA',  '+91-9311883132');
define('SITE_SUPPORT_EMAIL','care@empowerstudents.in');

// ---------- Anthropic Claude ----------
// Same key used on neurocareindia.in / mydoctor.ltd
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
define('ANTHROPIC_MODEL',   'claude-sonnet-4-5'); // change to claude-opus-4-7 for highest-quality reports
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');
define('ANTHROPIC_VERSION', '2023-06-01');

// ---------- OTP gateway ----------
// 'demo' = OTP echoed on screen for testing
// 'twilio_wa' = real WhatsApp delivery via Twilio Content template (production)
// 'msg91' / 'wati' = also supported, fill creds further down if you switch
define('OTP_MODE', 'twilio_wa');

// MSG91 / WATI fallbacks (unused while OTP_MODE = twilio_wa)
define('MSG91_AUTHKEY',  '');
define('MSG91_TEMPLATE', '');
define('MSG91_SENDER',   'EMPSTU');
define('WATI_API_ENDPOINT', '');
define('WATI_API_TOKEN',    '');
define('WATI_TEMPLATE',     'otp_login');

// ---------- Twilio WhatsApp (production OTP) ----------
// Same Twilio account as Neuro Care India.
// IMPORTANT: TWILIO_CONTENT_SID below is your existing NCI template — its
// message body says "Your Neuro Care India OTP is {{1}}...". OTPs will
// deliver fine, but the brand text in WhatsApp will say "Neuro Care India"
// instead of "Empower Students". To brand it correctly, create a new
// approved template in Twilio Console with body:
//   "Your Empower Students OTP is {{1}}. Verify at {{2}}. Do not share."
// and replace TWILIO_CONTENT_SID with the new HX… value.
define('TWILIO_SID',         getenv('TWILIO_SID')         ?: '');
define('TWILIO_TOKEN',       getenv('TWILIO_AUTH_TOKEN')  ?: '');
define('TWILIO_WA_FROM',     getenv('TWILIO_WHATSAPP_FROM') ?: 'whatsapp:+15558734404');
define('TWILIO_CONTENT_SID', getenv('TWILIO_CONTENT_SID') ?: '');

// ---------- Cashfree Payments v3 ----------
// empowerstudents.in is already whitelisted in your Cashfree dashboard.
// Get APP_ID + SECRET_KEY from: https://merchant.cashfree.com/ → Developers → API Keys
// (Then in Cashfree dashboard → Developers → Webhooks, register
//  https://empowerstudents.in/payment_webhook.php )
define('CASHFREE_ENV',         getenv('CASHFREE_ENV')        ?: 'production');   // 'sandbox' | 'production'
define('CASHFREE_APP_ID',      getenv('CASHFREE_APP_ID')     ?: '');             // ← PASTE
define('CASHFREE_SECRET_KEY',  getenv('CASHFREE_SECRET_KEY') ?: '');             // ← PASTE
define('CASHFREE_API_VERSION', '2023-08-01');

// ---------- Credit / wallet system ----------
// 1 credit = ₹1. Parents get 100 free credits on registration.
define('SIGNUP_FREE_CREDITS', 100);
$SERVICE_PRICES = [
    // module_key                  credits  audience    label
    'health'              => [3,  'parent', 'Health screening'],
    'mind_power'          => [3,  'parent', 'Mind power screening'],
    'emotions'            => [3,  'parent', 'Emotions screening'],
    'behavior'            => [3,  'parent', 'Behaviour screening'],
    'special_talent'      => [3,  'parent', 'Special talent screener'],
    'parent_index'        => [3,  'parent', 'Parent self-rating'],
    'general_awareness'   => [5,  'parent', 'General awareness quiz'],
    'math'                => [5,  'parent', 'Maths adaptive test'],
    'language'            => [5,  'parent', 'Language &amp; reading'],
    'speech'              => [10, 'parent', 'Speech (audio + AI)'],
    'spontaneous'         => [10, 'parent', 'Spontaneous speech (audio + AI)'],
    'diet'                => [20, 'parent', 'Diet plan (AI)'],
    'pulse_check'         => [2,  'parent', 'Pulse / breath check'],
    'comprehensive_report'=> [50, 'parent', 'Comprehensive AI report (1 / child)'],
];
define('COMPREHENSIVE_REPORT_MAX_PER_CHILD', 1);

// ---------- Storage paths ----------
define('DB_PATH',         __DIR__ . '/../data/empower.db');
define('UPLOAD_DIR',      __DIR__ . '/../uploads');
define('UPLOAD_URL',      '/uploads');

// ---------- Session / security ----------
define('SESSION_NAME', 'EMPSTU_SESS');
define('OTP_TTL_SECS', 5 * 60);     // OTP validity
define('OTP_MAX_TRY',  5);          // attempts per OTP
define('OTP_RESEND_GAP', 30);       // seconds between resends

// ---------- Default age-bands ----------
$AGE_BANDS = [
    'infant'    => [0, 2],   // 0-2 years -- subtle screening only
    'toddler'   => [2, 5],
    'child'     => [5, 10],
    'preteen'   => [10, 13],
    'teen'      => [13, 18],
];

// ---------- Bootstrapping ----------
session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');

// Lightweight escaping helper used everywhere
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// CSRF
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_check($t) {
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
