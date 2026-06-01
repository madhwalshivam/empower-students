<?php
/**
 * hc_check.php?key=nci2026admin
 *
 * Quick diagnostic — shows home_courses table state for debugging
 * dashboard "Continue course" card.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);

if (($_GET['key'] ?? '') !== 'nci2026admin') {
    http_response_code(403); echo "forbidden"; exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
@require_once __DIR__ . '/includes/home_course_engine.php';

echo "=== HOME COURSE DIAGNOSTIC ===\n\n";

// 1. Schema ensured?
try {
    _home_course_ensure_schema();
    echo "✓ Schema ensured\n";
} catch (Throwable $e) {
    echo "✗ Schema ensure failed: " . $e->getMessage() . "\n";
}

// 2. All home_courses
try {
    $rows = db()->query("SELECT id, parent_id, sku, status, daily_minutes, price_paid, 
                                started_at, completed_at, reflect_session_id
                         FROM home_courses ORDER BY id DESC")->fetchAll();
    echo "\n--- ALL home_courses ---\n";
    if (!$rows) {
        echo "(empty table)\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("#%-3d parent=%-3d sku=%-20s status=%-12s minutes=%-3d price=%-5d reflect_sid=%s started=%s\n",
                $r['id'], $r['parent_id'], $r['sku'], $r['status'], 
                $r['daily_minutes'], $r['price_paid'], $r['reflect_session_id'] ?: '-', $r['started_at']);
        }
    }
} catch (Throwable $e) {
    echo "✗ home_courses query: " . $e->getMessage() . "\n";
}

// 3. Active course for parent 1
echo "\n--- home_course_find_active(1) ---\n";
$active = home_course_find_active(1);
if (!$active) {
    echo "Returns null (no active course found for parent 1)\n";
} else {
    echo "Found: course_id=#" . $active['id'] . " status=" . $active['status'] . "\n";
}

// 4. Days table
try {
    echo "\n--- home_course_days rows ---\n";
    $st = db()->prepare("SELECT id, course_id, day_no, status, completed_at, theme_key
                         FROM home_course_days ORDER BY course_id DESC, day_no ASC");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) {
        echo "(empty)\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("#%-3d course=%-3d day=%-2d status=%-12s theme=%-15s completed=%s\n",
                $r['id'], $r['course_id'], $r['day_no'], $r['status'], 
                $r['theme_key'] ?: '-', $r['completed_at'] ?: '-');
        }
    }
} catch (Throwable $e) {
    echo "✗ days query: " . $e->getMessage() . "\n";
}

// 5. Parents table — who is parent 1?
echo "\n--- Parent #1 ---\n";
try {
    $p = db()->prepare("SELECT id, name, whatsapp, credits FROM parents WHERE id = 1");
    $p->execute();
    $row = $p->fetch();
    if ($row) {
        echo "id=" . $row['id'] . " name=" . $row['name'] . " phone=" . $row['whatsapp'] . " wallet=₹" . $row['credits'] . "\n";
    } else {
        echo "Parent #1 does not exist.\n";
    }
} catch (Throwable $e) {
    echo "✗ parents query: " . $e->getMessage() . "\n";
}

echo "\nDone. Delete this file after use.\n";
