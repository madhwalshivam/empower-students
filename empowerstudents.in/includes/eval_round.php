<?php
/**
 * eval_round.php — supports "Start fresh evaluation" feature.
 *
 * Behavior: HARD DELETE all assessments + expert_report_orders for the child
 * when reset is called. current_round stays at 1 (it's not used for filtering
 * anymore since data is gone). Schema columns + triggers are kept harmless
 * for forward compatibility.
 */
require_once __DIR__ . '/db.php';

function ensure_eval_round_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // Add current_round to children (default 1, kept for forward compat)
        $cols = db()->query("PRAGMA table_info(children)")->fetchAll();
        if (!in_array('current_round', array_column($cols, 'name'), true)) {
            db()->exec("ALTER TABLE children ADD COLUMN current_round INTEGER DEFAULT 1");
        }

        // Add evaluation_round to assessments (kept for backward compat)
        $cols = db()->query("PRAGMA table_info(assessments)")->fetchAll();
        if (!in_array('evaluation_round', array_column($cols, 'name'), true)) {
            db()->exec("ALTER TABLE assessments ADD COLUMN evaluation_round INTEGER DEFAULT 1");
        }

        // Add evaluation_round to expert_report_orders if table exists
        try {
            $cols = db()->query("PRAGMA table_info(expert_report_orders)")->fetchAll();
            if ($cols && !in_array('evaluation_round', array_column($cols, 'name'), true)) {
                db()->exec("ALTER TABLE expert_report_orders ADD COLUMN evaluation_round INTEGER DEFAULT 1");
            }
        } catch (Throwable $e) { /* table may not exist */ }

        // Drop old triggers if they exist (we no longer rely on rounds)
        db()->exec("DROP TRIGGER IF EXISTS trg_assessments_set_round");
        db()->exec("DROP TRIGGER IF EXISTS trg_expert_orders_set_round");

        // Force everything to round 1
        db()->exec("UPDATE assessments SET evaluation_round = 1 WHERE evaluation_round IS NULL OR evaluation_round != 1");
        try {
            db()->exec("UPDATE expert_report_orders SET evaluation_round = 1 WHERE evaluation_round IS NULL OR evaluation_round != 1");
        } catch (Throwable $e) { /* table may not exist */ }
        db()->exec("UPDATE children SET current_round = 1 WHERE current_round IS NULL OR current_round != 1");

    } catch (Throwable $e) {
        error_log('ensure_eval_round_schema: ' . $e->getMessage());
    }
}

/**
 * Returns 1 always now — round filtering is no longer used because reset deletes data.
 * Function kept for compatibility with code that calls it.
 */
function current_evaluation_round(int $child_id): int {
    ensure_eval_round_schema();
    return 1;
}

/**
 * HARD DELETE: wipes all assessments + expert_report_orders for this child.
 * After this call, /child.php shows zero done modules and no expert report.
 *
 * Returns 1 on success, 0 on failure (not owner / not found).
 */
function reset_evaluation_round(int $parent_id, int $child_id): int {
    ensure_eval_round_schema();

    // Verify ownership
    $st = db()->prepare("SELECT id FROM children WHERE id = ? AND parent_id = ?");
    $st->execute([$child_id, $parent_id]);
    if (!$st->fetchColumn()) return 0;

    try {
        db()->beginTransaction();

        // Wipe all assessments for this child (audio_recordings cascade via FK)
        db()->prepare("DELETE FROM assessments WHERE child_id = ?")->execute([$child_id]);

        // Wipe all expert report orders for this child
        try {
            db()->prepare("DELETE FROM expert_report_orders WHERE child_id = ? AND parent_id = ?")
               ->execute([$child_id, $parent_id]);
        } catch (Throwable $e) { /* table may not exist */ }

        // Wipe per-child reports table (legacy AI report cache, if any)
        try {
            db()->prepare("DELETE FROM reports WHERE child_id = ?")->execute([$child_id]);
        } catch (Throwable $e) { /* may not exist */ }

        // Reset round counter to 1
        db()->prepare("UPDATE children SET current_round = 1 WHERE id = ?")->execute([$child_id]);

        db()->commit();
        return 1;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('reset_evaluation_round: ' . $e->getMessage());
        return 0;
    }
}
