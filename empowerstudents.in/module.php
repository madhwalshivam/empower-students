<?php
/**
 * /module.php — single-module detail page.
 *
 * BUILD: 2026-04-30-r17  (ISAA module support: paid -> in_progress -> submitted flow with partner-conducted assessment)
 *
 * Pre-purchase: hero + free sample + price + CTA to buy.
 * Post-purchase: tabbed hub (regular module) OR contents grid (bundle).
 *
 * Query params: ?key=<service_key>&cid=<child_id>
 *               &action=buy                 — process buy (POST only)
 *               &action=claim_free          — claim 1 free module (POST or GET-after-login)
 *               &action=toggle_activity     — toggle daily checkbox (POST only) [legacy]
 *               &action=start_session       — start adaptive practice session (POST only)
 *               &action=submit_session      — auto-grade + save (POST only)
 *               &action=discard_session     — discard un-submitted session (POST only)
 *               &session=<id>               — which session to render in the task panel
 *               &tab=today|plan|...         — pick tab
 */
define('MODULE_PHP_BUILD', '2026-04-30-r17');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/claude.php';
require_once __DIR__ . '/includes/wallet.php';
if (file_exists(__DIR__ . '/includes/markdown.php')) {
    require_once __DIR__ . '/includes/markdown.php';
}
if (file_exists(__DIR__ . '/includes/daily_tasks.php')) {
    require_once __DIR__ . '/includes/daily_tasks.php';
}

$service_key = trim((string)($_GET['key'] ?? ''));
if ($service_key === '') { header('Location: /catalogue.php'); exit; }

$meta = module_meta($service_key);
if (!$meta) { header('Location: /catalogue.php'); exit; }

$page_title = $meta['label'] . ' — Empower Students';

$parent = current_parent();
$child  = null;
$age    = null;
$cid    = (int)($_GET['cid'] ?? 0);

if ($parent) {
    if ($cid > 0) {
        $child = child_for_parent($cid);
    } else {
        $st = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC LIMIT 1");
        $st->execute([(int)$parent['id']]);
        $child = $st->fetch() ?: null;
    }
    if ($child) {
        $age = calc_age_years($child['dob']);
        $cid = (int)$child['id'];
    }
}

$owned = ($parent && $child) ? module_owns((int)$parent['id'], (int)$child['id'], $service_key) : false;

// ─── GET-based claim_free (post-login redirect lands here) ───
// When a logged-out parent clicks "Pick this free" on homepage, we route them
// to /login.php with ?next=/module.php?key=...&action=claim_free. After they
// log in, they come back here as a GET. Run the claim flow once.
if (($_GET['action'] ?? '') === 'claim_free' && $parent && $child &&
    !parent_has_used_free_pick((int)$parent['id']) &&
    in_array($service_key, free_pick_module_keys(), true)) {
    $r = claim_free_pick((int)$parent['id'], (int)$child['id'], $service_key);
    if ($r['ok']) {
        $_SESSION['flash_ok'] = '🎁 Free module unlocked! Your assessment, AI report and 3 advice questions are free.';
    } else {
        $_SESSION['flash_error'] = $r['message'] ?? 'Could not unlock free module.';
    }
    header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid);
    exit;
}

// ─── POST: process buy ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'buy') {
        if (!$parent) { header('Location: /login.php?next=' . urlencode("/module.php?key={$service_key}&cid={$cid}")); exit; }
        if (!$child)  { $_SESSION['flash_error'] = 'Please add a child first.'; header('Location: /add_child.php'); exit; }

        $price = (int)$meta['price'];
        $bal   = wallet_balance((int)$parent['id']);
        if ($bal < $price) {
            $_SESSION['flash_error'] = "This module costs ₹{$price} — your wallet has ₹{$bal}. Top up to continue.";
            header('Location: /wallet.php?need=' . $price);
            exit;
        }

        // Charge: ref_id = child_id so a parent can buy the same module for
        // multiple children separately (idempotency on (service, child)).
        $r = wallet_charge_for_service((int)$parent['id'], $service_key, $cid);

        if ($r['status'] === 'charged' || $r['status'] === 'already_charged') {
            // Handle bundles: expand to member modules
            $bundle_keys = pack_member_keys($service_key);
            if (!empty($bundle_keys) && $bundle_keys[0] !== 'choice') {
                // Auto-grant ownership of bundle members by writing a 0-amount
                // ledger row — keeps audit trail clean (price = 0, but service_key
                // present so module_owns() returns true).
                foreach ($bundle_keys as $member_key) {
                    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                                         WHERE parent_id = ? AND service_key = ? AND ref_id = ? AND amount <= 0");
                    $st->execute([(int)$parent['id'], $member_key, $cid]);
                    if ((int)$st->fetchColumn() === 0) {
                        wallet_post((int)$parent['id'], 0, $member_key, $cid,
                                    "Included in {$service_key}", 'system');
                    }
                }
            }

            // Handle consult packs: grant balance
            if ($service_key === 'consult_pack_5')  consult_grant((int)$parent['id'], 5,  'consult_pack_5 purchase');
            if ($service_key === 'consult_pack_15') consult_grant((int)$parent['id'], 15, 'consult_pack_15 purchase');

            // ISAA: create the pending assessment row, auto-assign to registering partner if any
            if ($service_key === 'mod_isaa_assessment') {
                try {
                    // Find the partner who registered this child (if any)
                    $cst = db()->prepare("SELECT registered_by_partner_id FROM children WHERE id = ?");
                    $cst->execute([$cid]);
                    $reg_partner = $cst->fetchColumn();
                    $reg_partner = ($reg_partner !== false && $reg_partner !== null) ? (int)$reg_partner : null;

                    // Create assessment row only if one doesn't already exist (idempotent)
                    $exst = db()->prepare("SELECT id FROM isaa_assessments
                                           WHERE child_id = ? AND status IN ('paid','in_progress','submitted')");
                    $exst->execute([$cid]);
                    if (!$exst->fetchColumn()) {
                        db()->prepare("INSERT INTO isaa_assessments
                            (child_id, parent_id, partner_id, status, paid_at)
                            VALUES (?, ?, ?, 'paid', CURRENT_TIMESTAMP)")
                           ->execute([$cid, (int)$parent['id'], $reg_partner]);
                    }
                } catch (Throwable $e) {
                    error_log('[isaa create on buy] ' . $e->getMessage());
                }
            }

            // Grant included free consults for this module
            // (these are not added to consult_balance — consult_free_remaining
            // computes them from service_meta minus already-used.)

            $_SESSION['flash_ok'] = 'Purchase complete. Your module is unlocked.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid);
            exit;
        }
        $_SESSION['flash_error'] = $r['message'] ?? 'Could not complete purchase.';
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid);
        exit;
    }

    if ($action === 'claim_free') {
        // Lifetime-once free module pick. Parent picks one of 8 from homepage.
        if (!$parent) {
            // Send to login, then bring them back here to complete the claim
            $_SESSION['flash_ok'] = 'Sign in to claim your free module — we\'ll bring you right back.';
            $back = '/module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&action=claim_free';
            header('Location: /login.php?next=' . urlencode($back));
            exit;
        }
        if (!$child) {
            $_SESSION['flash_error'] = 'Add a child profile to begin.';
            header('Location: /add_child.php?next=' . urlencode("/module.php?key={$service_key}&cid={$cid}"));
            exit;
        }
        if (parent_has_used_free_pick((int)$parent['id'])) {
            $already = parent_free_pick_key((int)$parent['id']);
            if ($already === $service_key) {
                $_SESSION['flash_ok'] = 'You\'ve already unlocked this as your free module.';
            } else {
                $existing_meta = $already ? module_meta($already) : null;
                $name = $existing_meta['label'] ?? $already;
                $_SESSION['flash_error'] = "You've already used your free pick on \"{$name}\". One free module per parent.";
            }
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid);
            exit;
        }

        $r = claim_free_pick((int)$parent['id'], (int)$child['id'], $service_key);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = '🎁 Free module unlocked! Your assessment, AI report and 3 advice questions are free. The 12-week plan is ₹499 to add later.';
        } else {
            $_SESSION['flash_error'] = $r['message'] ?? 'Could not unlock free module.';
        }
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid);
        exit;
    }

    if ($action === 'toggle_activity') {
        // Mark a single (week, day, item) as done — or undo if already done.
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        if (!$owned)             { header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid); exit; }

        $week_n   = max(1, min(52, (int)($_POST['week_n']   ?? 0)));
        $day_idx  = max(0, min(6,  (int)($_POST['day_idx']  ?? -1)));
        $item_idx = max(0, min(1,  (int)($_POST['item_idx'] ?? -1)));

        if ($day_idx < 0 || $item_idx < 0 || $week_n < 1) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan'); exit;
        }

        // Free-tier preview: only Week 1, days 0-2 are tickable.
        if (module_owned_via_free_pick((int)$parent['id'], $service_key)) {
            if ($week_n > 1 || $day_idx > 2) {
                $_SESSION['flash_error'] = 'This day is part of the full plan. Unlock the full ₹' . number_format((int)$meta['price']) . ' module to track all days.';
                header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
                exit;
            }
        }

        // Validate week_n is within plan's weeks
        $plan_row = module_plan((int)$child['id'], $service_key);
        if (!$plan_row || $week_n > (int)$plan_row['weeks']) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan'); exit;
        }

        // Toggle: if exists, delete it; else insert.
        $st = db()->prepare("SELECT id FROM plan_activity_completions
                             WHERE child_id = ? AND service_key = ? AND week_n = ? AND day_idx = ? AND item_idx = ?");
        $st->execute([(int)$child['id'], $service_key, $week_n, $day_idx, $item_idx]);
        $existing = $st->fetchColumn();

        if ($existing) {
            db()->prepare("DELETE FROM plan_activity_completions WHERE id = ?")->execute([(int)$existing]);
        } else {
            db()->prepare("INSERT INTO plan_activity_completions
                           (child_id, service_key, week_n, day_idx, item_idx)
                           VALUES (?, ?, ?, ?, ?)")
               ->execute([(int)$child['id'], $service_key, $week_n, $day_idx, $item_idx]);
        }

        // Bring them back, scrolled to the right week if back_anchor is set
        $anchor = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['back_anchor'] ?? ''));
        $back = '/module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan'
              . ($anchor ? '#' . $anchor : '');
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'start_session') {
        // Create a fresh session header. The first question is generated lazily on render.
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        if (!$owned)             { header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid); exit; }

        $is_free = function_exists('module_owned_via_free_pick') &&
                   module_owned_via_free_pick((int)$parent['id'], $service_key);
        if ($is_free) {
            $st = db()->prepare("SELECT COUNT(*) FROM plan_daily_tasks
                                 WHERE child_id = ? AND service_key = ? AND status = 'submitted'");
            $st->execute([(int)$child['id'], $service_key]);
            if ((int)$st->fetchColumn() >= 1) {
                $_SESSION['flash_error'] = 'Free pick includes 1 practice session. Unlock the full module for ₹' . number_format((int)$meta['price']) . ' to continue.';
                header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
                exit;
            }
        }

        // Reuse in-progress session if any
        $existing = function_exists('in_progress_session')
            ? in_progress_session((int)$child['id'], $service_key) : null;
        if ($existing) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid
                   . '&tab=plan&session=' . (int)$existing['id'] . '#tasks');
            exit;
        }

        if (!$is_free && function_exists('has_session_today') && has_session_today((int)$child['id'], $service_key)) {
            $_SESSION['flash_ok'] = 'Today\'s session is already done. Come back tomorrow!';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }

        $age_yrs = (float) calc_age_years($child['dob']);
        $picker  = pick_current_skill((int)$child['id'], $service_key, $age_yrs);
        $skill   = $picker['skill'] ?? null;
        if (!$skill) {
            $_SESSION['flash_error'] = 'Skill curriculum not yet available for this module.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }

        $sess_id = create_session((int)$child['id'], $service_key, $skill, age_bucket($age_yrs));
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid
               . '&tab=plan&session=' . $sess_id . '#tasks');
        exit;
    }

    if ($action === 'answer_current') {
        // Record a single answer. State machine decides next move.
        if (!$parent || !$child) { header('Location: /login.php'); exit; }

        $sess_id      = (int)($_POST['session_id'] ?? 0);
        $q_id         = (int)($_POST['question_id'] ?? 0);
        $picked_idx   = (int)($_POST['picked'] ?? -1);
        $time_seconds = max(0, min(3600, (int)($_POST['time_seconds'] ?? 0)));
        if ($sess_id <= 0 || $q_id <= 0 || $picked_idx < 0) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }

        $result = record_answer($sess_id, (int)$child['id'], $q_id, $picked_idx, $time_seconds);

        // If session ended via mastery/max — generate the trick (don't block redirect)
        if ($result['action'] === 'end_session') {
            try {
                generate_trick_for_session($sess_id, $child, $meta);
            } catch (Throwable $e) {
                error_log('[trick gen] ' . $e->getMessage());
            }
        }

        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid
               . '&tab=plan&session=' . $sess_id . '#tasks');
        exit;
    }

    if ($action === 'end_session_now') {
        // Manual end (child gives up or finishes early)
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        $sess_id = (int)($_POST['session_id'] ?? 0);
        if ($sess_id > 0) {
            end_session_manually($sess_id, (int)$child['id']);
            try {
                generate_trick_for_session($sess_id, $child, $meta);
            } catch (Throwable $e) {
                error_log('[trick gen manual] ' . $e->getMessage());
            }
        }
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid
               . '&tab=plan&session=' . $sess_id . '#tasks');
        exit;
    }

    if ($action === 'discard_session') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        $sess_id = (int)($_POST['session_id'] ?? 0);
        if ($sess_id > 0) {
            db()->prepare("DELETE FROM plan_daily_tasks
                           WHERE id = ? AND child_id = ? AND status != 'submitted'")
               ->execute([$sess_id, (int)$child['id']]);
        }
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
        exit;
    }


    if ($action === 'generate_plan') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        if (!$owned) { $_SESSION['flash_error'] = 'Buy this module first.'; header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid); exit; }

        // Free-tier owners ARE allowed to generate — they get a preview view (week 1 days 0-2).
        // Server-side enforcement of preview-only happens in the toggle_activity handler.

        $weeks = max(1, (int)$meta['plan_weeks']);
        if ($weeks <= 0) $weeks = 4;

        // Pull most recent assessment summary if available — alias-aware so legacy
        // 'speech' rows still feed into the 'mod_speech_language' plan prompt.
        $alias_keys = function_exists('legacy_keys_for_catalogue')
            ? legacy_keys_for_catalogue($service_key)
            : [$service_key];
        $ph = implode(',', array_fill(0, count($alias_keys), '?'));
        $st = db()->prepare("SELECT ai_summary, score, level_reached, raw_json FROM assessments
                             WHERE child_id = ? AND module IN ($ph) AND status = 'done'
                             ORDER BY id DESC LIMIT 1");
        $st->execute(array_merge([(int)$child['id']], $alias_keys));
        $a = $st->fetch();
        $assess_text = $a ? trim((string)$a['ai_summary']) : '';

        $sys = "You are a child-development specialist creating a {$weeks}-week home action plan.
Tone: warm, practical, Indian context. Hindi-friendly explanations welcome where useful.
Output a JSON object with this exact shape:
{
  \"weeks\": [
    { \"n\": 1, \"focus\": \"...\", \"daily\": [\"5-min activity 1\", \"5-min activity 2\"], \"weekly_goal\": \"...\" },
    ...
  ],
  \"caregiver_tip\": \"one short paragraph for the parent\",
  \"red_flags\": [\"signs that this plan is not enough — see a specialist\"]
}
Each week's 'daily' array must have exactly 2 items. Plan must be specific to the child below.";

        $module_label = $meta['label'];
        $user = "Module: {$module_label}\n"
              . "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs"
              . ($child['gender'] ? ", " . $child['gender'] : "")
              . ($child['mother_tongue'] ? ", mother tongue: " . $child['mother_tongue'] : "")
              . ($child['diagnosis'] ? "\nKnown diagnosis: " . $child['diagnosis'] : "")
              . "\nAssessment summary: " . ($assess_text !== '' ? $assess_text : '(no assessment yet — write a starter plan from typical patterns at this age)')
              . "\n\nReturn ONLY the JSON object, no preamble.";

        // Capture errors so we can surface real reason if it fails.
        // claude_json returns null on parse failure or empty response.
        $j = claude_json($sys, $user, 2000, 0.4);

        if (!$j || !isset($j['weeks']) || empty($j['weeks'])) {
            // Diagnose by retrying once with raw chat call (helps if it's a JSON parse issue)
            $raw = claude_chat($sys . "\n\nReturn ONLY valid minified JSON. No prose, no code fences.",
                              [['role' => 'user', 'content' => $user]], 2000, 0.4);
            $detail = '';
            if ($raw === '') {
                $detail = ' (no response from AI — likely API key/quota or model issue)';
                error_log('[generate_plan] claude_chat returned empty for service_key=' . $service_key);
            } else {
                $detail = ' (AI returned non-JSON: ' . substr($raw, 0, 80) . '...)';
                error_log('[generate_plan] non-json response: ' . substr($raw, 0, 400));
            }
            $_SESSION['flash_error'] = 'Plan generation failed.' . $detail . ' Please try again.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }

        // Compose markdown for human display
        $md = "# Your " . $weeks . "-week plan for " . $child['name'] . "\n\n";
        $md .= "**Module:** " . $module_label . "\n\n";
        foreach ($j['weeks'] as $w) {
            $md .= "## Week " . (int)($w['n'] ?? 0) . " — " . ($w['focus'] ?? '') . "\n";
            if (!empty($w['daily']) && is_array($w['daily'])) {
                foreach ($w['daily'] as $d) { $md .= "- " . $d . "\n"; }
            }
            if (!empty($w['weekly_goal'])) {
                $md .= "\n**Weekly goal:** " . $w['weekly_goal'] . "\n";
            }
            $md .= "\n";
        }
        if (!empty($j['caregiver_tip'])) {
            $md .= "## A note for you\n" . $j['caregiver_tip'] . "\n\n";
        }
        if (!empty($j['red_flags']) && is_array($j['red_flags'])) {
            $md .= "## When to seek a specialist\n";
            foreach ($j['red_flags'] as $rf) { $md .= "- " . $rf . "\n"; }
        }

        module_plan_save((int)$child['id'], $service_key, $md, json_encode($j, JSON_UNESCAPED_UNICODE), $weeks);
        $_SESSION['flash_ok'] = 'Your plan is ready.';
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
        exit;
    }

    if ($action === 'ask_consult') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        if (!$owned) { $_SESSION['flash_error'] = 'Buy this module first.'; header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid); exit; }

        $question = trim((string)($_POST['question'] ?? ''));
        if ($question === '') {
            $_SESSION['flash_error'] = 'Please type a question first.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=consult');
            exit;
        }
        // Cap question length defensively
        if (mb_strlen($question) > 800) $question = mb_substr($question, 0, 800);

        $charge = consult_consume((int)$parent['id'], (int)$child['id'], $service_key);
        if (!$charge['ok']) {
            $_SESSION['flash_error'] = 'Out of consults. Buy a pack: 5 for ₹199 or 15 for ₹499.';
            header('Location: /catalogue.php?g=consult');
            exit;
        }

        $sys = "You are a paediatric expert answering a parent's specific question scoped to the '" . $meta['label'] . "' module. "
             . "Tone: warm, calm, Indian context, concrete steps. 150-250 words. "
             . "If the question is outside the scope of this module, say so briefly and suggest the right module. "
             . "Never give medication advice. End with one specific action the parent can try TODAY.";

        $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs"
              . ($child['mother_tongue'] ? ", mother tongue " . $child['mother_tongue'] : '')
              . ($child['diagnosis'] ? "\nKnown diagnosis: " . $child['diagnosis'] : '')
              . "\n\nParent's question:\n" . $question;

        $answer = claude_chat($sys, [['role' => 'user', 'content' => $user]], 600, 0.5);
        if ($answer === '') $answer = 'Sorry, the consult engine is busy right now. Please try again in a minute (your consult was not deducted).';

        // If the answer indicates failure, refund. Heuristic check.
        if (stripos($answer, 'busy right now') !== false && $charge['paid_from'] === 'pack') {
            // Refund 1 pack consult
            db()->prepare("UPDATE consult_balance SET balance = balance + 1 WHERE parent_id = ?")
                ->execute([(int)$parent['id']]);
        } else {
            consult_log((int)$parent['id'], (int)$child['id'], $service_key, $question, $answer, $charge['paid_from']);
        }

        $_SESSION['flash_ok'] = $charge['message'];
        $_SESSION['last_consult_q'] = $question;
        $_SESSION['last_consult_a'] = $answer;
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=consult');
        exit;
    }

    if ($action === 'translate_report') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        // Find the most recent done assessment under any aliased module key
        $alias_keys = function_exists('legacy_keys_for_catalogue')
            ? legacy_keys_for_catalogue($service_key)
            : [$service_key];
        $placeholders = implode(',', array_fill(0, count($alias_keys), '?'));
        $st = db()->prepare("SELECT * FROM assessments
                             WHERE child_id = ? AND module IN ($placeholders) AND status = 'done'
                             ORDER BY id DESC LIMIT 1");
        $st->execute(array_merge([(int)$child['id']], $alias_keys));
        $a = $st->fetch();

        if (!$a || empty($a['ai_summary'])) {
            $_SESSION['flash_error'] = 'No report to translate.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report');
            exit;
        }
        // If translation already cached, just go back — toggle will pick it up
        if (!empty($a['ai_summary_hi'])) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report&lang=hi');
            exit;
        }

        $sys = "You are translating a child-development report from English to Hindi for an Indian parent. "
             . "Use clear, warm, parent-friendly Hindi (not literary or overly formal). "
             . "Keep clinical terms in English when no common Hindi equivalent exists (e.g. ADHD, dyslexia, SLP, milestones). "
             . "Preserve paragraph breaks. Do not summarise — translate the full text. "
             . "Output ONLY the Hindi translation, no preamble, no markdown.";
        $hi = claude_chat($sys, [['role' => 'user', 'content' => (string)$a['ai_summary']]], 1500, 0.3);
        if ($hi === '') {
            $_SESSION['flash_error'] = 'Translation engine is busy — please try again in a minute.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report');
            exit;
        }
        db()->prepare("UPDATE assessments SET ai_summary_hi = ? WHERE id = ?")
           ->execute([$hi, (int)$a['id']]);

        $_SESSION['flash_ok'] = 'हिंदी अनुवाद तैयार है।';
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report&lang=hi');
        exit;
    }

    if ($action === 'translate_plan') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        $st = db()->prepare("SELECT * FROM module_plans WHERE child_id = ? AND service_key = ?");
        $st->execute([(int)$child['id'], $service_key]);
        $plan = $st->fetch();
        if (!$plan || empty($plan['plan_md'])) {
            $_SESSION['flash_error'] = 'No plan to translate. Generate one first.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }
        if (!empty($plan['plan_md_hi'])) {
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan&lang=hi');
            exit;
        }

        $sys = "You are translating a child-development plan from English to Hindi for an Indian parent. "
             . "Use clear, warm, parent-friendly Hindi (not literary or overly formal). "
             . "Keep clinical/professional terms in English when no common Hindi equivalent exists (e.g. ADHD, BMI, SLP, milestones). "
             . "PRESERVE the markdown structure exactly: headings (#, ##), tables (| col | col |), bullet lists (- item), bold (**text**), italics (*text*). "
             . "Do not translate weekday names in tables — keep Mon/Tue/Wed/etc. in English so the table stays readable. "
             . "Output ONLY the translated markdown. No preamble, no fences.";
        $hi = claude_chat($sys, [['role' => 'user', 'content' => (string)$plan['plan_md']]], 3000, 0.3);
        if ($hi === '') {
            $_SESSION['flash_error'] = 'Translation engine is busy — please try again.';
            header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan');
            exit;
        }
        db()->prepare("UPDATE module_plans SET plan_md_hi = ? WHERE id = ?")
           ->execute([$hi, (int)$plan['id']]);

        $_SESSION['flash_ok'] = 'योजना का अनुवाद तैयार है।';
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=plan&lang=hi');
        exit;
    }

    if ($action === 'save_daily_log') {
        if (!$parent || !$child) { header('Location: /login.php'); exit; }
        // Pull this module's allowed field keys so we only persist whitelisted fields
        $st = db()->prepare("SELECT field_key, field_type FROM module_log_fields WHERE service_key = ?");
        $st->execute([$service_key]);
        $fields = $st->fetchAll();
        $payload_for_module = [];
        foreach ($fields as $f) {
            $name = 'field_' . $f['field_key'];
            if (!isset($_POST[$name]) || $_POST[$name] === '') continue;
            $v = (string)$_POST[$name];
            // Light type coercion
            if ($f['field_type'] === 'minutes' || $f['field_type'] === 'count' || $f['field_type'] === 'likert05') {
                $v = (string)max(0, min(999, (int)$v));
            } else {
                $v = mb_substr(trim($v), 0, 500);
            }
            $payload_for_module[$f['field_key']] = $v;
        }

        $today = date('Y-m-d');

        // Read existing row (if any) to merge other modules' fields rather than overwrite
        $st = db()->prepare("SELECT * FROM daily_logs WHERE child_id = ? AND log_date = ?");
        $st->execute([(int)$child['id'], $today]);
        $existing = $st->fetch();

        $merged = [];
        if ($existing && !empty($existing['module_fields_json'])) {
            $j = json_decode((string)$existing['module_fields_json'], true);
            if (is_array($j)) $merged = $j;
        }
        $merged[$service_key] = $payload_for_module;
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            db()->prepare("UPDATE daily_logs SET module_fields_json = ? WHERE id = ?")
               ->execute([$json, (int)$existing['id']]);
        } else {
            db()->prepare("INSERT INTO daily_logs (child_id, log_date, module_fields_json) VALUES (?, ?, ?)")
               ->execute([(int)$child['id'], $today, $json]);
        }

        $_SESSION['flash_ok'] = 'Saved today\'s log.';
        header('Location: /module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=daily_log');
        exit;
    }
}

// ─── Render ───
$valid_tabs = ['today', 'overview', 'assessment', 'report', 'plan', 'daily_log', 'consult'];
$tab = (string)($_GET['tab'] ?? '');
if (!in_array($tab, $valid_tabs, true)) {
    // Smart default: if parent owns the module, land them on Today.
    // Otherwise show the Overview / pre-purchase view.
    $tab = $owned ? 'today' : 'overview';
}

require __DIR__ . '/includes/header.php';
if (function_exists('md_css')) echo md_css();

// Flash messages
if (!empty($_SESSION['flash_ok'])) {
    echo '<div class="max-w-4xl mx-auto px-4 mt-4 mb-2"><div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm">' . e($_SESSION['flash_ok']) . '</div></div>';
    unset($_SESSION['flash_ok']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="max-w-4xl mx-auto px-4 mt-4 mb-2"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm">' . e($_SESSION['flash_error']) . '</div></div>';
    unset($_SESSION['flash_error']);
}
?>

<style>
  .mod-tab {
    padding: 0.5rem 1rem;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 500;
    color: rgb(71, 85, 105);
    background: rgb(241, 245, 249);
    text-decoration: none;
    border: 1px solid transparent;
  }
  .mod-tab:hover { background: rgb(226, 232, 240); }
  .mod-tab.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
  }
  /* Daily log: pill labels light up when their hidden radio is :checked */
  .dl-pill { transition: background-color 0.15s, border-color 0.15s, color 0.15s; }
  .dl-pill:has(input:checked) {
    background: rgb(238, 242, 255) !important;
    border-color: rgb(165, 180, 252) !important;
    color: rgb(67, 56, 202) !important;
  }
  .dl-rating:has(input:checked) {
    background: rgb(254, 243, 199) !important;
    border-color: rgb(252, 211, 77) !important;
    color: rgb(133, 77, 14) !important;
  }
  .line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>

<div class="max-w-4xl mx-auto px-4 py-6">

  <!-- Hero -->
  <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">
    <a href="/catalogue.php<?= $cid ? '?cid=' . $cid : '' ?>" class="text-sm text-indigo-600 hover:underline">&larr; All modules</a>

    <div class="flex flex-wrap items-start gap-4 mt-3">
      <div class="text-5xl"><?= e($meta['icon'] ?: '📦') ?></div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap mb-1">
          <span class="cat-tier-badge inline-block text-xs font-semibold px-2 py-0.5 rounded-full border <?= e(tier_badge_class($meta['tier'] ?? '')) ?>">
            <?= e(tier_label($meta['tier'] ?? '')) ?>
          </span>
          <?php if ($owned): ?>
            <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full">✓ Owned</span>
          <?php endif; ?>
        </div>
        <h1 class="text-2xl font-bold text-slate-900"><?= e($meta['label']) ?></h1>
        <p class="text-slate-600 mt-1"><?= e($meta['short_desc'] ?: '') ?></p>
      </div>
      <div class="text-right">
        <div class="text-3xl font-bold text-slate-900">₹<?= number_format((int)$meta['price']) ?></div>
        <?php if ((int)$meta['plan_weeks'] > 0): ?>
          <div class="text-xs text-slate-500"><?= (int)$meta['plan_weeks'] ?>-week plan included</div>
        <?php endif; ?>
        <?php if ((int)$meta['free_consults_included'] > 0): ?>
          <div class="text-xs text-slate-500"><?= (int)$meta['free_consults_included'] ?> free AI consults</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($child): ?>
      <div class="mt-4 text-sm text-slate-600 bg-slate-50 rounded-lg px-3 py-2 inline-block">
        For <strong><?= e($child['name']) ?></strong> (<?= number_format((float)$age, 1) ?> yrs)
      </div>
    <?php endif; ?>
  </div>

  <?php
  $is_pack = ($meta['catalogue_group'] ?? '') === 'pack';
  ?>

  <?php if ($is_pack && $owned):
      // ─────────────────────────────────────────────────────────────
      // PACK VIEW — bundle contents grid. Packs aren't a single module;
      // they're a container that unlocks N catalogue modules. Show the
      // unlocked modules as cards with status, not a tabbed module hub.
      // ─────────────────────────────────────────────────────────────
      $member_keys = pack_member_keys($service_key);
      // Filter out the special "choice" token (for Starter Pack — pick-1 type)
      $member_keys = array_filter($member_keys, function ($k) { return $k !== 'choice'; });

      // Pull each member's metadata + activity status
      $members = [];
      foreach ($member_keys as $mkey) {
          $m_meta = module_meta($mkey);
          if (!$m_meta) continue;
          $alias_keys = function_exists('legacy_keys_for_catalogue')
              ? legacy_keys_for_catalogue($mkey)
              : [$mkey];
          $alias_ph = implode(',', array_fill(0, count($alias_keys), '?'));

          // Last assessment
          $st = db()->prepare("SELECT id, completed_at, score FROM assessments
                               WHERE child_id = ? AND module IN ($alias_ph) AND status = 'done'
                               ORDER BY id DESC LIMIT 1");
          $st->execute(array_merge([(int)$child['id']], $alias_keys));
          $last_a = $st->fetch();

          // Plan exists?
          $st = db()->prepare("SELECT started_at, weeks FROM module_plans WHERE child_id = ? AND service_key = ?");
          $st->execute([(int)$child['id'], $mkey]);
          $plan = $st->fetch();

          $members[] = [
              'meta'      => $m_meta,
              'last_a'    => $last_a,
              'plan'      => $plan,
              'is_partial' => (int)($m_meta['assessment_ready'] ?? 1) === 0,
          ];
      }
  ?>

  <div class="space-y-5">

    <!-- Bundle hero recap -->
    <div class="bg-gradient-to-br from-amber-50 via-orange-50 to-rose-50 border border-amber-200 rounded-2xl p-5">
      <div class="flex items-start gap-3">
        <div class="text-3xl"><?= e($meta['icon'] ?? '🎁') ?></div>
        <div class="flex-1">
          <h2 class="text-lg font-bold text-slate-900 mb-1"><?= e($meta['label']) ?></h2>
          <p class="text-sm text-slate-700"><?= e($meta['short_desc']) ?></p>
          <p class="text-xs text-slate-500 mt-2"><?= count($members) ?> module<?= count($members) === 1 ? '' : 's' ?> unlocked for <strong><?= e($child['name']) ?></strong> · No double-charge — already covered by your bundle.</p>
        </div>
      </div>
    </div>

    <!-- Member modules grid -->
    <h3 class="text-base font-semibold text-slate-900 mt-6 mb-3">📦 Your unlocked modules</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <?php foreach ($members as $row):
          $m = $row['meta'];
          $mkey = $m['service_key'];
          $href = '/module.php?key=' . urlencode($mkey) . '&cid=' . $cid;
      ?>
        <a href="<?= e($href) ?>" class="block bg-white border border-slate-200 rounded-2xl p-4 hover:border-indigo-300 hover:shadow-sm transition">
          <div class="flex items-start gap-3 mb-2">
            <div class="text-2xl shrink-0"><?= e($m['icon'] ?? '📘') ?></div>
            <div class="flex-1 min-w-0">
              <h4 class="text-sm font-semibold text-slate-900 leading-tight mb-0.5"><?= e($m['label']) ?></h4>
              <p class="text-xs text-slate-500 line-clamp-2"><?= e($m['short_desc']) ?></p>
            </div>
            <?php if ($row['is_partial']): ?>
              <span class="text-[10px] px-2 py-0.5 bg-amber-100 text-amber-800 border border-amber-200 rounded-full whitespace-nowrap">soon</span>
            <?php endif; ?>
          </div>

          <!-- Status row -->
          <div class="flex flex-wrap gap-1.5 mt-3 text-[11px]">
            <?php if ($row['last_a']): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full">
                ✓ Assessed <?= $row['last_a']['score'] !== null ? '· ' . number_format((float)$row['last_a']['score'], 0) . '%' : '' ?>
              </span>
            <?php elseif (!$row['is_partial']): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full">
                📝 Take assessment
              </span>
            <?php endif; ?>

            <?php if ($row['plan']):
                $weeks = max(1, (int)$row['plan']['weeks']);
                $start = strtotime($row['plan']['started_at']);
                $w = min($weeks, max(1, (int) floor((time() - $start) / (7 * 86400)) + 1));
            ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full">
                🗓️ Wk <?= $w ?>/<?= $weeks ?>
              </span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Footnote -->
    <div class="mt-6 text-xs text-slate-500 text-center">
      💡 Each module has its own assessment, AI report, plan and daily log. Click any module above to begin.
    </div>

  </div>

  <?php
  // Stop here for packs — skip the regular tab dispatch entirely
  require __DIR__ . '/includes/footer.php';
  exit;
  endif; // is_pack && owned
  ?>

  <?php if ($owned && $service_key === 'mod_isaa_assessment'):
      // ─────────────────────────────────────────────────────────────
      // Special render for ISAA: no tabs, just status + report link
      // ─────────────────────────────────────────────────────────────
      $st = db()->prepare("SELECT a.*, p.name AS partner_name, p.qualification AS partner_qual,
                                  p.whatsapp AS partner_whatsapp
                           FROM isaa_assessments a
                           LEFT JOIN partners p ON p.id = a.partner_id
                           WHERE a.child_id = ? AND a.parent_id = ?
                             AND a.status IN ('paid','in_progress','submitted')
                           ORDER BY a.id DESC LIMIT 1");
      $st->execute([(int)$child['id'], (int)$parent['id']]);
      $isaa = $st->fetch();
  ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
        <h2 class="text-xl font-bold text-slate-900 mb-2">🧠 ISAA Autism Assessment</h2>

        <?php if (!$isaa): ?>
          <!-- Edge case: owned but no assessment row — should be auto-created on buy -->
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-900">
            Your assessment was paid for but the assessment record is being set up. Please refresh in a minute, or contact admin.
          </div>

        <?php elseif ($isaa['status'] === 'submitted'): ?>
          <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4 mb-4">
            <p class="text-sm font-semibold text-emerald-900 mb-1">✓ Assessment complete</p>
            <p class="text-xs text-emerald-800">
              Conducted by <?= e($isaa['partner_name'] ?? 'a partner clinician') ?>
              <?= $isaa['partner_qual'] ? ' (' . e($isaa['partner_qual']) . ')' : '' ?>
              · Submitted <?= e(substr((string)$isaa['submitted_at'], 0, 10)) ?>.
            </p>
          </div>
          <a href="/partner-isaa-view.php?id=<?= (int)$isaa['id'] ?>"
             class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 inline-block">
            📋 View full report
          </a>

        <?php elseif ($isaa['status'] === 'in_progress'): ?>
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-amber-900 mb-1">📝 Assessment in progress</p>
            <p class="text-xs text-amber-800">
              <?= $isaa['partner_name'] ? e($isaa['partner_name']) : 'Your assigned clinician' ?> has started the assessment.
              You'll see the full report here once it's submitted.
            </p>
          </div>

        <?php else: /* status = 'paid' */ ?>
          <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-slate-900 mb-2">✓ Payment received</p>
            <?php if ($isaa['partner_name']): ?>
              <p class="text-sm text-slate-700 mb-2">
                Your assessment has been assigned to <strong><?= e($isaa['partner_name']) ?></strong>
                <?= $isaa['partner_qual'] ? ' (' . e($isaa['partner_qual']) . ')' : '' ?>.
              </p>
              <?php if (!empty($isaa['partner_whatsapp'])): ?>
                <p class="text-xs text-slate-600">
                  Please reach out on WhatsApp to schedule:
                  <a href="https://wa.me/<?= e(preg_replace('/\D/', '', (string)$isaa['partner_whatsapp'])) ?>"
                     target="_blank" class="text-emerald-700 font-semibold hover:underline">
                    📲 <?= e($isaa['partner_whatsapp']) ?>
                  </a>
                </p>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-sm text-slate-700">
                We're assigning a partner clinician to conduct your assessment. Please contact
                <a href="https://wa.me/919311696923" class="text-emerald-700 font-semibold hover:underline">
                  Dr Jha at +91-9311696923
                </a> on WhatsApp to schedule.
              </p>
            <?php endif; ?>
            <p class="text-xs text-slate-500 mt-3 italic">
              The assessment takes ~30 minutes via video or in-person. You'll see the full parent report here once your clinician submits it.
            </p>
          </div>
        <?php endif; ?>

        <details class="mt-6 text-sm text-slate-700">
          <summary class="cursor-pointer font-semibold hover:text-indigo-600">About ISAA</summary>
          <div class="mt-2 space-y-2 text-xs text-slate-600">
            <p>The Indian Scale for Assessment of Autism (ISAA) is a 40-item clinical scale developed by NIMH (National Institute for the Mentally Handicapped, Govt. of India) for autism diagnosis.</p>
            <p>It covers 6 domains — Social Relationship, Emotional Responsiveness, Speech-Language, Behaviour, Sensory, and Cognitive — and produces a total score (40-200), classification (Normal / Mild / Moderate / Severe), and a disability percentage.</p>
            <p class="text-amber-700">⚠️ ISAA is a screening / assessment aid; it supplements but does not replace a formal medical or developmental diagnosis.</p>
          </div>
        </details>
      </div>
  <?php
      require __DIR__ . '/includes/footer.php';
      exit;
  endif;
  ?>

  <?php if ($owned && !$is_pack): ?>
    <!-- Tab navigation (post-purchase) — modules only, not packs -->
    <div class="flex flex-wrap gap-2 mb-6">
      <?php
      $base_q = '?key=' . urlencode($service_key) . ($cid ? '&cid=' . $cid : '') . '&tab=';
      // Free-tier owners see Plan tab as a locked upgrade
      $free_tier = $parent ? module_owned_via_free_pick((int)$parent['id'], $service_key) : false;
      $tabs = [
          'today'      => '🌟 Today',
          'overview'   => '📋 Overview',
          'assessment' => '📝 Assessment',
          'report'     => '📊 Report',
          'plan'       => $free_tier ? '🔒 Plan' : '🗓️ Plan',
          'daily_log'  => '📔 Daily Log',
          'consult'    => '💬 Ask AI',
      ];
      foreach ($tabs as $k => $label):
      ?>
        <a class="mod-tab <?= $tab === $k ? 'active' : '' ?>" href="/module.php<?= e($base_q . $k) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: today — landing for owned-module parents
  // Combines: this week's plan slice · last log entry · assessment score · quick consult
  // ─────────────────────────────────────────────────────────────
  if ($tab === 'today' && $owned):
      // Pull latest report (alias-aware)
      $alias_keys = function_exists('legacy_keys_for_catalogue')
          ? legacy_keys_for_catalogue($service_key)
          : [$service_key];
      $ph = implode(',', array_fill(0, count($alias_keys), '?'));
      $st = db()->prepare("SELECT * FROM assessments
                           WHERE child_id = ? AND module IN ($ph) AND status = 'done'
                           ORDER BY id DESC LIMIT 1");
      $st->execute(array_merge([(int)$child['id']], $alias_keys));
      $latest_report = $st->fetch();

      // Pull plan + figure out which week we're on
      $plan = module_plan((int)$child['id'], $service_key);
      $current_week = null;
      $current_week_data = null;
      if ($plan && !empty($plan['plan_json'])) {
          $started_at = strtotime($plan['started_at']);
          $weeks_elapsed = max(1, (int) floor((time() - $started_at) / (7 * 86400)) + 1);
          $weeks_total   = max(1, (int)$plan['weeks']);
          $current_week  = min($weeks_elapsed, $weeks_total);
          $j = json_decode($plan['plan_json'], true);
          if (is_array($j) && !empty($j['weeks'])) {
              foreach ($j['weeks'] as $w) {
                  if ((int)($w['n'] ?? 0) === $current_week) { $current_week_data = $w; break; }
              }
          }
      }

      // Pull most recent daily log row that has fields for this module
      $last_log = null;
      try {
          $st = db()->prepare("SELECT * FROM daily_logs
                               WHERE child_id = ? AND module_fields_json IS NOT NULL
                               ORDER BY log_date DESC LIMIT 1");
          $st->execute([(int)$child['id']]);
          $last_log = $st->fetch() ?: null;
      } catch (Throwable $e) { /* daily_logs table may not exist on bare installs */ }

      // Module log fields for this module
      $st = db()->prepare("SELECT * FROM module_log_fields WHERE service_key = ? ORDER BY sort_order ASC");
      $st->execute([$service_key]);
      $log_fields = $st->fetchAll();
  ?>

  <div class="space-y-4">

    <!-- Greeting card -->
    <div class="bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-2xl p-5">
      <p class="text-xs uppercase tracking-wider text-indigo-700 font-semibold mb-1">Today, <?= e(date('l, j M')) ?></p>
      <h2 class="text-xl font-bold text-slate-900 mb-1">Welcome back, <?= e($child['name']) ?>'s parent 👋</h2>
      <p class="text-sm text-slate-600">Here's what's on for <?= e($meta['label']) ?>.</p>
    </div>

    <?php if ($free_tier ?? false): ?>
      <!-- Free-tier banner: parent picked this as their free module -->
      <div class="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl p-4 flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div class="flex-1">
          <p class="text-sm font-semibold text-emerald-900 mb-0.5">🎁 You're on the free tier for this module</p>
          <p class="text-xs text-emerald-800">Assessment, AI report and 3 advice questions are free. Plan + full consults unlock with the ₹<?= number_format((int)$meta['price']) ?> upgrade.</p>
        </div>
        <a href="<?= e($base_q . 'plan') ?>" class="text-xs font-semibold text-emerald-900 underline whitespace-nowrap">View upgrade →</a>
      </div>
    <?php endif; ?>

    <!-- This week's slice from the plan -->
    <?php if ($current_week_data): ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
          <h3 class="text-lg font-semibold">📅 This week — Week <?= (int)$current_week ?> of <?= (int)$plan['weeks'] ?></h3>
          <span class="text-xs text-slate-500"><?= e($current_week_data['focus'] ?? '') ?></span>
        </div>
        <?php if (!empty($current_week_data['daily']) && is_array($current_week_data['daily'])): ?>
          <ul class="space-y-2 mb-3">
            <?php foreach ($current_week_data['daily'] as $i => $d): ?>
              <li class="flex items-start gap-2 text-sm">
                <span class="text-emerald-600 mt-0.5">●</span>
                <span class="text-slate-700"><?= e($d) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($current_week_data['weekly_goal'])): ?>
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-900">
            <strong>Goal this week:</strong> <?= e($current_week_data['weekly_goal']) ?>
          </div>
        <?php endif; ?>
        <a href="<?= e($base_q . 'plan') ?>" class="text-xs text-indigo-600 hover:underline mt-3 inline-block">See full plan →</a>
      </div>
    <?php elseif ($plan): ?>
      <!-- Plan exists but week index couldn't be derived -->
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <h3 class="text-lg font-semibold mb-2">📅 Your plan</h3>
        <p class="text-sm text-slate-600 mb-3">You have a <?= (int)$plan['weeks'] ?>-week plan running. Open it for week-by-week activities.</p>
        <a href="<?= e($base_q . 'plan') ?>" class="inline-block brand-grad text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90">Open plan →</a>
      </div>
    <?php else: ?>
      <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-5 text-center">
        <h3 class="text-base font-semibold text-slate-700 mb-1">No plan yet</h3>
        <p class="text-sm text-slate-500 mb-3">Generate a 12-week home action plan personalised for <?= e($child['name']) ?>.</p>
        <a href="<?= e($base_q . 'plan') ?>" class="inline-block brand-grad text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90">Create plan →</a>
      </div>
    <?php endif; ?>

    <!-- Quick log -->
    <?php if (!empty($log_fields)): ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
          <h3 class="text-lg font-semibold">📔 Today's quick log</h3>
          <?php if ($last_log): ?>
            <span class="text-xs text-slate-500">Last logged: <?= e(date('j M', strtotime($last_log['log_date']))) ?></span>
          <?php endif; ?>
        </div>
        <p class="text-sm text-slate-600 mb-3">Tap below to log <?= e($child['name']) ?>'s progress in 30 seconds.</p>
        <a href="<?= e($base_q . 'daily_log') ?>" class="inline-flex items-center gap-2 brand-grad text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90">
          ✏️ Log today's progress
        </a>
      </div>
    <?php endif; ?>

    <!-- Assessment summary chip -->
    <?php if ($latest_report): ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="flex items-baseline justify-between mb-2 flex-wrap gap-2">
          <h3 class="text-lg font-semibold">📊 Latest assessment</h3>
          <span class="text-xs text-slate-500"><?= e(date('j M Y', strtotime($latest_report['completed_at'] ?: $latest_report['created_at']))) ?></span>
        </div>
        <?php if ($latest_report['score'] !== null): ?>
          <div class="text-2xl font-bold text-indigo-700 mb-1"><?= number_format((float)$latest_report['score'], 1) ?>%</div>
        <?php endif; ?>
        <?php
        // Strip markdown chars for the preview so we don't show "**bold**" mid-sentence
        $preview = preg_replace('/[*#_`]+/', '', (string)$latest_report['ai_summary']);
        $preview = trim(preg_replace('/\s+/', ' ', $preview));
        ?>
        <p class="text-sm text-slate-700 line-clamp-3"><?= e(mb_substr($preview, 0, 220)) ?>…</p>
        <a href="<?= e($base_q . 'report') ?>" class="text-xs text-indigo-600 hover:underline mt-3 inline-block">Read full report →</a>
      </div>
    <?php else: ?>
      <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-5 text-center">
        <h3 class="text-base font-semibold text-slate-700 mb-1">No assessment yet</h3>
        <p class="text-sm text-slate-500 mb-3">Take the structured assessment to unlock a personalised report.</p>
        <a href="<?= e($base_q . 'assessment') ?>" class="inline-block brand-grad text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90">Start assessment →</a>
      </div>
    <?php endif; ?>

    <!-- Quick consult -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h3 class="text-lg font-semibold mb-2">💬 Have a question?</h3>
      <p class="text-sm text-slate-600 mb-3">
        Ask our AI a quick question scoped to <?= e($meta['label']) ?>.
        <?php $free_left = consult_free_remaining((int)$parent['id'], (int)$child['id'], $service_key); ?>
        <?php if ($free_left > 0): ?>
          <span class="text-emerald-700 font-semibold">(<?= $free_left ?> free included)</span>
        <?php endif; ?>
      </p>
      <a href="<?= e($base_q . 'consult') ?>" class="inline-block bg-slate-100 text-slate-800 text-sm font-semibold px-4 py-2 rounded-lg hover:bg-slate-200">
        Ask now →
      </a>
    </div>

  </div>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: overview (or pre-purchase view)
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'overview' || !$owned):
  ?>

  <div class="bg-white rounded-2xl border border-slate-200 p-6">

    <?php if ($is_pack):
        // Resolve pack members to show what's inside before purchase.
        $member_keys = pack_member_keys($service_key);
        $member_keys = array_filter($member_keys, function ($k) { return $k !== 'choice'; });
        $is_choice_pack = empty($member_keys); // Starter Pack: parent picks at checkout
        $pack_members = [];
        $individual_total = 0;
        foreach ($member_keys as $mkey) {
            $m_meta = module_meta($mkey);
            if (!$m_meta) continue;
            $pack_members[] = $m_meta;
            $individual_total += (int)$m_meta['price'];
        }
        $bundle_price = (int)$meta['price'];
        $you_save     = max(0, $individual_total - $bundle_price);
    ?>

      <h2 class="text-lg font-semibold mb-1">📦 What's inside this bundle</h2>
      <p class="text-sm text-slate-600 mb-5">
        <?php if ($is_choice_pack): ?>
          You'll pick which module to unlock at checkout — full ownership of any 1 Standard module + 30-day tracker included.
        <?php else: ?>
          One purchase unlocks all <?= count($pack_members) ?> modules below for <strong><?= e($child['name'] ?? 'your child') ?></strong>. Each module has its own assessment, AI report, plan and daily log — no double-charge.
        <?php endif; ?>
      </p>

      <?php if (!empty($pack_members)): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
          <?php foreach ($pack_members as $m):
              $m_partial = (int)($m['assessment_ready'] ?? 1) === 0;
          ?>
            <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-3">
              <div class="flex items-start gap-2.5">
                <span class="text-xl shrink-0"><?= e($m['icon'] ?? '📘') ?></span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-baseline justify-between gap-2 flex-wrap">
                    <h4 class="text-sm font-semibold text-slate-900"><?= e($m['label']) ?></h4>
                    <span class="text-xs text-slate-500 line-through">₹<?= number_format((int)$m['price']) ?></span>
                  </div>
                  <p class="text-xs text-slate-600 mt-1 line-clamp-2"><?= e($m['short_desc']) ?></p>
                  <?php if ($m_partial): ?>
                    <p class="text-[10px] text-amber-700 mt-1.5">⏳ Assessment launching soon · plan + AI consults available now</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Savings math -->
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6">
          <div class="flex items-baseline justify-between text-sm">
            <span class="text-slate-700">If bought separately:</span>
            <span class="text-slate-500 line-through">₹<?= number_format($individual_total) ?></span>
          </div>
          <div class="flex items-baseline justify-between text-sm mt-1">
            <span class="text-slate-700">Bundle price:</span>
            <span class="font-bold text-slate-900">₹<?= number_format($bundle_price) ?></span>
          </div>
          <?php if ($you_save > 0): ?>
            <div class="flex items-baseline justify-between mt-2 pt-2 border-t border-emerald-300">
              <span class="text-emerald-800 font-semibold">You save:</span>
              <span class="font-bold text-emerald-700">₹<?= number_format($you_save) ?> (<?= $individual_total > 0 ? round($you_save * 100 / $individual_total) : 0 ?>% off)</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h3 class="text-sm font-semibold text-slate-900 mb-2">Plus, for every module:</h3>
      <ul class="space-y-1.5 text-sm text-slate-700 mb-6">
        <li>✅ Structured assessment + AI-generated report with strengths, gaps, age benchmarks</li>
        <li>✅ Personalised week-by-week home action plan</li>
        <li>✅ Free AI consult questions scoped to that module</li>
        <li>✅ Daily log to track progress</li>
        <li>✅ Bilingual — English &amp; हिंदी</li>
      </ul>

    <?php else: /* Regular module pre-purchase view */ ?>
      <h2 class="text-lg font-semibold mb-3">What you'll get</h2>
      <ul class="space-y-2 text-sm text-slate-700 mb-6">
        <li>✅ A short, structured assessment (5–20 questions, depending on depth)</li>
        <li>✅ AI-generated report with strengths, gaps, age benchmarks</li>
        <?php if ((int)$meta['plan_weeks'] > 0): ?>
          <li>✅ A <?= (int)$meta['plan_weeks'] ?>-week personalised plan with daily 5-minute activities</li>
        <?php endif; ?>
        <?php if ((int)$meta['free_consults_included'] > 0): ?>
          <li>✅ <?= (int)$meta['free_consults_included'] ?> free AI consult questions scoped to this module</li>
        <?php endif; ?>
        <li>✅ Optional 30-day daily tracker (₹149 separate)</li>
        <li>✅ Bilingual — English &amp; हिंदी</li>
      </ul>

      <?php if (!empty($meta['sample_question'])): ?>
        <!-- Free sample question -->
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
          <div class="text-xs font-semibold uppercase tracking-wider text-amber-800 mb-2">Free sample question</div>
          <p class="text-base text-slate-800 mb-3"><?= e($meta['sample_question']) ?></p>
          <?php if (!empty($meta['sample_question_hi'])): ?>
            <p class="text-sm text-slate-600 italic mb-3"><?= e($meta['sample_question_hi']) ?></p>
          <?php endif; ?>
          <div class="flex gap-2 flex-wrap">
            <span class="text-xs px-3 py-1 bg-white border border-amber-200 rounded-full text-amber-700">Yes</span>
            <span class="text-xs px-3 py-1 bg-white border border-amber-200 rounded-full text-amber-700">Sometimes</span>
            <span class="text-xs px-3 py-1 bg-white border border-amber-200 rounded-full text-amber-700">No</span>
            <span class="text-xs px-3 py-1 bg-white border border-amber-200 rounded-full text-amber-700">Not sure</span>
          </div>
          <p class="text-xs text-slate-500 mt-3">The full assessment has <strong>more like this</strong>, scored by AI for a personalised report.</p>
        </div>
      <?php endif; ?>
    <?php endif; /* is_pack */ ?>

    <?php
    // Free-pick eligibility — only for non-pack modules in the curated list
    $free_eligible = !$is_pack
                  && in_array($service_key, free_pick_module_keys(), true)
                  && (!$parent || !parent_has_used_free_pick((int)$parent['id']));
    ?>

    <?php if (!$owned): ?>

      <?php if ($free_eligible): ?>
        <!-- Pick-me-free CTA: lifetime once per parent -->
        <form method="post" class="mb-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="claim_free">
          <button class="w-full sm:w-auto bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold px-8 py-3 rounded-xl hover:opacity-90 shadow">
            🎁 Pick this free — assessment + report + 3 advice
          </button>
          <p class="text-xs text-emerald-700 mt-2">
            ✓ One free module per parent (lifetime). Plan is part of the full ₹<?= number_format((int)$meta['price']) ?> module.
          </p>
        </form>

        <div class="text-xs text-slate-500 mb-3">— or —</div>
      <?php endif; ?>

      <!-- Buy CTA -->
      <form method="post" class="mt-1">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="buy">
        <button class="w-full sm:w-auto brand-grad text-white font-semibold px-8 py-3 rounded-xl hover:opacity-90 shadow">
          <?= $is_pack ? '🎁 Unlock bundle' : ($free_eligible ? 'Unlock full module' : 'Unlock') ?> for ₹<?= number_format((int)$meta['price']) ?>
        </button>
        <?php if (!$parent): ?>
          <p class="text-xs text-slate-500 mt-2">You'll be asked to log in first. New parents get 100 free credits on signup.</p>
        <?php elseif (!$child): ?>
          <p class="text-xs text-slate-500 mt-2">Add a child profile to begin.</p>
        <?php endif; ?>
      </form>

    <?php else: ?>
      <?php if ($free_tier ?? false): ?>
        <!-- Free-tier banner shown above tabs already; here a quick CTA -->
        <a href="/module.php?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=assessment"
           class="inline-block brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90">Start free assessment →</a>
      <?php else: ?>
        <a href="/module.php?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=assessment"
           class="inline-block brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90">Take the assessment →</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: assessment — link out to the existing /modules/<key>.php
  // For new modules without a built file yet, render a placeholder.
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'assessment'):

      // Map catalogue keys → existing /modules/*.php where one exists.
      // The reference implementation (mod_speech_language) uses the
      // existing speech.php; new keys will get their own file in a
      // follow-up round.
      $existing_module_map = [
          'mod_speech_language'    => '/modules/speech.php',     // ref impl: merged speech+spontaneous in next round
          'mod_behaviour_emotion'  => '/modules/behavior.php',
          'mod_developmental'      => '/modules/health.php',     // health.php has milestone screening
          'mod_math'               => '/modules/math.php',
          'mod_language'           => '/modules/language.php',
          'mod_general_awareness'  => '/modules/general_awareness.php',
          'mod_mind_power'         => '/modules/mind_power.php',
          'mod_special_talent'     => '/modules/special_talent.php',
          'mod_parenting'          => '/modules/parent_index.php',
          'mod_family_wellness'    => '/modules/diet.php',
      ];
      $assessment_url = $existing_module_map[$service_key] ?? null;

      // Stash the catalogue return URL so finalize_assessment() in
      // /modules/_common.php knows to send the parent back to the
      // catalogue Report tab instead of the legacy /child.php redirect.
      if ($assessment_url) {
          $_SESSION['catalogue_assessment_return'] = [
              'service_key' => $service_key,
              'url'         => '/module.php?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report',
          ];
      }
  ?>

  <div class="bg-white rounded-2xl border border-slate-200 p-6">
    <?php if ($assessment_url): ?>
      <h2 class="text-lg font-semibold mb-3">Ready when you are</h2>
      <p class="text-slate-600 mb-5">10–15 minutes. You can pause and come back. Bilingual (English + हिंदी).</p>
      <a href="<?= e($assessment_url) ?>?cid=<?= $cid ?>&from=catalogue" class="inline-block brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90">
        Start assessment →
      </a>
    <?php else: ?>
      <h2 class="text-lg font-semibold mb-3">This module's assessment is launching soon</h2>
      <p class="text-slate-600 mb-3">
        Your purchase is recorded. While the dedicated assessment for <strong><?= e($meta['label']) ?></strong>
        is being prepared, you can already use the <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=plan" class="text-indigo-600 hover:underline">AI plan</a>
        and <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=consult" class="text-indigo-600 hover:underline">AI consults</a>
        below.
      </p>
      <p class="text-xs text-slate-500">No double-charge — when the assessment ships, ownership transfers automatically.</p>
    <?php endif; ?>
  </div>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: report — most recent assessment summary
  // Looks up by both the catalogue key AND any legacy keys aliased
  // to it, so a 'speech' or 'spontaneous' assessment shows up when
  // viewing 'mod_speech_language'.
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'report'):
      $alias_keys = function_exists('legacy_keys_for_catalogue')
          ? legacy_keys_for_catalogue($service_key)
          : [$service_key];
      $placeholders = implode(',', array_fill(0, count($alias_keys), '?'));
      $st = db()->prepare("SELECT * FROM assessments
                           WHERE child_id = ? AND module IN ($placeholders) AND status = 'done'
                           ORDER BY id DESC LIMIT 1");
      $st->execute(array_merge([(int)$child['id']], $alias_keys));
      $a = $st->fetch();
  ?>

  <div class="bg-white rounded-2xl border border-slate-200 p-6">
    <?php if (!$a): ?>
      <h2 class="text-lg font-semibold mb-3">No report yet</h2>
      <p class="text-slate-600">Complete the <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=assessment" class="text-indigo-600 hover:underline">assessment</a> to generate your AI report.</p>
    <?php else:
        // Language selection — ?lang=hi or ?lang=en, default en
        $report_lang = ($_GET['lang'] ?? '') === 'hi' ? 'hi' : 'en';
        $hi_cached   = !empty($a['ai_summary_hi']);
        // If user asked for hi but we don't have a cached translation, fall back to en
        $shown_lang  = ($report_lang === 'hi' && $hi_cached) ? 'hi' : 'en';
        $shown_text  = $shown_lang === 'hi' ? (string)$a['ai_summary_hi'] : (string)$a['ai_summary'];
        $base_q      = '?key=' . urlencode($service_key) . '&cid=' . $cid . '&tab=report';
    ?>
      <div class="flex items-baseline justify-between flex-wrap gap-2 mb-4">
        <h2 class="text-lg font-semibold">
          <?= $shown_lang === 'hi' ? 'आपकी रिपोर्ट' : 'Your report' ?>
        </h2>
        <span class="text-xs text-slate-500">
          <?= $shown_lang === 'hi' ? 'तैयार हुई' : 'Generated' ?>
          <?= e(date('j M Y', strtotime($a['completed_at'] ?: $a['created_at']))) ?>
        </span>
      </div>

      <!-- Language toggle -->
      <div class="flex flex-wrap items-center gap-2 mb-4 text-xs">
        <?php if ($shown_lang === 'en'): ?>
          <?php if ($hi_cached): ?>
            <a href="<?= e($base_q . '&lang=hi') ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-amber-50 text-amber-800 border border-amber-200 rounded-full hover:bg-amber-100">
              🌐 हिंदी में देखें
            </a>
          <?php else: ?>
            <form method="post" class="m-0 inline-block">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="translate_report">
              <button class="inline-flex items-center gap-1 px-3 py-1 bg-amber-50 text-amber-800 border border-amber-200 rounded-full hover:bg-amber-100 cursor-pointer">
                🌐 हिंदी में अनुवाद करें (free)
              </button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <a href="<?= e($base_q . '&lang=en') ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-slate-100 text-slate-700 border border-slate-200 rounded-full hover:bg-slate-200">
            🌐 View in English
          </a>
        <?php endif; ?>
      </div>

      <?php if ($a['score'] !== null): ?>
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-4 text-sm text-indigo-900">
          <strong><?= $shown_lang === 'hi' ? 'स्कोर:' : 'Score:' ?></strong>
          <?= number_format((float)$a['score'], 1) ?>%
          <?php if (!empty($a['level_reached'])): ?>
            &nbsp;·&nbsp; <strong><?= $shown_lang === 'hi' ? 'आयु बैंड:' : 'Age band:' ?></strong> <?= e($a['level_reached']) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="md-content"<?= $shown_lang === 'hi' ? ' lang="hi"' : '' ?>><?= function_exists('md_render') ? md_render((string)$shown_text) : nl2br(e($shown_text)) ?></div>
    <?php endif; ?>
  </div>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: plan
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'plan'):
      $plan = module_plan((int)$child['id'], $service_key);
      $weeks = max(1, (int)$meta['plan_weeks']);

      // Adaptive practice helpers — defensively guarded so a missing daily_tasks.php
      // doesn't blank the page. If any helper is missing, $adaptive_ready=false and we
      // render the legacy "View original plan strategy" view.
      $adaptive_ready = function_exists('pick_current_skill')
                     && function_exists('curriculum_for')
                     && function_exists('is_skill_mastered')
                     && function_exists('age_bucket');

      $current_skill = null;
      $progress      = ['mastered' => 0, 'total' => 0];
      $progress_pct  = 0;
      $curriculum    = [];
      $age_yrs       = 0.0;
      $sess_id       = 0;
      $sess          = null;
      $in_progress   = null;
      $today_done    = false;
      $free_session_count = 0;
      $free_used_up  = false;

      if ($adaptive_ready) {
          try {
              $age_yrs = (float) calc_age_years($child['dob']);
              $picker  = pick_current_skill((int)$child['id'], $service_key, $age_yrs);
              $current_skill = $picker['skill'];
              $progress      = $picker['progress'];
              $progress_pct  = $progress['total'] > 0 ? round($progress['mastered'] * 100 / $progress['total']) : 0;
              $curriculum    = curriculum_for($service_key);

              $sess_id = (int)($_GET['session'] ?? 0);
              if ($sess_id > 0) {
                  $st = db()->prepare("SELECT * FROM plan_daily_tasks WHERE id = ? AND child_id = ?");
                  $st->execute([$sess_id, (int)$child['id']]);
                  $sess = $st->fetch() ?: null;
              }
              $in_progress = function_exists('in_progress_session')
                  ? in_progress_session((int)$child['id'], $service_key) : null;
              $today_done  = function_exists('has_session_today')
                  ? has_session_today((int)$child['id'], $service_key) : false;

              if ($free_tier ?? false) {
                  $st = db()->prepare("SELECT COUNT(*) FROM plan_daily_tasks
                                       WHERE child_id = ? AND service_key = ? AND status = 'submitted'");
                  $st->execute([(int)$child['id'], $service_key]);
                  $free_session_count = (int)$st->fetchColumn();
                  $free_used_up = $free_session_count >= 1;
              }
          } catch (Throwable $e) {
              error_log('[plan tab adaptive] ' . $e->getMessage());
              $adaptive_ready = false;
          }
      }
  ?>

  <div class="space-y-4">

    <?php if (!$plan): ?>
      <!-- No plan yet — show generate flow -->
      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-semibold mb-2">Generate your strategy plan</h2>
        <p class="text-slate-600 mb-4">
          A short personalised plan tied to <?= e($child['name']) ?>'s assessment. Once it's ready, your child can start adaptive daily practice — AI picks the right skill each time.
        </p>
        <form method="post" id="planGenForm" onsubmit="planGenStart(this); return true;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="generate_plan">
          <button id="planGenBtn" class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90">
            ✨ Generate my plan
          </button>
          <p class="text-xs text-slate-500 mt-2">This usually takes 15–30 seconds. Please don't close this tab.</p>
        </form>

        <!-- Reuse the same processing modal pattern -->
        <div id="planGenOverlay" class="hidden fixed inset-0 z-[200] bg-slate-900/70 backdrop-blur-sm flex items-center justify-center px-4">
          <div class="bg-white rounded-2xl shadow-2xl p-7 max-w-sm w-full text-center">
            <div class="mx-auto mb-4 relative" style="width:84px; height:84px;">
              <svg viewBox="0 0 50 50" class="absolute inset-0" style="width:84px; height:84px; animation: po-spin 1.6s linear infinite;">
                <defs>
                  <linearGradient id="poGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%"   stop-color="#4f46e5"/>
                    <stop offset="50%"  stop-color="#06b6d4"/>
                    <stop offset="100%" stop-color="#10b981"/>
                  </linearGradient>
                </defs>
                <circle cx="25" cy="25" r="22" fill="none" stroke="url(#poGrad)" stroke-width="3" stroke-linecap="round" stroke-dasharray="90 60" />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <img src="/assets/images/logo-small.png" alt="EmpowerStudents" style="width:48px; height:48px; border-radius:10px;">
              </div>
            </div>
            <h3 class="text-lg font-bold text-slate-900 mb-2">EmpowerStudents is crafting <?= e($child['name']) ?>'s plan…</h3>
            <p class="text-sm text-slate-600 mb-3">15–30 seconds. Please don't close this tab.</p>
          </div>
        </div>
        <style>@keyframes po-spin { to { transform: rotate(360deg); } }</style>
        <script>
        function planGenStart(form) {
          var btn = document.getElementById('planGenBtn');
          if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
          var ov = document.getElementById('planGenOverlay');
          if (ov) ov.classList.remove('hidden');
        }
        </script>
      </div>

    <?php elseif (!$adaptive_ready): ?>
      <!-- Adaptive system not ready (missing daily_tasks.php or schema migration didn't run) -->
      <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6">
        <h2 class="text-lg font-semibold mb-2 text-amber-900">⚠️ Adaptive practice is being set up</h2>
        <p class="text-amber-800 text-sm mb-3">
          The new adaptive practice system is being prepared for this module. In the meantime, you can review your strategy plan below.
        </p>
        <details class="mt-3">
          <summary class="cursor-pointer text-sm font-semibold text-indigo-700 hover:underline">View original plan strategy</summary>
          <div class="mt-3 md-content"><?= function_exists('md_render') ? md_render((string)$plan['plan_md']) : nl2br(e($plan['plan_md'])) ?></div>
        </details>
      </div>

    <?php elseif (empty($curriculum)): ?>
      <!-- Plan exists but no curriculum for this module yet -->
      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-semibold mb-2">Adaptive practice — coming soon for this module</h2>
        <p class="text-slate-600 text-sm">The skill curriculum for <?= e($meta['label']) ?> is being prepared. For now, you can review the strategy plan below.</p>
        <details class="mt-4">
          <summary class="cursor-pointer text-sm font-semibold text-indigo-600 hover:underline">View original plan strategy</summary>
          <div class="mt-3 md-content"><?= function_exists('md_render') ? md_render((string)$plan['plan_md']) : nl2br(e($plan['plan_md'])) ?></div>
        </details>
      </div>

    <?php else: ?>

      <!-- Progress strip: skills mastered -->
      <div class="bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-2xl p-4">
        <div class="flex items-baseline justify-between mb-2 flex-wrap gap-2">
          <h3 class="text-base font-bold text-slate-900">📚 <?= e($meta['label']) ?> — adaptive practice</h3>
          <span class="text-xs font-semibold text-indigo-700"><?= (int)$progress['mastered'] ?> / <?= (int)$progress['total'] ?> skills mastered</span>
        </div>
        <div class="h-2 bg-white rounded-full overflow-hidden border border-indigo-100">
          <div class="h-full brand-grad transition-all" style="width: <?= $progress_pct ?>%;"></div>
        </div>
      </div>

      <!-- Today's session card -->
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <?php if ($free_used_up): ?>
          <!-- Free tier: 1 session done, locked -->
          <div class="text-center py-3">
            <div class="text-4xl mb-2">🔒</div>
            <h3 class="text-lg font-bold text-slate-900 mb-2">Continue daily practice with the full module</h3>
            <p class="text-sm text-slate-600 max-w-md mx-auto mb-4">
              Your free pick of <strong><?= e($meta['label']) ?></strong> includes 1 practice session. Unlock the full module to keep going every day, with AI-adaptive skill progression.
            </p>
            <form method="post" class="m-0 inline-block">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="buy">
              <button class="brand-grad text-white font-semibold px-7 py-3 rounded-xl hover:opacity-90 shadow">
                🔓 Unlock full module · ₹<?= number_format((int)$meta['price']) ?>
              </button>
            </form>
          </div>

        <?php elseif ($current_skill && !$today_done && !$in_progress): ?>
          <!-- Fresh start: AI will pick the skill -->
          <div class="flex items-baseline justify-between mb-1 flex-wrap gap-2">
            <h3 class="text-lg font-bold text-slate-900">✨ Today's session</h3>
            <span class="text-xs text-slate-500"><?= e(date('l, j M')) ?></span>
          </div>
          <p class="text-sm text-slate-600 mb-1">
            Next skill for <?= e($child['name']) ?>:
            <span class="font-semibold text-indigo-700"><?= e($current_skill['skill_label']) ?></span>
          </p>
          <p class="text-xs text-slate-500 mb-4"><?= e($current_skill['skill_brief']) ?></p>

          <form method="post" id="sessionStartForm" onsubmit="taskGenStart(this)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="start_session">
            <button id="sessionStartBtn" class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 shadow">
              ▶ Start today's session
            </button>
            <p class="text-xs text-slate-500 mt-2">AI will create 4–5 practice questions. Takes ~15 seconds.</p>
          </form>

        <?php elseif ($in_progress && !$sess): ?>
          <!-- In-progress session — show "continue" -->
          <h3 class="text-lg font-bold text-slate-900 mb-2">📝 Continue your session</h3>
          <p class="text-sm text-slate-600 mb-4">You have an unfinished session — pick up where you left off, or discard it to start fresh tomorrow.</p>
          <div class="flex flex-wrap gap-2">
            <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=plan&session=<?= (int)$in_progress['id'] ?>#tasks"
               class="brand-grad text-white font-semibold px-5 py-2.5 rounded-lg hover:opacity-90">
              ↻ Continue session
            </a>
            <form method="post" class="m-0">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="discard_session">
              <input type="hidden" name="session_id" value="<?= (int)$in_progress['id'] ?>">
              <button class="text-xs text-slate-500 hover:text-rose-600 px-3 py-2.5">✕ Discard</button>
            </form>
          </div>

        <?php elseif ($today_done && !$sess): ?>
          <!-- Already done today — encourage them to come back -->
          <div class="text-center py-2">
            <div class="text-3xl mb-2">🎉</div>
            <h3 class="text-lg font-bold text-slate-900 mb-1">Today's session done!</h3>
            <p class="text-sm text-slate-600 mb-3">Come back tomorrow for the next skill in your ladder.</p>
            <?php
              // Show last submitted session details
              $st = db()->prepare("SELECT id, skill_id, score FROM plan_daily_tasks
                                   WHERE child_id = ? AND service_key = ? AND status = 'submitted'
                                   ORDER BY submitted_at DESC LIMIT 1");
              $st->execute([(int)$child['id'], $service_key]);
              $last = $st->fetch();
            ?>
            <?php if ($last): ?>
              <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=plan&session=<?= (int)$last['id'] ?>#tasks"
                 class="text-sm text-indigo-600 hover:underline">View today's results →</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="text-sm text-slate-500 italic">No skill available right now.</p>
        <?php endif; ?>
      </div>

      <!-- Skill ladder history strip -->
      <?php if (!$free_used_up): ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-5">
          <h3 class="text-base font-semibold mb-3">🪜 Skill ladder</h3>
          <div class="flex flex-wrap gap-1.5">
            <?php
            // Show 3 mastered before, current, 5 ahead
            $skill_index = -1;
            foreach ($curriculum as $i => $sk) {
                if ($current_skill && $sk['skill_id'] === $current_skill['skill_id']) $skill_index = $i;
            }
            if ($skill_index === -1) $skill_index = count($curriculum) - 1;
            $start = max(0, $skill_index - 3);
            $end   = min(count($curriculum) - 1, $skill_index + 5);
            for ($i = $start; $i <= $end; $i++):
                $sk = $curriculum[$i];
                $mastered = is_skill_mastered((int)$child['id'], $service_key, $sk['skill_id']);
                $is_current = ($i === $skill_index);
                // Last submitted-session score on this skill as target
                $rs = db()->prepare("SELECT score FROM plan_daily_tasks
                                     WHERE child_id = ? AND service_key = ? AND target_skill_id = ?
                                       AND status = 'submitted'
                                     ORDER BY submitted_at DESC LIMIT 1");
                $rs->execute([(int)$child['id'], $service_key, $sk['skill_id']]);
                $rs_score = $rs->fetchColumn();
                $last_score = ($rs_score !== false && $rs_score !== null) ? (int)$rs_score : null;
                $status_dot = $mastered ? '🟢' : ($is_current ? '🔵' : ($last_score !== null ? '🟡' : '⚪'));
                $cls = $is_current ? 'border-indigo-300 bg-indigo-50' : ($mastered ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-white');
            ?>
              <div class="text-xs px-2.5 py-1.5 rounded-lg border <?= e($cls) ?>" title="<?= e($sk['skill_brief']) ?>">
                <?= $status_dot ?> <?= e($sk['skill_label']) ?>
                <?php if ($is_current): ?>
                  <span class="text-[10px] text-indigo-700 font-semibold ml-1">(now)</span>
                <?php endif; ?>
                <?php if ($mastered): ?>
                  <span class="text-[10px] text-emerald-700 font-semibold ml-1">(mastered)</span>
                <?php elseif ($last_score !== null && !$is_current): ?>
                  <span class="text-[10px] text-amber-700 ml-1">(<?= $last_score ?>%)</span>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>
          <?php if ($skill_index < count($curriculum) - 1): ?>
            <p class="text-xs text-slate-500 mt-3">+ <?= count($curriculum) - $skill_index - 1 ?> more skills ahead in the ladder</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Active task panel — adaptive live session -->
      <?php if ($sess):
          // Lazy-generate the next question if there's no current unanswered one
          // and the session is still active.
          $current_q = current_unanswered_question((int)$sess['id']);
          if (!$current_q && $sess['status'] !== 'submitted') {
              try {
                  generate_and_save_next_question($child, $meta, $sess);
                  $current_q = current_unanswered_question((int)$sess['id']);
                  // Refresh session row in case state changed
                  $sess = get_session((int)$sess['id'], (int)$child['id']);
              } catch (Throwable $e) {
                  error_log('[gen next q] ' . $e->getMessage());
              }
          }

          $is_submitted   = ($sess['status'] === 'submitted');
          $sess_skill     = $sess['target_skill_id'] ? skill_by_id($service_key, (string)$sess['target_skill_id']) : null;
          $answered       = (int)$sess['questions_answered'];
          $correct        = (int)$sess['questions_correct'];
          $score          = $answered > 0 ? (int) round($correct * 100 / $answered) : 0;
          $threshold      = function_exists('speed_threshold_for_age_bucket')
              ? speed_threshold_for_age_bucket((string)$sess['age_bucket']) : 60;

          // For trick-test feedback on Q1
          $tests_trick    = ($current_q && (int)$current_q['tests_trick'] === 1);
          $prev_trick     = null;
          if ($tests_trick && !empty($sess['trick_from_prev_id'])) {
              $tst = db()->prepare("SELECT trick_md FROM plan_daily_tasks WHERE id = ?");
              $tst->execute([(int)$sess['trick_from_prev_id']]);
              $prev_trick = $tst->fetchColumn() ?: null;
          }
      ?>
        <?php if ($is_submitted): ?>
          <!-- ════════════════ SESSION ENDED — TRANSCRIPT + TRICK ════════════════ -->
          <div id="tasks" class="bg-gradient-to-br from-emerald-50/40 to-white border-2 border-emerald-200 rounded-2xl p-5">
            <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
              <h4 class="text-base font-bold text-emerald-900">
                <?php
                  switch ($sess['end_reason']) {
                    case 'mastery':       echo '🏆 Session complete — mastered!'; break;
                    case 'max_questions': echo '👍 Session complete'; break;
                    case 'manual':        echo '⏸ Session ended'; break;
                    default:              echo '✓ Session done';
                  }
                ?>
              </h4>
              <div class="flex items-center gap-3 text-sm">
                <span class="text-slate-600"><?= $correct ?>/<?= $answered ?> correct</span>
                <span class="font-bold <?= $score >= 80 ? 'text-emerald-700' : ($score >= 60 ? 'text-amber-700' : 'text-rose-700') ?>"><?= $score ?>%</span>
              </div>
            </div>

            <!-- Trick of the day (if generated) -->
            <?php if (!empty($sess['trick_md'])): ?>
              <div class="bg-amber-50 border border-amber-300 rounded-xl p-4 mb-4">
                <div class="flex items-baseline gap-2 mb-1">
                  <span class="text-lg">💡</span>
                  <h5 class="font-bold text-amber-900">Trick to remember</h5>
                </div>
                <div class="md-content text-sm text-amber-900">
                  <?= function_exists('md_render') ? md_render((string)$sess['trick_md']) : nl2br(e($sess['trick_md'])) ?>
                </div>
                <p class="text-xs text-amber-800 mt-2">
                  Try this in your copybook today. Tomorrow we'll start by checking if you've practiced it!
                </p>
              </div>
            <?php endif; ?>

            <!-- Full transcript -->
            <h5 class="font-semibold text-slate-700 mb-2 text-sm">📋 What happened in this session</h5>
            <div class="space-y-2">
              <?php
              $transcript = get_session_questions((int)$sess['id']);
              foreach ($transcript as $tq):
                  $opts = json_decode((string)$tq['options_json'], true) ?: [];
                  $picked  = (int)$tq['picked_idx'];
                  $cidx    = (int)$tq['correct_idx'];
                  $is_right = ((int)$tq['is_correct'] === 1);
                  $time = (int)$tq['time_seconds'];
                  $offset = (int)$tq['level_offset'];
                  $level_label = $offset === 0 ? 'target level'
                              : ($offset === -1 ? 'easier' : 'level ' . $offset);
              ?>
                <details class="border border-slate-200 rounded-lg p-2 bg-white text-sm">
                  <summary class="cursor-pointer flex items-baseline justify-between gap-2">
                    <span class="flex-1">
                      <span class="<?= $is_right ? 'text-emerald-700' : 'text-rose-700' ?> font-bold mr-1">
                        <?= $is_right ? '✓' : '✗' ?>
                      </span>
                      <span class="text-slate-700">Q<?= (int)$tq['seq'] ?>:</span>
                      <span class="text-slate-900"><?= e(mb_substr((string)$tq['q_text'], 0, 100)) ?><?= mb_strlen((string)$tq['q_text']) > 100 ? '…' : '' ?></span>
                    </span>
                    <span class="text-xs text-slate-500 whitespace-nowrap">
                      <?= $time ?>s · <?= e($level_label) ?>
                    </span>
                  </summary>
                  <div class="mt-2 pl-4 text-xs text-slate-600">
                    <p class="mb-1">Your answer: <strong><?= e($opts[$picked] ?? '?') ?></strong></p>
                    <?php if (!$is_right): ?>
                      <p class="mb-1">Correct: <strong class="text-emerald-700"><?= e($opts[$cidx] ?? '?') ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($tq['explain'])): ?>
                      <p class="mt-1 italic"><?= e($tq['explain']) ?></p>
                    <?php endif; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>

            <div class="mt-4 pt-3 border-t border-emerald-100 flex flex-wrap gap-2 items-center text-xs text-slate-500">
              <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=plan" class="hover:underline">close</a>
              <span class="ml-auto">
                <?php if ($sess['end_reason'] === 'mastery'): ?>
                  🎉 You mastered this skill today. Tomorrow's session will move you forward.
                <?php elseif ($sess['end_reason'] === 'max_questions'): ?>
                  Good practice today. Tomorrow we'll continue with the same skill.
                <?php else: ?>
                  Practice tomorrow to build on today's work.
                <?php endif; ?>
              </span>
            </div>
          </div>

        <?php elseif ($current_q):
            $opts = json_decode((string)$current_q['options_json'], true) ?: [];
        ?>
          <!-- ════════════════ LIVE QUESTION ════════════════ -->
          <div id="tasks" class="bg-gradient-to-br from-indigo-50/40 to-white border-2 border-indigo-200 rounded-2xl p-5">
            <!-- Header: skill + counters + timer -->
            <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
              <h4 class="text-base font-bold text-indigo-900">
                🎯 <?= e($sess_skill['skill_label'] ?? 'Practice') ?>
              </h4>
              <div class="flex items-center gap-3 text-xs">
                <span class="text-slate-500">Q <?= $answered + 1 ?></span>
                <?php if ((int)$current_q['level_offset'] !== 0): ?>
                  <span class="text-amber-700 font-semibold">
                    <?= (int)$current_q['level_offset'] === -1 ? 'easier' : 'easier (level ' . (int)$current_q['level_offset'] . ')' ?>
                  </span>
                <?php endif; ?>
                <?php if ((int)$sess['comfortable_streak'] > 0): ?>
                  <span class="text-emerald-700 font-semibold">🔥 <?= (int)$sess['comfortable_streak'] ?> in a row</span>
                <?php endif; ?>
                <span id="qTimer" class="text-slate-400 font-mono">0s</span>
              </div>
            </div>

            <!-- Trick reminder (only if Q1 tests trick) -->
            <?php if ($tests_trick && $prev_trick): ?>
              <div class="bg-amber-50 border border-amber-200 rounded-lg p-2 mb-3 text-xs text-amber-900">
                💡 <strong>Remember yesterday's trick?</strong> Try to use it on this question.
              </div>
            <?php endif; ?>

            <!-- The question -->
            <form method="post" id="qLiveForm" onsubmit="return submitLive(this)">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="answer_current">
              <input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
              <input type="hidden" name="question_id" value="<?= (int)$current_q['id'] ?>">
              <input type="hidden" name="time_seconds" id="qTimerInput" value="0">

              <div class="task-q border border-slate-200 rounded-lg p-4 bg-white mb-3">
                <p class="text-base font-semibold text-slate-900 mb-3"><?= e($current_q['q_text']) ?></p>
                <div class="space-y-1.5" id="qOptsBox">
                  <?php foreach ($opts as $oi => $opt): ?>
                    <label class="task-opt cursor-pointer" data-oi="<?= $oi ?>">
                      <input type="radio" name="picked" value="<?= $oi ?>" required
                             class="mr-2 task-opt-radio"
                             onchange="optPicked(this)">
                      <span><?= e($opt) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Result flash (hidden until answered, shown briefly before submit) -->
              <div id="qFlash" class="hidden text-center mb-3"></div>

              <div class="flex items-center justify-between gap-3">
                <button id="qNextBtn" disabled type="submit"
                        class="brand-grad text-white font-semibold px-6 py-2.5 rounded-lg hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                  Next →
                </button>
                <span class="text-xs text-slate-500">
                  Threshold: <?= $threshold ?>s · <?= $correct ?>/<?= $answered ?> right
                </span>
              </div>
            </form>

            <?php if ($answered >= 3): ?>
              <!-- Allow manual end after 3 questions -->
              <div class="mt-4 pt-3 border-t border-indigo-100 text-center">
                <form method="post" class="m-0 inline" onsubmit="return confirm('End session now?')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="end_session_now">
                  <input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
                  <button class="text-xs text-slate-500 hover:text-rose-600 underline">⏸ End session here</button>
                </form>
              </div>
            <?php endif; ?>
          </div>

          <script>
          (function() {
            // Per-question timer (resets each render since each question is its own page-load)
            var startTs = Date.now();
            var timerEl = document.getElementById('qTimer');
            var timerInput = document.getElementById('qTimerInput');
            var ti = setInterval(function() {
              var sec = Math.floor((Date.now() - startTs) / 1000);
              if (timerEl) timerEl.textContent = sec + 's';
              if (timerInput) timerInput.value = sec;
            }, 500);
            window._qTimerInterval = ti;
          })();

          function optPicked(input) {
            // Visual selected state + enable Next button
            var box = document.getElementById('qOptsBox');
            if (box) {
              var lbls = box.querySelectorAll('.task-opt');
              for (var i = 0; i < lbls.length; i++) lbls[i].classList.remove('task-opt-picked');
              input.parentNode.classList.add('task-opt-picked');
            }
            var btn = document.getElementById('qNextBtn');
            if (btn) btn.disabled = false;
          }

          function submitLive(form) {
            // Stop timer, finalize time
            if (window._qTimerInterval) clearInterval(window._qTimerInterval);
            // Show a brief result flash
            var picked = form.querySelector('input[name=picked]:checked');
            if (!picked) return false;
            var pickedIdx = parseInt(picked.value, 10);
            var correctIdx = <?= (int)$current_q['correct_idx'] ?>;
            var flash = document.getElementById('qFlash');
            var btn = document.getElementById('qNextBtn');
            if (btn) btn.disabled = true;

            // Disable all radios so child can't change after submitting
            var radios = form.querySelectorAll('input[name=picked]');
            for (var i = 0; i < radios.length; i++) radios[i].disabled = true;

            if (flash) {
              flash.classList.remove('hidden');
              if (pickedIdx === correctIdx) {
                flash.innerHTML = '<span class="text-emerald-700 font-bold text-lg">✓ Correct!</span>';
              } else {
                flash.innerHTML = '<span class="text-rose-700 font-bold text-lg">✗ Not quite</span>';
              }
            }
            // Auto-submit after 1.2s flash
            setTimeout(function() { form.submit(); }, 1200);
            return false;  // we handle submit via setTimeout
          }
          </script>

        <?php else: ?>
          <!-- Edge: session active but no current question and generation failed -->
          <div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 text-center">
            <p class="text-rose-800 mb-2">Couldn't load the next question. This sometimes happens if the AI service is busy.</p>
            <a href="?key=<?= urlencode($service_key) ?>&cid=<?= $cid ?>&tab=plan&session=<?= (int)$sess['id'] ?>#tasks"
               class="text-rose-700 underline text-sm">Try again</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Original strategy plan (collapsed by default) -->
      <details class="bg-white border border-slate-200 rounded-2xl p-4">
        <summary class="cursor-pointer text-sm font-semibold text-slate-700 hover:text-indigo-700">
          📄 View original strategy plan
        </summary>
        <div class="mt-3 md-content"><?= function_exists('md_render') ? md_render((string)$plan['plan_md']) : nl2br(e($plan['plan_md'])) ?></div>
      </details>

    <?php endif; ?>

    <!-- Task generation loading overlay (used by Start session button) -->
    <div id="taskGenOverlay" class="hidden fixed inset-0 z-[200] bg-slate-900/70 backdrop-blur-sm flex items-center justify-center px-4">
      <div class="bg-white rounded-2xl shadow-2xl p-7 max-w-sm w-full text-center">
        <!-- Brand-grad halo with logo + spinning ring -->
        <div class="mx-auto mb-4 relative" style="width:84px; height:84px;">
          <svg viewBox="0 0 50 50" class="absolute inset-0" style="width:84px; height:84px; animation: tg-spin 1.6s linear infinite;">
            <defs>
              <linearGradient id="tgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%"   stop-color="#4f46e5"/>
                <stop offset="50%"  stop-color="#06b6d4"/>
                <stop offset="100%" stop-color="#10b981"/>
              </linearGradient>
            </defs>
            <circle cx="25" cy="25" r="22" fill="none" stroke="url(#tgGrad)" stroke-width="3" stroke-linecap="round" stroke-dasharray="90 60" />
          </svg>
          <div class="absolute inset-0 flex items-center justify-center">
            <img src="/assets/images/logo-small.png" alt="EmpowerStudents" style="width:48px; height:48px; border-radius:10px;">
          </div>
        </div>
        <h3 class="text-lg font-bold text-slate-900 mb-2">EmpowerStudents is preparing your practice…</h3>
        <p class="text-sm text-slate-600 mb-3">Crafting fresh questions just for <?= e($child['name']) ?>. Takes 10–20 seconds.</p>
        <div class="mt-4 h-1 bg-slate-100 rounded-full overflow-hidden">
          <div id="taskGenBar" class="h-full brand-grad" style="width:0%; transition:width 0.5s ease;"></div>
        </div>
      </div>
    </div>
    <style>
      @keyframes tg-spin { to { transform: rotate(360deg); } }
      .task-opt {
        display: flex; align-items: center;
        padding: 9px 12px;
        border: 1.5px solid rgb(226, 232, 240);
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        color: rgb(51, 65, 85);
        background: white;
        transition: background-color 0.12s, border-color 0.12s;
      }
      .task-opt:hover { background: rgb(248, 250, 252); }
      .task-opt-picked { border-color: rgb(99, 102, 241); background: rgb(238, 242, 255); color: rgb(67, 56, 202); }
      .task-opt-correct { border-color: rgb(16, 185, 129); background: rgb(220, 252, 231); color: rgb(6, 78, 59); font-weight: 600; }
      .task-opt-wrong { border-color: rgb(244, 63, 94); background: rgb(255, 228, 230); color: rgb(159, 18, 57); }
    </style>
    <script>
    function taskGenStart(form) {
      var btn = form.querySelector('button[type=submit], button:not([type])');
      if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
      var ov = document.getElementById('taskGenOverlay');
      var bar = document.getElementById('taskGenBar');
      if (ov) ov.classList.remove('hidden');
      var pct = 5;
      var iv = setInterval(function() {
        pct = Math.min(95, pct + 4 + Math.random() * 4);
        if (bar) bar.style.width = pct + '%';
        if (pct >= 95) clearInterval(iv);
      }, 600);
      return true;
    }
    </script>
  </div>


  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: daily_log — quick log of THIS module's tracker fields
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'daily_log' && $owned):
      // Pull this module's log fields
      $st = db()->prepare("SELECT * FROM module_log_fields WHERE service_key = ? ORDER BY sort_order ASC");
      $st->execute([$service_key]);
      $fields = $st->fetchAll();

      // Last 7 days of logs for this child (with module_fields_json)
      $st = db()->prepare("SELECT * FROM daily_logs
                           WHERE child_id = ? AND module_fields_json IS NOT NULL
                           ORDER BY log_date DESC LIMIT 7");
      $st->execute([(int)$child['id']]);
      $recent_logs = $st->fetchAll();

      // Today's log row (if any) so we can pre-fill
      $today_str = date('Y-m-d');
      $today_log = null;
      foreach ($recent_logs as $r) {
          if ($r['log_date'] === $today_str) { $today_log = $r; break; }
      }
      $today_data = [];
      if ($today_log && !empty($today_log['module_fields_json'])) {
          $j = json_decode($today_log['module_fields_json'], true);
          if (is_array($j) && isset($j[$service_key])) $today_data = $j[$service_key];
      }
  ?>

  <div class="space-y-4">

    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="text-lg font-semibold mb-1">📔 Today's log — <?= e(date('l, j M')) ?></h2>
      <p class="text-sm text-slate-600 mb-4">30-second check-in for <?= e($child['name']) ?>'s <?= e($meta['label']) ?> progress. You can edit today's entry as many times as you want — only the last save is kept.</p>

      <?php if (empty($fields)): ?>
        <p class="text-sm text-slate-500 italic">No tracker fields are configured for this module yet.</p>
      <?php else: ?>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_daily_log">
          <?php foreach ($fields as $f):
              $val = $today_data[$f['field_key']] ?? '';
          ?>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">
                <?= e($f['label_en']) ?>
                <?php if ($f['label_hi']): ?>
                  <span class="text-xs text-slate-500 italic">· <?= e($f['label_hi']) ?></span>
                <?php endif; ?>
              </label>
              <?php if ($f['field_type'] === 'yesno'): ?>
                <div class="flex gap-2 flex-wrap">
                  <?php foreach (['yes' => 'Yes / हाँ', 'no' => 'No / नहीं', 'partial' => 'Partial / थोड़ा'] as $opt => $opt_lab): ?>
                    <label class="dl-pill inline-flex items-center gap-1.5 px-3 py-1.5 border rounded-full cursor-pointer text-sm bg-white border-slate-200 hover:bg-slate-50">
                      <input type="radio" name="field_<?= e($f['field_key']) ?>" value="<?= e($opt) ?>" <?= $val === $opt ? 'checked' : '' ?> class="hidden">
                      <?= e($opt_lab) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($f['field_type'] === 'minutes' || $f['field_type'] === 'count'): ?>
                <input type="number" min="0" max="999" name="field_<?= e($f['field_key']) ?>" value="<?= e($val) ?>"
                       class="w-32 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-500"
                       placeholder="<?= $f['field_type'] === 'minutes' ? 'mins' : '#' ?>">
              <?php elseif ($f['field_type'] === 'likert05' || $f['field_type'] === 'rating_1_5'): ?>
                <div class="flex gap-1">
                  <?php
                  $lo = $f['field_type'] === 'likert05' ? 0 : 1;
                  $hi = $f['field_type'] === 'likert05' ? 5 : 5;
                  for ($i = $lo; $i <= $hi; $i++): ?>
                    <label class="dl-rating inline-flex items-center justify-center w-9 h-9 border rounded-lg cursor-pointer text-sm font-semibold bg-white border-slate-200 hover:bg-slate-50">
                      <input type="radio" name="field_<?= e($f['field_key']) ?>" value="<?= $i ?>" <?= (string)$val === (string)$i ? 'checked' : '' ?> class="hidden">
                      <?= $i ?>
                    </label>
                  <?php endfor; ?>
                </div>
              <?php else: /* text */ ?>
                <textarea name="field_<?= e($f['field_key']) ?>" rows="2"
                          class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-500"
                          placeholder="Optional notes..."><?= e($val) ?></textarea>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <button class="brand-grad text-white text-sm font-semibold px-5 py-2 rounded-lg hover:opacity-90">
            💾 Save today's log
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Recent 7 days strip -->
    <?php if (!empty($recent_logs)): ?>
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <h3 class="text-base font-semibold mb-3">Last 7 days</h3>
        <div class="space-y-2">
          <?php foreach (array_reverse($recent_logs) as $r):
              $j = json_decode((string)$r['module_fields_json'], true);
              $mod_data = is_array($j) && isset($j[$service_key]) ? $j[$service_key] : null;
          ?>
            <div class="flex items-start gap-3 text-sm">
              <span class="text-slate-500 w-20 shrink-0"><?= e(date('D, j M', strtotime($r['log_date']))) ?></span>
              <div class="flex-1 text-slate-700">
                <?php if ($mod_data && is_array($mod_data)): ?>
                  <?php
                  $bits = [];
                  foreach ($mod_data as $k => $v) {
                      if ($v === '' || $v === null) continue;
                      // Find label
                      $lab = $k;
                      foreach ($fields as $f) { if ($f['field_key'] === $k) { $lab = $f['label_en']; break; } }
                      $bits[] = e($lab) . ': <strong>' . e((string)$v) . '</strong>';
                  }
                  echo $bits ? implode(' · ', $bits) : '<span class="text-slate-400 italic">(no data for this module)</span>';
                  ?>
                <?php else: ?>
                  <span class="text-slate-400 italic">(no entry)</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <?php
  // ─────────────────────────────────────────────────────────────
  // Tab: consult — ask the AI a scoped question
  // ─────────────────────────────────────────────────────────────
  elseif ($tab === 'consult'):
      $free_left = $parent ? consult_free_remaining((int)$parent['id'], (int)$child['id'], $service_key) : 0;
      $pack_bal  = $parent ? consult_balance((int)$parent['id']) : 0;
      $last_q    = $_SESSION['last_consult_q'] ?? null;
      $last_a    = $_SESSION['last_consult_a'] ?? null;
      unset($_SESSION['last_consult_q'], $_SESSION['last_consult_a']);

      // Recent consult history for this module + child
      $st = db()->prepare("SELECT question, answer, paid_from, created_at FROM module_consults
                           WHERE parent_id = ? AND child_id = ? AND service_key = ?
                           ORDER BY id DESC LIMIT 5");
      $st->execute([(int)$parent['id'], (int)$child['id'], $service_key]);
      $history = $st->fetchAll();
  ?>

  <div class="bg-white rounded-2xl border border-slate-200 p-6">
    <h2 class="text-lg font-semibold mb-2">Ask AI — scoped to this module</h2>
    <div class="flex gap-2 flex-wrap text-xs text-slate-600 mb-4">
      <span class="bg-emerald-50 border border-emerald-200 px-2 py-1 rounded-full">
        <?= (int)$free_left ?> free included left
      </span>
      <span class="bg-indigo-50 border border-indigo-200 px-2 py-1 rounded-full">
        <?= (int)$pack_bal ?> in consult pack
      </span>
      <a href="/catalogue.php?g=consult" class="bg-amber-50 border border-amber-200 px-2 py-1 rounded-full text-amber-800 hover:bg-amber-100">
        + Buy more
      </a>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="ask_consult">
      <textarea name="question" rows="3" required maxlength="800"
                placeholder="e.g. My child is still saying only 5–6 words at age 3. What should I do this week?"
                class="w-full border border-slate-300 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"><?= e((string)$last_q) ?></textarea>
      <div class="flex justify-between items-center mt-3">
        <span class="text-xs text-slate-500">≤ 800 characters</span>
        <button class="brand-grad text-white font-semibold px-5 py-2 rounded-lg hover:opacity-90">Ask</button>
      </div>
    </form>

    <?php if ($last_a): ?>
      <div class="mt-6 bg-slate-50 border border-slate-200 rounded-xl p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold mb-2">Latest answer</p>
        <div class="prose prose-sm max-w-none whitespace-pre-wrap text-slate-800"><?= e($last_a) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($history)): ?>
      <div class="mt-8">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Recent consults</h3>
        <div class="space-y-3">
          <?php foreach ($history as $h): ?>
            <details class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <summary class="cursor-pointer text-sm text-slate-800">
                <span class="text-xs text-slate-500"><?= e(date('j M, H:i', strtotime($h['created_at']))) ?></span>
                &middot; <?= e(mb_substr($h['question'], 0, 100)) ?><?= mb_strlen($h['question']) > 100 ? '…' : '' ?>
              </summary>
              <div class="mt-2 text-sm text-slate-700 whitespace-pre-wrap"><?= e($h['answer']) ?></div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
