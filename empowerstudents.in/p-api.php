<?php
/**
 * p-api.php
 *
 * JSON API endpoints for the in-page signup flow on /p.php?code=DRSHARMA.
 * Lets a parent register + verify OTP + create child + start payment WITHOUT
 * leaving the pediatrician-branded landing page. The parent never sees
 * the EmpowerStudents-branded /login.php or /parent-register.php pages.
 *
 * Endpoints (all POST):
 *   action=send_otp     {phone, ref_code}
 *   action=verify_otp   {code, name}
 *   action=create_child {child_name, child_dob, gender, mother_tongue, reason}
 *   action=start_eval   {}   — returns Cashfree session URL or starts the eval
 *
 * All responses JSON: {ok: bool, ...}
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$action = $_POST['action'] ?? '';

// ────────────────────────────────────────────
// Helper: attribute partner referral
// ────────────────────────────────────────────
function _p_attribute_partner(int $parent_id, string $code): void {
    if ($code === '') return;
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $code));
    if ($code === '') return;

    try {
        $st = db()->prepare("SELECT id FROM partners WHERE referral_code = ? AND status = 'active'");
        $st->execute([$code]);
        $pid = (int)$st->fetchColumn();
        if ($pid > 0) {
            // Ensure parents has partner_id column
            $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
            if (!in_array('partner_id', array_column($cols, 'name'), true)) {
                @db()->exec("ALTER TABLE parents ADD COLUMN partner_id INTEGER");
            }
            db()->prepare("UPDATE parents SET partner_id = ? WHERE id = ? AND (partner_id IS NULL OR partner_id = 0)")
               ->execute([$pid, $parent_id]);
        }
    } catch (Throwable $_) {}
}


// ────────────────────────────────────────────
// 1. send_otp
// ────────────────────────────────────────────
if ($action === 'send_otp') {
    $phone_raw = trim($_POST['phone'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $ref_code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $_POST['ref_code'] ?? ''));

    $phone = normalize_phone($phone_raw);
    if (!preg_match('/^\+\d{10,15}$/', $phone)) {
        echo json_encode(['ok' => false, 'error' => 'Please enter a valid WhatsApp number with country code.']);
        exit;
    }
    if ($name === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter your name.']);
        exit;
    }

    // Resend throttle
    $st = db()->prepare("SELECT sent_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $last = $st->fetch();
    if ($last && (time() - strtotime($last['sent_at'])) < (defined('OTP_RESEND_GAP') ? OTP_RESEND_GAP : 30)) {
        echo json_encode(['ok' => false, 'error' => 'Please wait a few seconds before requesting another OTP.']);
        exit;
    }

    $code = generate_otp_code();
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $exp = date('Y-m-d H:i:s', time() + (defined('OTP_TTL_SECS') ? OTP_TTL_SECS : 300));

    db()->prepare("INSERT INTO otps (whatsapp, code_hash, expires_at) VALUES (?,?,?)")
       ->execute([$phone, $hash, $exp]);

    send_otp_message($phone, $code);

    $_SESSION['otp_phone'] = $phone;
    $_SESSION['otp_name']  = $name;
    $_SESSION['otp_ref']   = $ref_code;

    $resp = ['ok' => true, 'phone' => $phone];
    if (defined('OTP_MODE') && OTP_MODE === 'demo') {
        $resp['demo_otp'] = $code;
    }
    echo json_encode($resp);
    exit;
}


// ────────────────────────────────────────────
// 2. verify_otp
// ────────────────────────────────────────────
if ($action === 'verify_otp') {
    $phone = $_SESSION['otp_phone'] ?? '';
    $name = $_SESSION['otp_name'] ?? '';
    $ref_code = $_SESSION['otp_ref'] ?? '';
    $entered = preg_replace('/\D/', '', $_POST['code'] ?? '');

    if (!$phone) {
        echo json_encode(['ok' => false, 'error' => 'Please request an OTP first.']);
        exit;
    }
    if (strlen($entered) < 4) {
        echo json_encode(['ok' => false, 'error' => 'Enter the OTP.']);
        exit;
    }

    // Look up the most recent OTP for this phone
    $st = db()->prepare("SELECT id, code_hash, expires_at, used_at FROM otps WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'No OTP found. Please request again.']);
        exit;
    }
    if (!empty($row['used_at'])) {
        echo json_encode(['ok' => false, 'error' => 'This OTP is already used. Request a new one.']);
        exit;
    }
    if (strtotime($row['expires_at']) < time()) {
        echo json_encode(['ok' => false, 'error' => 'OTP expired. Request a new one.']);
        exit;
    }
    if (!password_verify($entered, $row['code_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect OTP.']);
        exit;
    }

    db()->prepare("UPDATE otps SET used_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$row['id']]);

    // Find or create parent
    $pst = db()->prepare("SELECT id FROM parents WHERE whatsapp = ? LIMIT 1");
    $pst->execute([$phone]);
    $parent_id = (int)$pst->fetchColumn();
    $is_new = false;

    if (!$parent_id) {
        // Create parent
        $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
        $col_names = array_column($cols, 'name');

        $insert_data = [
            'whatsapp' => $phone,
            'name' => $name,
        ];
        if (in_array('credits', $col_names, true)) {
            $insert_data['credits'] = defined('WELCOME_CREDITS') ? WELCOME_CREDITS : 0;
        }
        if (in_array('created_at', $col_names, true)) {
            $insert_data['created_at'] = date('Y-m-d H:i:s');
        }

        $cols_list = array_keys($insert_data);
        $placeholders = implode(', ', array_fill(0, count($cols_list), '?'));
        db()->prepare("INSERT INTO parents (" . implode(', ', $cols_list) . ") VALUES ($placeholders)")
            ->execute(array_values($insert_data));
        $parent_id = (int)db()->lastInsertId();
        $is_new = true;
    } else {
        // Update name if changed
        if ($name) {
            db()->prepare("UPDATE parents SET name = ? WHERE id = ? AND (name IS NULL OR name = '')")
               ->execute([$name, $parent_id]);
        }
    }

    // Attribute partner referral
    _p_attribute_partner($parent_id, $ref_code);

    // Auto-credit ₹2000 to NEW parents who signed up via a partner referral link
    if ($is_new && $ref_code !== '') {
        try {
            // Check partner is active
            $pck = db()->prepare("SELECT id FROM partners WHERE referral_code=? AND status='active'");
            $pck->execute([$ref_code]);
            if ($pck->fetchColumn()) {
                // Only credit once (guard: no existing referral_credit transaction)
                $gck = db()->prepare("SELECT COUNT(*) FROM wallet_transactions
                    WHERE parent_id=? AND service_key='referral_credit' LIMIT 1");
                $gck->execute([$parent_id]);
                if ((int)$gck->fetchColumn() === 0) {
                    // Ensure wallet_transactions table exists
                    db()->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        parent_id INTEGER, amount REAL, service_key TEXT,
                        description TEXT, created_by TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                    // Credit wallet
                    db()->prepare("UPDATE parents SET credits = COALESCE(credits,0) + 2000 WHERE id=?")
                       ->execute([$parent_id]);
                    db()->prepare("INSERT INTO wallet_transactions
                        (parent_id, amount, service_key, description, created_by)
                        VALUES (?,2000,'referral_credit','Partner referral welcome credit','system')")
                       ->execute([$parent_id]);
                }
            }
        } catch (Throwable $_) {}
    }

    // Set parent session
    $_SESSION['parent_id'] = $parent_id;
    $_SESSION['phone']     = $phone;
    if (function_exists('current_parent')) current_parent();  // warm up

    // Set csrf if not present
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

    // Check if parent has a child already
    $cst = db()->prepare("SELECT id FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
    $cst->execute([$parent_id]);
    $existing_child = (int)$cst->fetchColumn();

    echo json_encode([
        'ok'             => true,
        'parent_id'      => $parent_id,
        'is_new'         => $is_new,
        'has_child'      => $existing_child > 0,
        'csrf'           => $_SESSION['csrf'],
    ]);
    exit;
}


// ────────────────────────────────────────────
// 3. create_child
// ────────────────────────────────────────────
if ($action === 'create_child') {
    $parent_id = (int)($_SESSION['parent_id'] ?? 0);
    if (!$parent_id) {
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please verify OTP first.']);
        exit;
    }

    $child_name   = trim($_POST['child_name'] ?? '');
    $child_dob    = trim($_POST['child_dob'] ?? '');
    $gender       = trim($_POST['gender'] ?? '');
    $mother_tongue= trim($_POST['mother_tongue'] ?? 'Hindi');
    $reason       = trim($_POST['reason'] ?? '');

    if ($child_name === '' || strlen($child_name) < 2) {
        echo json_encode(['ok' => false, 'error' => "Please enter your child's name."]);
        exit;
    }

    // Check if a child of this name already exists for this parent (avoid duplicates)
    $cst = db()->prepare("SELECT id FROM children WHERE parent_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
    $cst->execute([$parent_id, $child_name]);
    $existing = (int)$cst->fetchColumn();

    if (!$existing) {
        $cols = db()->query("PRAGMA table_info(children)")->fetchAll();
        $col_names = array_column($cols, 'name');

        $data = ['parent_id' => $parent_id, 'name' => $child_name];
        if ($child_dob && in_array('dob', $col_names, true)) $data['dob'] = $child_dob;
        if ($gender && in_array('gender', $col_names, true)) $data['gender'] = $gender;
        if ($mother_tongue && in_array('mother_tongue', $col_names, true)) $data['mother_tongue'] = $mother_tongue;
        if ($reason && in_array('notes', $col_names, true)) $data['notes'] = $reason;
        if (in_array('created_at', $col_names, true)) $data['created_at'] = date('Y-m-d H:i:s');

        $cols_list = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols_list), '?'));
        db()->prepare("INSERT INTO children (" . implode(', ', $cols_list) . ") VALUES ($placeholders)")
            ->execute(array_values($data));
        $existing = (int)db()->lastInsertId();
    }

    $_SESSION['active_child_id'] = $existing;

    /* fresh-v10: optional promo code redemption (one-time, at signup only) */
    $promo_result = null;
    $promo_code = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
    if ($promo_code !== '') {
        @require_once __DIR__ . '/includes/partners.php';
        if (function_exists('promo_redeem')) {
            $rr = promo_redeem($promo_code, $parent_id);
            if (!empty($rr['ok'])) {
                $promo_result = [
                    'applied' => true,
                    'credit'  => (int)$rr['credit_granted'],
                    'code'    => $rr['code'] ?? $promo_code,
                ];
            } else {
                $promo_result = [
                    'applied' => false,
                    'error'   => $rr['error'] ?? 'Code could not be applied.',
                ];
            }
        }
    }

    $resp = ['ok' => true, 'child_id' => $existing];
    if ($promo_result !== null) $resp['promo'] = $promo_result;
    echo json_encode($resp);
    exit;
}


// ────────────────────────────────────────────
// 4. check_status  (used by JS to figure out which step the user is at)
// ────────────────────────────────────────────
if ($action === 'check_status') {
    $parent_id = (int)($_SESSION['parent_id'] ?? 0);
    if (!$parent_id) {
        echo json_encode(['ok' => true, 'step' => 'phone']);
        exit;
    }

    $cst = db()->prepare("SELECT id FROM children WHERE parent_id = ? LIMIT 1");
    $cst->execute([$parent_id]);
    $has_child = (int)$cst->fetchColumn() > 0;

    // Also report wallet balance — if they already have ₹1000+, skip pay step
    $balance = 0;
    try {
        $b = db()->prepare("SELECT credits FROM parents WHERE id = ?");
        $b->execute([$parent_id]);
        $balance = (int)$b->fetchColumn();
    } catch (Throwable $_) {}

    $step = $has_child ? ($balance >= 1000 ? 'ready' : 'pay') : 'child';
    echo json_encode(['ok' => true, 'step' => $step, 'parent_id' => $parent_id, 'balance' => $balance]);
    exit;
}


// ────────────────────────────────────────────
// 5. create_order — create Cashfree order for ₹1000
// ────────────────────────────────────────────
if ($action === 'create_order') {
    $parent_id = (int)($_SESSION['parent_id'] ?? 0);
    if (!$parent_id) {
        echo json_encode(['ok' => false, 'error' => 'Session expired — please verify OTP again.']);
        exit;
    }

    require_once __DIR__ . '/includes/cashfree.php';

    if (!cf_is_configured()) {
        echo json_encode(['ok' => false, 'error' => 'Payment gateway not configured. Contact support.']);
        exit;
    }

    $amount = 1000;
    $ref_code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $_POST['ref_code'] ?? ($_SESSION['otp_ref'] ?? '')));
    $_SESSION['p_flow_ref'] = $ref_code;   // remember so p-return can route back

    // Parent row
    $pst = db()->prepare("SELECT id, name, email, whatsapp FROM parents WHERE id = ?");
    $pst->execute([$parent_id]);
    $parent = $pst->fetch();
    if (!$parent) {
        echo json_encode(['ok' => false, 'error' => 'Parent not found']);
        exit;
    }

    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
          . ($_SERVER['HTTP_HOST'] ?? 'empowerstudents.in');
    $return_url = $base . '/p-return.php?order_id={order_id}';
    $notify_url = $base . '/payment_webhook.php';

    $order_id = cf_make_order_id($parent_id);
    db()->prepare("INSERT INTO payment_orders (order_id, parent_id, amount, currency, status)
                   VALUES (?, ?, ?, 'INR', 'pending')")
       ->execute([$order_id, $parent_id, $amount]);

    $phone = preg_replace('/\D+/', '', (string)$parent['whatsapp']);
    if (strlen($phone) > 10) $phone = substr($phone, -10);
    if (!$phone) $phone = '9999999999';

    try {
        $resp = cf_create_order([
            'order_id'       => $order_id,
            'order_amount'   => $amount,
            'customer_id'    => 'parent_' . $parent_id,
            'customer_name'  => $parent['name'] ?: 'Parent',
            'customer_email' => $parent['email'] ?: 'noreply@empowerstudents.in',
            'customer_phone' => $phone,
            'return_url'     => $return_url,
            'notify_url'     => $notify_url,
            'note'           => "Parent Evaluation ₹1000" . ($ref_code ? " · ref=$ref_code" : ''),
        ]);
    } catch (Throwable $e) {
        db()->prepare("UPDATE payment_orders SET status='failed', raw_response=? WHERE order_id=?")
           ->execute([substr('create_error: ' . $e->getMessage(), 0, 5000), $order_id]);
        echo json_encode(['ok' => false, 'error' => 'Could not start payment: ' . $e->getMessage()]);
        exit;
    }

    db()->prepare("UPDATE payment_orders SET raw_response=? WHERE order_id=?")
       ->execute([substr(json_encode($resp), 0, 5000), $order_id]);

    echo json_encode([
        'ok'                 => true,
        'order_id'           => $order_id,
        'payment_session_id' => $resp['payment_session_id'] ?? '',
        'mode'               => defined('CASHFREE_ENV') ? CASHFREE_ENV : 'sandbox',
    ]);
    exit;
}


echo json_encode(['ok' => false, 'error' => 'Unknown action']);
