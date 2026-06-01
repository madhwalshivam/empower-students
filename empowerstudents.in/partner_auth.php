<?php
/**
 * includes/partner_auth.php
 *
 * Partner authentication. Reuses the existing `partners` table (extended with
 * password_hash + magic-link token columns by isaa_schema.php).
 *
 * Lifecycle:
 *   1. Admin onboards a partner via existing admin/partners.php flow (creates row).
 *   2. Admin clicks "Send password setup link" → generates a token, valid 24h.
 *      Token is sent via WhatsApp click-to-chat; partner taps it, lands on
 *      /partner-set-password.php?token=XXX, sets their password.
 *   3. Partner logs in via /partner-login.php (whatsapp + password).
 *   4. Session var $_SESSION['partner_id'] is set; current_partner() retrieves the row.
 */

require_once __DIR__ . '/db.php';

const PARTNER_SESSION_KEY  = 'partner_id';
const PARTNER_TOKEN_TTL_HRS = 24;

/** Returns the currently logged-in partner's row, or null if none. */
function current_partner(): ?array {
    if (empty($_SESSION[PARTNER_SESSION_KEY])) return null;
    $st = db()->prepare("SELECT * FROM partners WHERE id = ?");
    $st->execute([(int)$_SESSION[PARTNER_SESSION_KEY]]);
    $row = $st->fetch();
    if (!$row) {
        unset($_SESSION[PARTNER_SESSION_KEY]);
        return null;
    }
    if ($row['status'] !== 'active') {
        // Partner is paused/terminated — invalidate session
        unset($_SESSION[PARTNER_SESSION_KEY]);
        return null;
    }
    return $row;
}

/** Hard-redirect to /partner-login.php if no active partner is logged in. */
function require_partner(): array {
    $p = current_partner();
    if (!$p) {
        $next = $_SERVER['REQUEST_URI'] ?? '/partner-dashboard.php';
        header('Location: /partner-login.php?next=' . urlencode($next));
        exit;
    }
    return $p;
}

/**
 * Try to log in a partner by WhatsApp + password.
 * Returns ['ok' => bool, 'message' => string, 'partner' => ?array].
 */
function partner_login(string $whatsapp, string $password): array {
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    if (strlen($whatsapp) < 10) {
        return ['ok' => false, 'message' => 'Please enter a valid WhatsApp number.', 'partner' => null];
    }

    $st = db()->prepare("SELECT * FROM partners WHERE whatsapp = ?");
    $st->execute([$whatsapp]);
    $p = $st->fetch();
    if (!$p) {
        return ['ok' => false, 'message' => 'No partner found with that WhatsApp number. Contact admin if you should be a partner.', 'partner' => null];
    }
    if (empty($p['password_hash'])) {
        return ['ok' => false, 'message' => 'Your password hasn\'t been set up yet. Please use the setup link sent to your WhatsApp, or contact admin.', 'partner' => null];
    }
    if (!password_verify($password, (string)$p['password_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect password.', 'partner' => null];
    }
    if ($p['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Your account is currently ' . htmlspecialchars($p['status']) . '. Please contact admin.', 'partner' => null];
    }

    $_SESSION[PARTNER_SESSION_KEY] = (int)$p['id'];
    db()->prepare("UPDATE partners SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([(int)$p['id']]);
    return ['ok' => true, 'message' => '', 'partner' => $p];
}

/** Log out the current partner. */
function partner_logout(): void {
    unset($_SESSION[PARTNER_SESSION_KEY]);
}

/**
 * Generate a fresh password-setup token for a partner. Valid for 24 hours.
 * Returns the token (caller is responsible for delivering it via WhatsApp).
 */
function partner_generate_setup_token(int $partner_id): string {
    $token = bin2hex(random_bytes(24));
    db()->prepare("UPDATE partners
                   SET password_setup_token = ?, password_setup_token_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$token, $partner_id]);
    return $token;
}

/** Find a partner by setup token, or null if invalid/expired. */
function partner_by_setup_token(string $token): ?array {
    if (strlen($token) < 16) return null;
    $st = db()->prepare("SELECT * FROM partners
                         WHERE password_setup_token = ?
                           AND password_setup_token_at IS NOT NULL");
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) return null;

    // Check expiry
    $issued = strtotime((string)$row['password_setup_token_at']);
    if (!$issued || (time() - $issued) > PARTNER_TOKEN_TTL_HRS * 3600) {
        return null;
    }
    return $row;
}

/** Set a partner's password (clears the setup token). Returns true on success. */
function partner_set_password(int $partner_id, string $password): bool {
    if (strlen($password) < 6) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = db()->prepare("UPDATE partners
                         SET password_hash = ?, password_setup_token = NULL, password_setup_token_at = NULL
                         WHERE id = ?");
    return $st->execute([$hash, $partner_id]);
}

/**
 * Build a WhatsApp click-to-send URL pre-loaded with the magic link.
 * Used by admin to onboard partners. Caller passes the partner's WhatsApp number.
 */
function partner_setup_whatsapp_url(string $whatsapp, string $partner_name, string $token, string $base_url): string {
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    $link = rtrim($base_url, '/') . '/partner-set-password.php?token=' . urlencode($token);
    $msg = "Hi {$partner_name}, welcome to EmpowerStudents as a partner!\n\n"
         . "Please set your login password using this link (valid 24 hours):\n"
         . $link . "\n\n"
         . "After setup, sign in at " . rtrim($base_url, '/') . "/partner-login.php with your WhatsApp number.";
    return 'https://wa.me/' . $whatsapp . '?text=' . urlencode($msg);
}

/* ─────────────────────────────────────────────────────────────────────────────
   OTP-based password reset  (fresh-v12)
   Uses the same `otps` table as the parent OTP flow.
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Send a WhatsApp OTP to a partner for password reset.
 * Returns ['ok'=>true] or ['ok'=>false, 'message'=>string].
 */
function partner_send_reset_otp(string $whatsapp): array {
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    if (strlen($whatsapp) < 10) {
        return ['ok' => false, 'message' => 'Please enter a valid WhatsApp number.'];
    }
    // Must be an active partner
    $st = db()->prepare("SELECT id, name, status FROM partners WHERE whatsapp = ?");
    $st->execute([$whatsapp]);
    $partner = $st->fetch();
    if (!$partner) {
        return ['ok' => false, 'message' => 'No partner account found with that WhatsApp number.'];
    }
    if ($partner['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Your account is ' . $partner['status'] . '. Contact admin.'];
    }

    // Resend throttle (30 s)
    try {
        $rt = db()->prepare("SELECT sent_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
        $rt->execute(['+91' . substr($whatsapp, -10)]);
        $last = $rt->fetch();
        if ($last && (time() - strtotime($last['sent_at'])) < 30) {
            return ['ok' => false, 'message' => 'Please wait 30 seconds before requesting another OTP.'];
        }
    } catch (Throwable $_) {}

    $code    = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $hash    = password_hash($code, PASSWORD_DEFAULT);
    $phone   = '+91' . substr($whatsapp, -10);
    $exp     = date('Y-m-d H:i:s', time() + 600);   // 10 min

    try {
        db()->prepare("INSERT INTO otps (whatsapp, code_hash, expires_at) VALUES (?,?,?)")
           ->execute([$phone, $hash, $exp]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'DB error: ' . $e->getMessage()];
    }

    // Send via Twilio (reuse send_otp_message from sms.php if available)
    @require_once __DIR__ . '/sms.php';
    $body = "EmpowerStudents Partner Reset OTP: *{$code}*\nValid for 10 minutes. Do not share.";
    if (function_exists('send_otp_message')) {
        send_otp_message($phone, $code);
    } else {
        // Fallback: direct Twilio curl
        $sid   = defined('TWILIO_SID')   ? TWILIO_SID   : '';
        $token = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : '';
        $from  = defined('TWILIO_FROM')  ? TWILIO_FROM  : 'whatsapp:+14155238886';
        if ($sid && $token) {
            $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => 'whatsapp:' . $phone, 'Body' => $body]),
                CURLOPT_USERPWD        => "{$sid}:{$token}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // Demo mode — expose OTP in response so admin can test without Twilio
    $resp = ['ok' => true, 'phone' => $phone];
    if (defined('OTP_MODE') && OTP_MODE === 'demo') {
        $resp['demo_otp'] = $code;
    }
    return $resp;
}

/**
 * Verify OTP and set new password for a partner.
 * Returns ['ok'=>true] or ['ok'=>false, 'message'=>string].
 */
function partner_verify_otp_and_reset(string $whatsapp, string $entered_otp, string $new_password): array {
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    if (strlen($whatsapp) < 10) {
        return ['ok' => false, 'message' => 'Invalid WhatsApp number.'];
    }
    if (strlen($new_password) < 6) {
        return ['ok' => false, 'message' => 'Password must be at least 6 characters.'];
    }

    $phone = '+91' . substr($whatsapp, -10);
    $entered_otp = preg_replace('/\D/', '', $entered_otp);
    if (strlen($entered_otp) < 4) {
        return ['ok' => false, 'message' => 'Enter the OTP.'];
    }

    // Fetch latest OTP
    $st = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'No OTP found. Please request a new one.'];
    }
    if (!empty($row['used_at'])) {
        return ['ok' => false, 'message' => 'OTP already used. Request a new one.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'OTP expired. Request a new one.'];
    }
    if (!password_verify($entered_otp, (string)$row['code_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect OTP. Try again.'];
    }

    // Mark OTP used
    db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$row['id']]);

    // Fetch partner
    $pst = db()->prepare("SELECT id FROM partners WHERE whatsapp = ?");
    $pst->execute([$whatsapp]);
    $partner_id = (int)$pst->fetchColumn();
    if (!$partner_id) {
        return ['ok' => false, 'message' => 'Partner not found.'];
    }

    // Set new password
    if (!partner_set_password($partner_id, $new_password)) {
        return ['ok' => false, 'message' => 'Could not save password. Try again.'];
    }

    return ['ok' => true, 'message' => 'Password updated. Please sign in.'];
}
