<?php
/**
 * partners_invite_patch.php  — fresh-v12
 *
 * APPEND the contents of this file (everything between the PHP tags below)
 * to the BOTTOM of includes/partners.php on production.
 *
 * Do NOT overwrite includes/partners.php — just open it in cPanel editor,
 * scroll to bottom, paste below this comment block.
 *
 * Functions added:
 *   invite_count_today($partner_id)       → int
 *   invite_create($partner_id, $name, $wa) → array (row|['error'=>...])
 *   invite_get_by_token($token)            → array|null
 *
 * Uses db() — same PDO connection as the rest of includes/partners.php.
 * parent_invites table is created automatically if missing.
 */

/* ── Ensure parent_invites table exists ───────────────────────────────────── */
if (!function_exists('_invite_ensure_table')) {
    function _invite_ensure_table(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS parent_invites (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                partner_id        INTEGER NOT NULL,
                parent_name       TEXT NOT NULL,
                whatsapp_clean    TEXT NOT NULL,
                invite_token      TEXT UNIQUE NOT NULL,
                credit_amount     REAL DEFAULT 2000,
                status            TEXT DEFAULT 'pending',
                created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by        TEXT DEFAULT 'admin',
                claimed_at        DATETIME,
                claimed_parent_id INTEGER,
                expires_at        DATETIME
            )");
        } catch (Throwable $_) {}
    }
}

/* ── Count invites created today for a partner (daily limit check) ─────────  */
if (!function_exists('invite_count_today')) {
    function invite_count_today(int $partner_id): int {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM parent_invites
                WHERE partner_id = ? AND DATE(created_at) = DATE('now','localtime')");
            $st->execute([$partner_id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $_) { return 0; }
    }
}

/* ── Create a new invite (5/day limit enforced) ─────────────────────────────  */
if (!function_exists('invite_create')) {
    /**
     * @return array  Full invite row on success, or ['error' => string] on failure.
     */
    function invite_create(int $partner_id, string $parent_name, string $whatsapp_clean): array {
        _invite_ensure_table();
        if (invite_count_today($partner_id) >= 5) {
            return ['error' => 'Daily limit of 5 invites reached.'];
        }
        $token     = bin2hex(random_bytes(16));   // 32-char hex
        $expires   = date('Y-m-d H:i:s', strtotime('+7 days'));
        try {
            db()->prepare("INSERT INTO parent_invites
                (partner_id, parent_name, whatsapp_clean, invite_token, credit_amount, status, created_by, expires_at)
                VALUES (?, ?, ?, ?, 2000, 'pending', 'admin', ?)")
               ->execute([$partner_id, trim($parent_name), $whatsapp_clean, $token, $expires]);
            $id = (int)db()->lastInsertId();
            return invite_get_by_id($id) ?? ['error' => 'Could not retrieve created invite.'];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

/* ── Fetch invite by token ──────────────────────────────────────────────────  */
if (!function_exists('invite_get_by_token')) {
    function invite_get_by_token(string $token): ?array {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT * FROM parent_invites WHERE invite_token = ?");
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $_) { return null; }
    }
}

/* ── Fetch invite by id ─────────────────────────────────────────────────────  */
if (!function_exists('invite_get_by_id')) {
    function invite_get_by_id(int $id): ?array {
        _invite_ensure_table();
        try {
            $st = db()->prepare("SELECT * FROM parent_invites WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $_) { return null; }
    }
}
