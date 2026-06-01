<?php
require_once __DIR__ . '/../includes/config.php';
unset($_SESSION['admin_id'], $_SESSION['admin_user']);
header('Location: /admin/login.php');
exit;
