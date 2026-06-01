<?php
/**
 * includes/partner_capture.php — capture ?ref=CODE on landing
 *
 * Two-stage flow:
 *   Stage 1 (anonymous visitor):
 *     User lands at /?ref=SUNRISE
 *     -> Code stored in session (and cookie for cross-tab durability)
 *     -> Survives until they sign up (could be days later)
 *
 *   Stage 2 (after parent_id is set in session):
 *     Auth code calls partner_capture_attribute_session_parent()
 *     -> Reads session/cookie, attaches to parent record (first-touch wins)
 *     -> Backfills any wallet_ledger charges that happened after the
 *        ref was captured but before attribution (e.g. signup_bonus
 *        won't apply because it's positive, but if any negative charge
 *        slipped through it'd be backfilled)
 *
 * Cookie is named `es_ref` and lasts 30 days (typical attribution window).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/partner_schema.php';

const PARTNER_REF_COOKIE = 'es_ref';
const PARTNER_REF_TTL_DAYS = 30;

/**
 * Call from any landing page (top of index.php is fine).
 * If ?ref=CODE in URL and code matches an active partner, store in
 * session + cookie. No-op if no ?ref or unknown code.
 */
function partner_capture_from_url(): void {
    if (empty($_GET['ref'])) return;
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string)$_GET['ref']));
    if ($code === '') return;

    $partner = partner_by_code($code);
    if (!$partner) return;             // unknown / inactive code

    if (session_status() === PHP_SESSION_NONE) session_start();
    // First-touch wins — don't overwrite an existing session ref
    if (empty($_SESSION['partner_ref_code'])) {
        $_SESSION['partner_ref_code'] = $code;
        $_SESSION['partner_ref_id']   = (int)$partner['id'];
    }

    // Cookie for cross-tab / cross-session persistence
    if (empty($_COOKIE[PARTNER_REF_COOKIE])) {
        setcookie(PARTNER_REF_COOKIE, $code, [
            'expires'  => time() + (PARTNER_REF_TTL_DAYS * 86400),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Resolve the current visitor's referring partner (session preferred, cookie fallback).
 * Returns partner_id or null.
 */
function partner_capture_current_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['partner_ref_id'])) return (int)$_SESSION['partner_ref_id'];
    if (!empty($_COOKIE[PARTNER_REF_COOKIE])) {
        $partner = partner_by_code((string)$_COOKIE[PARTNER_REF_COOKIE]);
        if ($partner) {
            $_SESSION['partner_ref_id']   = (int)$partner['id'];
            $_SESSION['partner_ref_code'] = $partner['referral_code'];
            return (int)$partner['id'];
        }
    }
    return null;
}

/**
 * Called once per session after a parent successfully logs in / registers.
 * Attaches the parent to the partner (first-touch wins) and backfills any
 * charges that may have happened in the small window between attribution
 * and now.
 */
function partner_capture_attribute_session_parent(int $parent_id): void {
    $pid = partner_capture_current_id();
    if (!$pid) return;
    if (partner_attribute_parent($parent_id, $pid)) {
        // Backfill any charges this parent already incurred
        partner_backfill_payouts($pid);
    }
}

/**
 * Optional: also stash the partner code on a leads row so the
 * leads admin can see which partner sent each lead.
 * Call this from lead_submit.php right after the INSERT.
 */
function partner_capture_attribute_lead(int $lead_id): void {
    $pid = partner_capture_current_id();
    if (!$pid) return;
    // Add column if missing (idempotent)
    $cols = db()->query("PRAGMA table_info(leads)")->fetchAll();
    $has = false;
    foreach ($cols as $c) if ($c['name'] === 'partner_id') { $has = true; break; }
    if (!$has) {
        try { db()->exec("ALTER TABLE leads ADD COLUMN partner_id INTEGER"); } catch (Throwable $_) {}
    }
    db()->prepare("UPDATE leads SET partner_id = ? WHERE id = ? AND partner_id IS NULL")
        ->execute([$pid, $lead_id]);
}
