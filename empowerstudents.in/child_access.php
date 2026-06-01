<?php
/**
 * includes/child_access.php
 *
 * Single source of truth for child-module access decisions.
 *
 * The model:
 *   - Each child gets ONE free module evaluation (their pick)
 *   - All other modules + redos require the Child Package (₹999, 14 days)
 *   - Free 2-min parent index check is always free (handled elsewhere)
 *
 * Public API:
 *   ca_ensure_schema()                                  — adds free_trial_* cols if missing
 *   ca_has_active_unlock(int $cid): ?array              — package row if active, else null
 *   ca_free_trial(int $cid): array                      — ['module' => string|null, 'used_at' => string|null]
 *   ca_set_free_trial(int $cid, string $module): void   — record the parent's pick
 *   ca_can_evaluate(int $cid, string $module): array    — ['ok' => bool, 'reason' => 'package'|'free_trial'|'paywall', 'message' => '']
 *   ca_paywall_card_html(int $cid, string $module): string — renders the paywall block
 */

require_once __DIR__ . '/db.php';

function ca_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("PRAGMA table_info(children)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('free_trial_module', $names, true)) {
            @db()->exec("ALTER TABLE children ADD COLUMN free_trial_module TEXT");
        }
        if (!in_array('free_trial_used_at', $names, true)) {
            @db()->exec("ALTER TABLE children ADD COLUMN free_trial_used_at TEXT");
        }
    } catch (Throwable $_) {}
}

/**
 * Returns the active child_program_unlocks row for the child, or null.
 */
function ca_has_active_unlock(int $cid): ?array {
    try {
        $st = db()->prepare("SELECT * FROM child_program_unlocks
                              WHERE child_id = ? AND status = 'active' AND expires_at > datetime('now')
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$cid]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $_) { return null; }
}

/**
 * Returns the child's free-trial status.
 *   ['module' => 'mind_power', 'used_at' => '2026-05-13 12:34:56']
 *   or ['module' => null, 'used_at' => null] if not chosen yet.
 */
function ca_free_trial(int $cid): array {
    ca_ensure_schema();
    try {
        $st = db()->prepare("SELECT free_trial_module, free_trial_used_at FROM children WHERE id = ?");
        $st->execute([$cid]);
        $row = $st->fetch();
        if (!$row) return ['module' => null, 'used_at' => null];
        return [
            'module'  => $row['free_trial_module'] ?: null,
            'used_at' => $row['free_trial_used_at'] ?: null,
        ];
    } catch (Throwable $_) {
        return ['module' => null, 'used_at' => null];
    }
}

/**
 * Records the parent's free-trial pick. Idempotent — does nothing if already set.
 * Returns true if newly set, false if already had a pick.
 */
function ca_set_free_trial(int $cid, string $module): bool {
    ca_ensure_schema();
    $cur = ca_free_trial($cid);
    if ($cur['module']) return false;
    try {
        $st = db()->prepare("UPDATE children SET free_trial_module = ?, free_trial_used_at = CURRENT_TIMESTAMP WHERE id = ?");
        $st->execute([$module, $cid]);
        return true;
    } catch (Throwable $_) {
        return false;
    }
}

/**
 * Decides if this parent can evaluate this child on this module.
 *
 * Returns:
 *   ['ok' => true,  'reason' => 'package']     — Child Package is active, all good
 *   ['ok' => true,  'reason' => 'free_trial']  — this is their free pick, allowed once
 *   ['ok' => false, 'reason' => 'paywall',
 *                   'message' => '...',
 *                   'paywall_html' => '...']   — gate fires
 */
function ca_can_evaluate(int $cid, string $module): array {
    // 1. Active package wins
    $unlock = ca_has_active_unlock($cid);
    if ($unlock) {
        return ['ok' => true, 'reason' => 'package', 'unlock' => $unlock];
    }

    // 2. Free trial logic
    $ft = ca_free_trial($cid);

    // 2a. No pick yet — auto-pick this module
    if ($ft['module'] === null) {
        ca_set_free_trial($cid, $module);
        return ['ok' => true, 'reason' => 'free_trial_auto_picked'];
    }

    // 2b. This is their pick
    if ($ft['module'] === $module) {
        // Check if they've already COMPLETED an evaluation for this module
        // (i.e. assessments row exists OR child_eval_sessions completed). If yes,
        // retake requires the package. If no, free trial still active.
        if (_ca_module_completed($cid, $module)) {
            return [
                'ok'           => false,
                'reason'       => 'paywall_retake',
                'message'      => "You've used your free trial. Retakes of " . _ca_label($module) . " require the Child Package.",
                'paywall_html' => ca_paywall_card_html($cid, $module),
            ];
        }
        // In-progress or never-started → allow
        return ['ok' => true, 'reason' => 'free_trial'];
    }

    // 3. Free trial used on a different module, no package
    return [
        'ok'           => false,
        'reason'       => 'paywall',
        'message'      => "Your free trial was used on " . _ca_label($ft['module']) . ". Unlock the Child Package to evaluate " . _ca_label($module) . ".",
        'paywall_html' => ca_paywall_card_html($cid, $module),
    ];
}

/**
 * Internal: has the child already completed (or has a completed assessment for) this module?
 * Checks both child_eval_sessions (adaptive engine) and assessments table (legacy).
 */
function _ca_module_completed(int $cid, string $module): bool {
    // child_eval_sessions check
    try {
        $st = db()->prepare("SELECT 1 FROM child_eval_sessions
                              WHERE child_id = ? AND module = ? AND status = 'completed' LIMIT 1");
        $st->execute([$cid, $module]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $_) {}
    // legacy assessments check
    try {
        $st = db()->prepare("SELECT 1 FROM assessments
                              WHERE child_id = ? AND module = ? AND status = 'completed' LIMIT 1");
        $st->execute([$cid, $module]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $_) {}
    return false;
}

function _ca_label(string $module): string {
    return [
        'speech'           => 'Speech',
        'mind_power'       => 'Mind Power',
        'behavior'         => 'Behaviour',
        'general_awareness'=> 'General Knowledge',
        'maths'            => 'Maths',
        'math'             => 'Maths',
        'language'         => 'Language',
        'health'           => 'Health',
        'pulse_check'      => 'Pulse & Breath',
        'diet'             => 'Diet',
        'special_talent'   => 'Special Talent',
    ][$module] ?? ucfirst($module);
}

/**
 * Renders the paywall card HTML. Shown when ca_can_evaluate returns ok=false.
 */
function ca_paywall_card_html(int $cid, string $module): string {
    $module_label = _ca_label($module);
    $unlock_price = 999;
    try {
        if (function_exists('wallet_service_price')) {
            $p = (int)(wallet_service_price('child_learn_program') ?? 999);
            if ($p > 0) $unlock_price = $p;
        }
    } catch (Throwable $_) {}

    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    return <<<HTML
<div style="background:linear-gradient(135deg,#FFF8EC 0%,#FFE3CE 100%);border:2px solid #fb923c;border-radius:20px;padding:24px;text-align:center;margin:20px auto;max-width:520px">
  <div style="font-size:42px;margin-bottom:8px">🔒</div>
  <h3 style="font-family:'Fredoka',system-ui;font-size:22px;color:#9a3412;margin:0 0 8px;font-weight:700">
    Unlock all child evaluations
  </h3>
  <p style="font-size:14px;color:#7c2d12;line-height:1.55;margin:0 0 18px">
    You've used your free trial. The <strong>Child Package (₹{$unlock_price})</strong> unlocks
    all 10 modules including <strong>{$module_label}</strong>, plus the 7-day daily-practice course — for 14 days.
  </p>
  <form method="POST" action="/child-learn-unlock.php" style="margin:0">
    <input type="hidden" name="csrf" value="{$csrf}">
    <input type="hidden" name="cid" value="{$cid}">
    <button type="submit" style="background:linear-gradient(135deg,#f97316,#ea580c);color:white;border:none;padding:14px 24px;border-radius:999px;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 6px 18px rgba(234,88,12,0.3)">
      🔓 Unlock Child Package — ₹{$unlock_price}
    </button>
  </form>
  <p style="margin:14px 0 0;font-size:11px;color:#92400e">
    Includes all 10 modules · 14 days · 7-day daily practice
  </p>
</div>
HTML;
}
