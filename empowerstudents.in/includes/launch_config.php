<?php
/**
 * includes/launch_config.php
 *
 * Single source of truth for what's part of the launched product lineup
 * on EmpowerStudents.in. Other pages call is_launched($key) to decide
 * whether a feature appears.
 *
 * Set LAUNCH_MODE = false to disable all hiding (everything visible).
 */

if (!defined('LAUNCH_MODE')) {
    define('LAUNCH_MODE', true);
}

/**
 * Module/feature visibility lookup.
 */
function is_launched(string $key): bool {
    if (!LAUNCH_MODE) return true;   // dev mode — show everything

    static $launched = [
        // Parent flow
        'parent_reflect'        => true,
        'parent_home_course'    => true,

        // Child hub
        'child_learn_hub'       => true,
        'child_learn_program'   => true,

        // Child modules (adaptive)
        'speech'                => true,
        'mind_power'            => true,
        'behavior'              => true,
        'behaviour'             => true,
        'general_awareness'     => true,
        'language'              => true,
        'math'                  => true,
        'maths'                 => true,

        // Child modules (specialized — keep visible, no adaptive engine)
        'health'                => true,
        'pulse_check'           => true,
        'diet'                  => true,
        'special_talent'        => true,

        // ISAA + partner program
        'isaa'                  => true,
        'pediatrician_program'  => true,
    ];

    return !empty($launched[$key]);
}

/**
 * Returns hidden module keys (archived to /modules/_archive/).
 */
function launch_hidden_modules(): array {
    if (!LAUNCH_MODE) return [];
    return ['emotions', 'spontaneous', 'parent_index'];
}

/**
 * Maps legacy module keys to adaptive engine module keys.
 * Returns null for modules that DON'T use the adaptive engine.
 */
function launch_adaptive_engine_key(string $key): ?string {
    static $map = [
        'mind_power'        => 'mind_power',
        'behavior'          => 'behavior',
        'behaviour'         => 'behavior',
        'general_awareness' => 'general_awareness',
        'language'          => 'language',
        'math'              => 'maths',
        'maths'             => 'maths',
    ];
    return $map[$key] ?? null;
}
