<?php
/**
 * admin_passwordless_login.php
 *
 * Open this URL ONCE while logged in as the parent registered with
 * +91 98102 94877 (Dr. P. K. Jha). It will:
 *
 *   1. Confirm your parent session matches that number.
 *   2. Mark your parent account as VIP (visible badge in admin → Parents).
 *   3. Create an admin session for you (under the existing 'admin' user).
 *   4. Redirect you to /admin/index.php.
 *
 * If you are NOT logged in as that parent, it will redirect you to
 * /login.php to log in first.
 *
 * Safe to leave on the server — it only grants admin access if the
 * caller is already authenticated as the specific WhatsApp number
 * hard-coded below. Even so, you can DELETE it once you've used it
 * a few times — `/admin/login.php` (admin / empower@2026, then change
 * the password) is the normal path.
 */
require_once __DIR__ . '/includes/auth.php';

const ADMIN_OWNER_PHONE = '+919810294877';   // Dr. P. K. Jha

// 1. Must be logged in as a parent.
$me = current_parent();
if (!$me) {
    $_SESSION['flash_error'] = 'Log in with your registered WhatsApp number first.';
    header('Location: /login.php?next=/admin_passwordless_login.php');
    exit;
}

// 2. Must be the registered owner phone.
if ($me['whatsapp'] !== ADMIN_OWNER_PHONE) {
    http_response_code(403);
    echo "<html><body style='font-family:system-ui;max-width:540px;margin:60px auto;padding:0 16px'>";
    echo "<h1 style='color:#dc2626'>Not authorised</h1>";
    echo "<p>This passwordless admin entry is hard-coded to "
       . "<code>" . htmlspecialchars(ADMIN_OWNER_PHONE) . "</code>.</p>";
    echo "<p>Your current parent session is logged in as <code>"
       . htmlspecialchars($me['whatsapp']) . "</code>.</p>";
    echo "<p><a href='/logout.php'>Logout</a> and login with the right number, "
       . "or use the regular <a href='/admin/login.php'>admin/login.php</a> page.</p>";
    echo "</body></html>";
    exit;
}

// 3. Mark this parent as VIP (idempotent).
db()->prepare("UPDATE parents SET is_vip = 1 WHERE id = ?")->execute([$me['id']]);

// 4. Find or create the admin row to attach the session to.
$admin = db()->query("SELECT id, username FROM admins ORDER BY id ASC LIMIT 1")->fetch();
if (!$admin) {
    // No admin exists yet — create the default and tell the user the password.
    db()->prepare(
        "INSERT INTO admins (username, pass_hash, name) VALUES (?, ?, ?)"
    )->execute([
        'admin',
        password_hash('empower@2026', PASSWORD_DEFAULT),
        'Dr. P. K. Jha',
    ]);
    $admin = db()->query("SELECT id, username FROM admins ORDER BY id ASC LIMIT 1")->fetch();
}

// 5. Open the admin session.
$_SESSION['admin_id']   = (int)$admin['id'];
$_SESSION['admin_user'] = $admin['username'];

header('Location: /admin/index.php');
exit;
