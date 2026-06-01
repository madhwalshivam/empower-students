<?php
/**
 * includes/catalogue_alias.php — bridges legacy module keys to catalogue keys.
 *
 * Old module files like /modules/speech.php call wallet_charge_for_service($p, 'speech', $aid).
 * If the parent already owns the catalogue equivalent (e.g. 'mod_speech_language'),
 * we mark the assessment as covered and don't double-charge.
 *
 * This is the ONLY new touch-point in the existing module engine.
 * Required from /modules/_common.php after the wallet charge call.
 *
 * Does NOTHING if the parent doesn't own a catalogue alias — original
 * charge logic stands.
 */
require_once __DIR__ . '/db.php';

/**
 * Map of legacy module key → catalogue service_key that supersedes it.
 * If parent owns the catalogue key, the legacy charge is treated as
 * covered (no new charge, but assessment ownership recorded).
 */
function catalogue_alias_map(): array {
    return [
        'speech'             => 'mod_speech_language',
        'spontaneous'        => 'mod_speech_language',
        'behavior'           => 'mod_behaviour_emotion',
        'emotions'           => 'mod_behaviour_emotion',
        'health'             => 'mod_developmental',
        'math'               => 'mod_math',
        'language'           => 'mod_language',
        'general_awareness'  => 'mod_general_awareness',
        'mind_power'         => 'mod_mind_power',
        'special_talent'     => 'mod_special_talent',
        'parent_index'       => 'mod_parenting',
        'diet'               => 'mod_family_wellness',
    ];
}

/**
 * Returns the catalogue key that aliases this legacy key, or null if none.
 */
function catalogue_alias_for(string $legacy_key): ?string {
    $m = catalogue_alias_map();
    return $m[$legacy_key] ?? null;
}

/**
 * Has the parent purchased the catalogue equivalent of this legacy module,
 * OR an active Care Pack that covers it? Either path means the legacy module
 * should NOT charge again.
 *
 * Note: Care Pack is per-child, so this needs the child via the assessment row.
 * The caller (in modules/_common.php) only has parent_id + legacy_key. We
 * therefore check Care Pack at parent-level too — if ANY of their children
 * has a Care Pack, and the legacy key maps to a covered catalogue module,
 * we're safe to skip. (Wallet idempotency catches any ambiguity.)
 */
function catalogue_owns_alias(int $parent_id, string $legacy_key): bool {
    $alias = catalogue_alias_for($legacy_key);
    if ($alias === null) return false;

    // Direct catalogue purchase
    $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = ? AND amount <= 0");
    $st->execute([$parent_id, $alias]);
    if ((int)$st->fetchColumn() > 0) return true;

    // Care Pack bridge: if parent owns a Care Pack and the alias is a
    // module covered by Care Pack, treat as owned.
    if (function_exists('care_pack_catalogue_keys')
        && in_array($alias, care_pack_catalogue_keys(), true)) {
        $st = db()->prepare("SELECT COUNT(*) FROM wallet_ledger
                             WHERE parent_id = ? AND service_key = 'care_pack' AND amount < 0");
        $st->execute([$parent_id]);
        if ((int)$st->fetchColumn() > 0) return true;
    }

    return false;
}

/**
 * Reverse alias lookup: given a catalogue service_key, return all legacy
 * `assessments.module` values that should be considered the same.
 *
 * Example: legacy_keys_for_catalogue('mod_speech_language') returns
 *   ['mod_speech_language', 'speech', 'spontaneous']
 *
 * Used by /module.php's Report tab so that an assessment saved under the
 * legacy `module='speech'` shows up when viewing the catalogue
 * `mod_speech_language` Report tab.
 */
function legacy_keys_for_catalogue(string $catalogue_key): array {
    $keys = [$catalogue_key];
    foreach (catalogue_alias_map() as $legacy => $alias) {
        if ($alias === $catalogue_key) $keys[] = $legacy;
    }
    return array_values(array_unique($keys));
}
