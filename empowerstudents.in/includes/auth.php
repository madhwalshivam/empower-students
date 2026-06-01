<?php
require_once __DIR__ . '/db.php';

/* ─────────────────────────────────────────────────────────────
 * Persistent-login (60-day "remember-me") — DB schema upgrade
 * ───────────────────────────────────────────────────────────── */
function ensure_remember_columns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("PRAGMA table_info(parents)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('remember_hash', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN remember_hash TEXT");
        }
        if (!in_array('remember_expires', $names, true)) {
            db()->exec("ALTER TABLE parents ADD COLUMN remember_expires TEXT");
        }
    } catch (Throwable $e) { /* fail silently */ }
}

const REMEMBER_COOKIE = 'EMPSTU_REMEMBER';
const REMEMBER_DAYS   = 60;

/* Issue a 60-day remember-me cookie for $parent_id. */
function set_remember_cookie($parent_id) {
    ensure_remember_columns();
    $token   = bin2hex(random_bytes(16));        // 32 hex chars
    $hash    = hash('sha256', $token);
    $expires = time() + (REMEMBER_DAYS * 86400);

    db()->prepare("UPDATE parents SET remember_hash = ?, remember_expires = ? WHERE id = ?")
        ->execute([$hash, date('Y-m-d H:i:s', $expires), (int)$parent_id]);

    $cookie_value = $parent_id . '.' . $token;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    setcookie(REMEMBER_COOKIE, $cookie_value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* If a valid remember cookie is present, log the parent in. */
function try_login_from_cookie() {
    if (!empty($_SESSION['parent_id'])) return;          // already logged in
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    ensure_remember_columns();

    $raw = $_COOKIE[REMEMBER_COOKIE];
    if (strpos($raw, '.') === false) return;
    [$pid, $token] = explode('.', $raw, 2);
    $pid = (int)$pid;
    if ($pid <= 0 || strlen($token) < 16) return;

    $st = db()->prepare("SELECT id, remember_hash, remember_expires FROM parents WHERE id = ?");
    $st->execute([$pid]);
    $row = $st->fetch();
    if (!$row) return;
    if (!$row['remember_hash'] || !$row['remember_expires']) return;
    if (strtotime($row['remember_expires']) < time()) return;

    if (!hash_equals($row['remember_hash'], hash('sha256', $token))) return;

    /* Valid → log in and slide expiry forward (rolling cookie) */
    $_SESSION['parent_id'] = $pid;
    db()->prepare("UPDATE parents SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pid]);
    set_remember_cookie($pid);
}

/* Clear the remember cookie + DB hash on logout */
function clear_remember_cookie() {
    if (!empty($_SESSION['parent_id'])) {
        ensure_remember_columns();
        try {
            db()->prepare("UPDATE parents SET remember_hash = NULL, remember_expires = NULL WHERE id = ?")
                ->execute([(int)$_SESSION['parent_id']]);
        } catch (Throwable $e) {}
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path'    => '/',
        'secure'  => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'=> true,
        'samesite'=> 'Lax',
    ]);
}

/* Auto-attempt cookie login on every request that requires the parent.
   Wrap in try/catch — a DB issue here must NEVER break the whole site. */
try { try_login_from_cookie(); } catch (Throwable $e) { /* swallow */ }

/* ─────────────────────────────────────────────────────────────
 * Standard auth helpers
 * ───────────────────────────────────────────────────────────── */
function current_parent() {
    if (empty($_SESSION['parent_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = db()->prepare('SELECT * FROM parents WHERE id = ?');
    $st->execute([$_SESSION['parent_id']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}

function require_parent() {
    if (!current_parent()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
        header('Location: /login.php?next=' . urlencode($next));
        exit;
    }
}

function require_admin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: /admin/login.php');
        exit;
    }
}

function normalize_phone($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (strlen($digits) === 10) { $digits = '91' . $digits; }
    if (strlen($digits) > 15)   { $digits = substr($digits, -12); }
    return '+' . $digits;
}

function calc_age_years($dob) {
    try {
        $b = new DateTime($dob);
        $n = new DateTime('today');
        return (float) $b->diff($n)->y + ($b->diff($n)->m / 12.0);
    } catch (Exception $e) { return null; }
}

function age_band($years) {
    global $AGE_BANDS;
    if ($years === null) return 'child';
    foreach ($AGE_BANDS as $name => $range) {
        if ($years >= $range[0] && $years < $range[1]) return $name;
    }
    return 'teen';
}

function child_for_parent($child_id) {
    $p = current_parent();
    if (!$p) return null;
    $st = db()->prepare('SELECT * FROM children WHERE id = ? AND parent_id = ?');
    $st->execute([$child_id, $p['id']]);
    return $st->fetch() ?: null;
}
