<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claude.php';
require_once __DIR__ . '/../includes/wallet.php';
// Soft-load catalogue alias bridge so legacy modules don't double-charge
// parents who paid for the catalogue version (e.g. mod_speech_language)
// or have an active Care Pack.
if (file_exists(__DIR__ . '/../includes/catalogue_alias.php')) {
    require_once __DIR__ . '/../includes/catalogue_alias.php';
}
if (file_exists(__DIR__ . '/../includes/catalogue.php')) {
    require_once __DIR__ . '/../includes/catalogue.php';
}

function module_require_child() {
    require_parent();
    $child = child_for_parent((int)($_GET['cid'] ?? $_POST['cid'] ?? 0));
    if (!$child) { header('Location: /dashboard.php'); exit; }
    return $child;
}

/**
 * Confirm the parent has enough credits BEFORE the user invests time in a
 * module. Modules call this at top after `module_require_child()`. If
 * insufficient, redirects to /wallet.php with a clear message and does not
 * return.
 */
function module_require_credits(string $service_key) {
    $price = wallet_service_price($service_key);
    if ($price === null || $price === 0) return; // free / unknown -> let through
    $p = current_parent();
    $bal = (int)($p['credits'] ?? 0);
    if ($bal < $price) {
        $_SESSION['flash_error'] = "This module costs {$price} credits — you have {$bal}. Top up to continue.";
        header('Location: /wallet.php?need=' . $price);
        exit;
    }
}

function start_or_resume_assessment($child_id, $module, $age_band) {
    // If a 'done' row exists we still create a new attempt so re-do is supported.
    $st = db()->prepare("SELECT * FROM assessments WHERE child_id = ? AND module = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $st->execute([$child_id, $module]);
    $row = $st->fetch();
    if ($row) return $row;
    db()->prepare("INSERT INTO assessments (child_id, module, age_band, status) VALUES (?, ?, ?, 'in_progress')")
        ->execute([$child_id, $module, $age_band]);
    $id = (int) db()->lastInsertId();
    return ['id' => $id, 'child_id' => $child_id, 'module' => $module, 'age_band' => $age_band, 'status' => 'in_progress', 'raw_json' => null];
}

function finalize_assessment($assessment_id, $score, $level, $ai_summary, $flags, $raw) {
    db()->prepare("UPDATE assessments
                   SET status='done', score=?, level_reached=?, ai_summary=?, flags=?, raw_json=?, completed_at=CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$score, $level, $ai_summary, json_encode($flags), json_encode($raw), $assessment_id]);

    // Charge the parent for this completed module — idempotent by (service, assessment_id)
    $a = db()->query("SELECT a.module, a.child_id, c.parent_id FROM assessments a
                      JOIN children c ON c.id = a.child_id
                      WHERE a.id = " . (int)$assessment_id)->fetch();
    if ($a && !empty($a['parent_id'])) {
        // Catalogue alias: if parent owns the catalogue equivalent
        // (e.g. mod_speech_language for legacy 'speech'), skip the charge —
        // they've already paid via the catalogue.
        $skip_charge = function_exists('catalogue_owns_alias')
            ? catalogue_owns_alias((int)$a['parent_id'], (string)$a['module'])
            : false;
        if (!$skip_charge) {
            wallet_charge_for_service((int)$a['parent_id'], $a['module'], (int)$assessment_id);
        }

        // If this parent was referred by someone, flag the referrer's row
        // as eligible (they've completed at least 1 child evaluation).
        if (file_exists(__DIR__ . '/../includes/referral.php')) {
            require_once __DIR__ . '/../includes/referral.php';
            try { maybe_mark_referral_complete((int)$a['parent_id']); } catch (Throwable $e) {}
        }
    }

    // Catalogue-aware redirect: if the parent reached this module via
    // /catalogue.php (signalled by a session marker we set when they
    // clicked "Start assessment" on /module.php), send them back to the
    // module page's Report tab — that's where their report belongs in the
    // catalogue UX.
    //
    // We exit() here so any header()/Location call later in the per-module
    // file (e.g. speech.php's old "redirect to /child.php") never fires.
    if ($a && !empty($_SESSION['catalogue_assessment_return'])) {
        $ret = $_SESSION['catalogue_assessment_return'];
        // Single-use — don't bleed into the next module
        unset($_SESSION['catalogue_assessment_return']);
        // Sanity: the marker should match the legacy module that just finished.
        // If parent navigates away mid-flow and finishes a different module,
        // we just use the marker anyway (it's their declared return target).
        if (is_array($ret) && !empty($ret['url'])) {
            header('Location: ' . $ret['url']);
            exit;
        }
    }
}

function module_layout_open($child, $module_title) {
    global $page_title; $page_title = $module_title;
    require __DIR__ . '/../includes/header.php';
    echo '<a href="/child.php?id=' . (int)$child['id'] . '" class="text-sm text-indigo-600 hover:underline">&larr; Back to ' . e($child['name']) . '</a>';
    // Show the price banner for this module + current balance
    $mkey = strtolower(str_replace(' ', '_', strtolower($module_title)));
    // Try to derive module key from current script for accuracy
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $price = wallet_service_price($script);
    $bal   = (int)((current_parent() ?? [])['credits'] ?? 0);
    if ($price !== null) {
        echo '<div class="mt-2 mb-3 inline-flex items-center gap-2 text-xs bg-indigo-50 text-indigo-800 border border-indigo-100 rounded-full px-3 py-1">';
        echo '<span>This module: <strong>' . (int)$price . ' credits</strong></span>';
        echo '<span class="text-indigo-300">·</span>';
        echo '<span>You have <strong>' . $bal . '</strong></span>';
        echo '<a href="/wallet.php" class="ml-2 underline">Top up</a>';
        echo '</div>';
    }
    echo '<h1 class="text-2xl sm:text-3xl font-bold mt-3 mb-6">' . e($module_title) . '</h1>';
}
function module_layout_close() {
    require __DIR__ . '/../includes/footer.php';
}
