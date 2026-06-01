<?php
/**
 * partner-logout.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/partner_auth.php';

partner_logout();
header('Location: /partner-login.php?logged_out=1');
exit;
