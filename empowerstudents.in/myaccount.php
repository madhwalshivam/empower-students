<?php
/**
 * myaccount.php — generic partner dashboard.
 *
 * This is the canonical URL for the partner dashboard going forward.
 * The original /pediatrician.php still works (for backward compat with
 * already-printed QR codes / shared WhatsApp links).
 *
 * Both files do the same thing — load the same logic from pediatrician.php.
 */

// Just include the original file. It already adapts content based on the
// logged-in partner's partner_type (after the patcher runs).
require __DIR__ . '/pediatrician.php';
