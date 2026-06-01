<?php
/**
 * includes/catalogue.php — module catalogue helpers.
 *
 * Pure functions over the existing wallet_ledger + service_prices +
 * service_meta + module_consults + module_plans tables. No state of its own.
 *
 * PHP 7.4 compatible.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/catalogue_schema.php';
// Soft-load ISAA schema (partners + assessment tables + 40-item seed)
if (file_exists(__DIR__ . '/isaa_schema.php')) {
    require_once __DIR__ . '/isaa_schema.php';
}
// Soft-load alias bridge so reverse-lookup helpers (legacy_keys_for_catalogue,
// catalogue_owns_alias) are available wherever catalogue.php is loaded.
if (file_exists(__DIR__ . '/catalogue_alias.php')) {
    require_once __DIR__ . '/catalogue_alias.php';
}

// ─────────────────────────────────────────────────────────────
// Read: catalogue listing + single module lookup
// ─────────────────────────────────────────────────────────────

/**
 * Return all catalogue rows joined with current price. Filtered to
 * is_catalogue=1, is_active=1, assessment_ready=1 by default.
 *
 * @param array $filters = ['group' => 'special'|'all'|'parent'|'pack'|'consult'|null,
 *                          'age'   => float|null,         // filter by age band
 *                          'include_legacy'  => bool,
 *                          'include_partial' => bool]    // include modules without assessment files
 */
function catalogue_modules(array $filters = []): array {
    $sql = "SELECT sm.service_key, sm.catalogue_group, sm.tier, sm.icon,
                   sm.short_desc, sm.short_desc_hi, sm.long_desc_md, sm.long_desc_md_hi,
                   sm.sample_question, sm.sample_question_hi,
                   sm.age_min, sm.age_max, sm.plan_weeks, sm.free_consults_included,
                   sm.sort_order, sm.is_catalogue, sm.bundle_keys, sm.bundle_discount_pct,
                   COALESCE(sm.assessment_ready, 1) AS assessment_ready,
                   sp.label, sp.price, sp.is_active
            FROM service_meta sm
            JOIN service_prices sp ON sp.service_key = sm.service_key
            WHERE 1=1";
    $args = [];
    if (empty($filters['include_legacy'])) {
        $sql .= " AND sm.is_catalogue = 1 AND sp.is_active = 1";
    }
    if (empty($filters['include_partial'])) {
        // Packs and consults don't have assessments — never filter them out.
        $sql .= " AND (sm.catalogue_group IN ('pack','consult') OR COALESCE(sm.assessment_ready, 1) = 1)";
    }
    if (!empty($filters['group'])) {
        $sql .= " AND sm.catalogue_group = ?";
        $args[] = $filters['group'];
    }
    if (isset($filters['age']) && is_numeric($filters['age'])) {
        $sql .= " AND ? >= sm.age_min AND ? <= sm.age_max";
        $args[] = (float)$filters['age'];
        $args[] = (float)$filters['age'];
    }
    $sql .= " ORDER BY sm.sort_order ASC, sp.price ASC";
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Single catalogue row by service_key (NULL if not found / not in catalogue). */
function module_meta(string $service_key): ?array {
    $st = db()->prepare(
        "SELECT sm.*, sp.label, sp.price, sp.is_active
         FROM service_meta sm
         LEFT JOIN service_prices sp ON sp.service_key = sm.service_key
         WHERE sm.service_key = ?"
    );
    $st->execute([$service_key]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 
 * Care Pack bridge: parents who bought the legacy Care Pack for child X
 * are treated as owning these catalogue modules for that child too.
 * Read-only — does not write phantom ledger rows.
 */
function care_pack_catalogue_keys(): array {
    return [
        'mod_speech_language',
        'mod_behaviour_emotion',
        'mod_developmental',
        'mod_special_talent',
        'mod_parenting',
        'mod_family_wellness',
        'mod_mind_power',
    ];
}

/** Does the parent own a Care Pack for this child? */
function care_pack_active(int $parent_id, int $child_id): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = 'care_pack' AND ref_id = ? AND amount < 0");
    $st->execute([$parent_id, $child_id]);
    return (int)$st->fetchColumn() > 0;
}

/** Has this parent purchased this module for this child? */
function module_owns(int $parent_id, int $child_id, string $service_key): bool {
    // Care Pack bridge: if parent has an active Care Pack for this child,
    // treat covered catalogue modules as owned.
    if (in_array($service_key, care_pack_catalogue_keys(), true)
        && care_pack_active($parent_id, $child_id)) {
        return true;
    }

    // Primary check: a wallet charge exists where ref_id == child_id.
    // This is how /module.php and /cart.php charge — so this is the cleanest
    // proof of "this parent bought this module for THIS child".
    // (amount <= 0 catches both real charges (-499) and 0-amount ownership
    // rows written by bundle expansion.)
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = ? AND ref_id = ? AND amount <= 0");
    $st->execute([$parent_id, $service_key, $child_id]);
    if ((int)$st->fetchColumn() > 0) return true;

    // Fallback A: completed assessment exists (covers legacy purchases
    // where ref_id was assessment_id, not child_id)
    $st = db()->prepare("SELECT COUNT(*) FROM assessments
                         WHERE child_id = ? AND module = ? AND status = 'done'");
    $st->execute([$child_id, $service_key]);
    if ((int)$st->fetchColumn() > 0) return true;

    // Fallback B: a generated plan exists
    $st = db()->prepare("SELECT COUNT(*) FROM module_plans WHERE child_id = ? AND service_key = ?");
    $st->execute([$child_id, $service_key]);
    if ((int)$st->fetchColumn() > 0) return true;

    // Fallback C: parent-track modules (parent_stress, daily_practices, etc.)
    // are not child-scoped — any non-reversed charge counts for any child.
    $meta = module_meta($service_key);
    if ($meta && ($meta['catalogue_group'] ?? '') === 'parent') {
        $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                             WHERE parent_id = ? AND service_key = ? AND amount <= 0");
        $st->execute([$parent_id, $service_key]);
        if ((int)$st->fetchColumn() > 0) return true;
    }

    return false;
}

// ─────────────────────────────────────────────────────────────
// Consults
// ─────────────────────────────────────────────────────────────

function consult_balance(int $parent_id): int {
    $st = db()->prepare("SELECT balance FROM consult_balance WHERE parent_id = ?");
    $st->execute([$parent_id]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Free consults remaining for a (parent, child, module) — derived from
 *  module's free_consults_included minus already-used. Resets per module
 *  purchase (not per child); the original free quota came with the module.
 *
 *  If the module was claimed via the free-tier pick (service_key
 *  'free_module_pick'), the quota is overridden to FREE_TIER_CONSULTS
 *  regardless of what the module's full-tier free_consults_included says. */
const FREE_TIER_CONSULTS = 3;

function consult_free_remaining(int $parent_id, int $child_id, string $service_key): int {
    $meta = module_meta($service_key);
    if (!$meta) return 0;

    // If parent owns this module via the free pick, the quota is FREE_TIER_CONSULTS.
    if (function_exists('module_owned_via_free_pick') &&
        module_owned_via_free_pick($parent_id, $service_key)) {
        $included = FREE_TIER_CONSULTS;
    } else {
        $included = (int)($meta['free_consults_included'] ?? 0);
    }

    if ($included <= 0) return 0;
    $st = db()->prepare("SELECT COUNT(*) FROM module_consults
                         WHERE parent_id = ? AND child_id = ? AND service_key = ? AND paid_from = 'free_included'");
    $st->execute([$parent_id, $child_id, $service_key]);
    $used = (int)$st->fetchColumn();
    return max(0, $included - $used);
}

// ─────────────────────────────────────────────────────────────
// Free-tier pick (one free module per parent, lifetime)
// ─────────────────────────────────────────────────────────────

/** Modules a parent may pick via the free-tier offer. Curated, not auto-derived
 *  so we can include both ready and partial-but-stable picks. */
function free_pick_module_keys(): array {
    return [
        'mod_speech_language',
        'mod_math',
        'mod_behaviour_emotion',
        'mod_parenting',
        'mod_language',
        'mod_family_wellness',
        'mod_general_awareness',
        'mod_developmental',
    ];
}

/** Has this parent already used their lifetime free pick? */
function parent_has_used_free_pick(int $parent_id): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = 'free_module_pick'");
    $st->execute([$parent_id]);
    return (int)$st->fetchColumn() > 0;
}

/** Which module did the parent pick? Returns service_key or null. */
function parent_free_pick_key(int $parent_id): ?string {
    $st = db()->prepare("SELECT reason FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = 'free_module_pick'
                         ORDER BY id ASC LIMIT 1");
    $st->execute([$parent_id]);
    $row = $st->fetch();
    if (!$row) return null;
    // We stored the picked key in `reason` like 'Free pick: mod_speech_language'
    if (preg_match('/free pick:\s*([a-z_]+)/i', (string)$row['reason'], $m)) {
        return $m[1];
    }
    return null;
}

/** Did this parent acquire this specific module via the free pick? */
function module_owned_via_free_pick(int $parent_id, string $service_key): bool {
    $picked = parent_free_pick_key($parent_id);
    if ($picked !== $service_key) return false;
    // Belt-and-braces: also verify the parent doesn't have a real (positive amount paid) charge for this module
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = ? AND amount < 0");
    $st->execute([$parent_id, $service_key]);
    return (int)$st->fetchColumn() === 0;
}

/** Record the free pick. Idempotent — second call is a no-op. */
function claim_free_pick(int $parent_id, int $child_id, string $service_key): array {
    if (!in_array($service_key, free_pick_module_keys(), true)) {
        return ['ok' => false, 'message' => 'This module is not eligible for the free pick.'];
    }
    if (parent_has_used_free_pick($parent_id)) {
        return ['ok' => false, 'message' => 'You have already claimed your free module.', 'picked' => parent_free_pick_key($parent_id)];
    }
    // Marker row — service_key 'free_module_pick', amount 0, reason carries the picked key
    wallet_post($parent_id, 0, 'free_module_pick', $child_id,
                "Free pick: {$service_key}", 'system');
    // Ownership row for the picked module — same shape as bundle expansion
    wallet_post($parent_id, 0, $service_key, $child_id,
                "Free-tier ownership (assessment + report + 3 advice only)", 'system');
    return ['ok' => true, 'message' => 'Free module unlocked.'];
}

/**
 * Charge for one consult — picks free_included first, then pack balance,
 * then nothing (caller decides whether to upsell a pack).
 *
 * Returns ['ok' => bool, 'paid_from' => 'free_included'|'pack'|null, 'message' => str]
 */
function consult_consume(int $parent_id, int $child_id, string $service_key): array {
    $free = consult_free_remaining($parent_id, $child_id, $service_key);
    if ($free > 0) {
        return ['ok' => true, 'paid_from' => 'free_included', 'message' => "Free included ({$free} left before this one)"];
    }
    $bal = consult_balance($parent_id);
    if ($bal > 0) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE consult_balance SET balance = balance - 1, updated_at = CURRENT_TIMESTAMP WHERE parent_id = ? AND balance > 0")
                ->execute([$parent_id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        return ['ok' => true, 'paid_from' => 'pack', 'message' => "From your consult pack ({$bal} left before this one)"];
    }
    return ['ok' => false, 'paid_from' => null, 'message' => 'Out of free consults and pack balance — buy a consult pack'];
}

/** Add consults to a parent's balance (called after they buy a pack). */
function consult_grant(int $parent_id, int $count, string $reason = ''): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Portable upsert (no ON CONFLICT) — works on older SQLite too
        $st = $pdo->prepare("SELECT balance FROM consult_balance WHERE parent_id = ?");
        $st->execute([$parent_id]);
        $existing = $st->fetchColumn();
        if ($existing === false) {
            $pdo->prepare("INSERT INTO consult_balance (parent_id, balance) VALUES (?, ?)")
                ->execute([$parent_id, (int)$count]);
        } else {
            $pdo->prepare("UPDATE consult_balance SET balance = balance + ?, updated_at = CURRENT_TIMESTAMP WHERE parent_id = ?")
                ->execute([(int)$count, $parent_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return consult_balance($parent_id);
}

/** Log a consult question and its answer. */
function consult_log(int $parent_id, int $child_id, string $service_key, string $question, string $answer, string $paid_from): int {
    $st = db()->prepare("INSERT INTO module_consults (parent_id, child_id, service_key, question, answer, paid_from)
                         VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([$parent_id, $child_id, $service_key, $question, $answer, $paid_from]);
    return (int)db()->lastInsertId();
}

// ─────────────────────────────────────────────────────────────
// Cart pricing
// ─────────────────────────────────────────────────────────────

/**
 * Compute cart total with auto-applied discount tier.
 *   1 module  → no discount
 *   2 modules → 10% off
 *   3 modules → 20% off
 *   5+ modules → 30% off
 *
 * Packs are not discounted further (their price is already discounted).
 *
 * @param array $service_keys
 * @return array ['lines' => [...], 'subtotal' => int, 'discount_pct' => int, 'discount' => int, 'total' => int]
 */
function cart_total(array $service_keys): array {
    $lines = [];
    $subtotal = 0;
    $module_count = 0;
    foreach ($service_keys as $key) {
        $meta = module_meta($key);
        if (!$meta || (int)$meta['is_active'] !== 1) continue;
        $price = (int)$meta['price'];
        $lines[] = ['service_key' => $key, 'label' => $meta['label'], 'price' => $price, 'tier' => $meta['tier'] ?? ''];
        $subtotal += $price;
        if (($meta['catalogue_group'] ?? '') !== 'pack' && ($meta['catalogue_group'] ?? '') !== 'consult') {
            $module_count++;
        }
    }
    $pct = 0;
    if      ($module_count >= 5) $pct = 30;
    elseif  ($module_count >= 3) $pct = 20;
    elseif  ($module_count >= 2) $pct = 10;
    $discount = (int) floor($subtotal * $pct / 100);
    $total = $subtotal - $discount;
    return [
        'lines' => $lines,
        'subtotal' => $subtotal,
        'discount_pct' => $pct,
        'discount' => $discount,
        'total' => $total,
        'module_count' => $module_count,
    ];
}

// ─────────────────────────────────────────────────────────────
// Bundle expansion (Special Children Pack → 5 module unlocks)
// ─────────────────────────────────────────────────────────────

/** Decode bundle_keys JSON. Returns [] if not a bundle. */
function pack_member_keys(string $service_key): array {
    $meta = module_meta($service_key);
    if (!$meta || empty($meta['bundle_keys'])) return [];
    $raw = $meta['bundle_keys'];
    if ($raw === '"choice"') return ['choice'];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

// ─────────────────────────────────────────────────────────────
// Module log fields (unified tracker contributions)
// ─────────────────────────────────────────────────────────────

/**
 * Active module-added log fields for a child = union of fields
 * from all owned modules. For pure-parent modules, child_id can be 0 and
 * they apply to any child the parent owns.
 */
function active_log_fields(int $parent_id, int $child_id): array {
    // Find owned service_keys (any negative ledger entry for this parent).
    $owned = db()->prepare("SELECT DISTINCT service_key FROM wallet_ledger
                            WHERE parent_id = ? AND amount < 0 AND service_key IS NOT NULL");
    $owned->execute([$parent_id]);
    $keys = [];
    foreach ($owned->fetchAll() as $r) { $keys[] = $r['service_key']; }
    if (empty($keys)) return [];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st = db()->prepare("SELECT * FROM module_log_fields
                         WHERE service_key IN ($placeholders)
                         ORDER BY sort_order ASC");
    $st->execute($keys);
    return $st->fetchAll();
}

// ─────────────────────────────────────────────────────────────
// Plan fetching
// ─────────────────────────────────────────────────────────────

function module_plan(int $child_id, string $service_key): ?array {
    $st = db()->prepare("SELECT * FROM module_plans WHERE child_id = ? AND service_key = ?");
    $st->execute([$child_id, $service_key]);
    $r = $st->fetch();
    return $r ?: null;
}

function module_plan_save(int $child_id, string $service_key, string $plan_md, string $plan_json, int $weeks): int {
    // Portable upsert (no ON CONFLICT) — works on older SQLite too
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM module_plans WHERE child_id = ? AND service_key = ?");
    $st->execute([$child_id, $service_key]);
    $existing = $st->fetchColumn();
    if ($existing === false) {
        $pdo->prepare("INSERT INTO module_plans (child_id, service_key, plan_md, plan_json, weeks, started_at)
                       VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([$child_id, $service_key, $plan_md, $plan_json, $weeks]);
        return (int)$pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE module_plans SET plan_md = ?, plan_json = ?, weeks = ?, started_at = CURRENT_TIMESTAMP
                   WHERE child_id = ? AND service_key = ?")
       ->execute([$plan_md, $plan_json, $weeks, $child_id, $service_key]);
    return (int)$existing;
}

// ─────────────────────────────────────────────────────────────
// Friendly group / tier labels
// ─────────────────────────────────────────────────────────────

function catalogue_group_label(string $group): string {
    $map = [
        'special' => '🩺 For special children',
        'all'     => '📚 For all children',
        'parent'  => '🌱 For parents',
        'pack'    => '🎁 Bundles',
        'consult' => '💡 AI consult packs',
    ];
    return $map[$group] ?? ucfirst($group);
}

function tier_label(string $tier): string {
    $map = [
        'quick'    => 'Quick',
        'standard' => 'Standard',
        'deep'     => 'Deep',
        'pack'     => 'Bundle',
        'consult'  => 'Pack',
    ];
    return $map[$tier] ?? $tier;
}

function tier_badge_class(string $tier): string {
    // Returns Tailwind classes for the tier badge
    $map = [
        'quick'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'standard' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'deep'     => 'bg-rose-50 text-rose-700 border-rose-200',
        'pack'     => 'bg-amber-50 text-amber-700 border-amber-200',
        'consult'  => 'bg-violet-50 text-violet-700 border-violet-200',
    ];
    return $map[$tier] ?? 'bg-slate-50 text-slate-700 border-slate-200';
}
