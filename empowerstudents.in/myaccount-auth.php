<?php
/**
 * myaccount-auth.php — generic partner login endpoint.
 *
 * Friendlier canonical URL for the partner login. The underlying logic
 * lives in pediatrician-auth.php, which only renders its login UI when
 * SCRIPT_NAME's basename is 'pediatrician-auth.php'. So we spoof it.
 *
 * Both /myaccount-auth.php and /pediatrician-auth.php work identically.
 */

// Make pediatrician-auth.php believe it's running as itself, so it
// renders the login form instead of behaving as a silent library.
$_SERVER['SCRIPT_NAME'] = '/pediatrician-auth.php';

require __DIR__ . '/pediatrician-auth.php';
