<?php
/**
 * diag_home_course.php
 *
 * Diagnostic — visit this URL to see exactly where home_course_engine fails.
 * Returns JSON with step-by-step status. DELETE AFTER USE.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$log = [];
function L($k, $v = null) { global $log; $log[] = [$k, $v]; }

try {
    L('php_version', PHP_VERSION);
    L('cwd', __DIR__);

    L('step', 'require config');
    require_once __DIR__ . '/includes/config.php';
    L('config_loaded', true);

    L('step', 'require db');
    require_once __DIR__ . '/includes/db.php';
    L('db_init exists?', function_exists('db_init'));
    if (function_exists('db_init')) {
        db_init();
        L('db_init called', true);
    }

    L('step', 'require auth');
    require_once __DIR__ . '/includes/auth.php';
    L('auth_loaded', true);
    L('require_parent exists?', function_exists('require_parent'));
    L('current_parent exists?', function_exists('current_parent'));
    L('csrf_check exists?', function_exists('csrf_check'));

    L('step', 'require wallet');
    require_once __DIR__ . '/includes/wallet.php';
    L('wallet_loaded', true);
    L('wallet_charge_for_service exists?', function_exists('wallet_charge_for_service'));
    L('wallet_post exists?', function_exists('wallet_post'));

    L('step', 'require parent_reflect_schema');
    require_once __DIR__ . '/includes/parent_reflect_schema.php';
    L('pr_schema_loaded', true);

    L('step', 'require parent_reflect_home_climate');
    require_once __DIR__ . '/includes/parent_reflect_home_climate.php';
    L('hc_loaded', true);

    L('step', 'require home_course_engine');
    require_once __DIR__ . '/includes/home_course_engine.php';
    L('hce_loaded', true);

    L('step', 'check home_course_engine functions');
    L('_home_course_ensure_schema exists?', function_exists('_home_course_ensure_schema'));
    L('home_course_create exists?', function_exists('home_course_create'));

    L('step', 'run schema ensure');
    _home_course_ensure_schema();
    L('schema_ensure called', true);

    L('step', 'check home_courses table');
    $t = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='home_courses'")->fetch();
    L('home_courses table exists?', !empty($t));

    L('step', 'check service_prices has home SKUs');
    $st = db()->query("SELECT service_key, price, is_active FROM service_prices WHERE service_key LIKE 'home_course_%'");
    $rows = $st->fetchAll();
    L('SKUs found', count($rows));
    foreach ($rows as $r) L('  - ' . $r['service_key'], '₹' . $r['price'] . ' active=' . $r['is_active']);

    L('step', 'check service_prices columns');
    $cols = db()->query("PRAGMA table_info(service_prices)")->fetchAll();
    L('columns', array_column($cols, 'name'));

    L('step', 'try wallet_service_price lookup');
    $price = wallet_service_price('home_course_5min');
    L('home_course_5min price', $price);

    L('result', 'ALL CHECKS PASSED');
} catch (Throwable $e) {
    L('❌ EXCEPTION', $e->getMessage());
    L('file', $e->getFile() . ':' . $e->getLine());
    L('trace', $e->getTraceAsString());
}

echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
