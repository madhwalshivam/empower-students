<?php
/**
 * includes/parent_reflect_home_climate.php
 *
 * Adds a "Home Climate" 5-axis card layer to the parent reflection report.
 *
 * Reads the existing sig_marital_stress / sig_in_law_stress / sig_parent_burnout /
 * sig_child_distress / sig_isolation columns (already computed by pr_finalise),
 * inverts them to 0-100 scores (higher = better), classifies into bands, and
 * augments each axis with a finding + concrete exercise.
 *
 * Finding generation: ONE Claude call per session, cached in the new
 * home_climate_cards_json column. Idempotent — re-running on a session that
 * already has cards is a no-op unless force=true.
 *
 * Function: home_climate_analyse(int $session_id, bool $force = false): bool
 * Reads:    parent_reflect_sessions row + parent_reflect_turns rows
 * Writes:   parent_reflect_sessions.home_climate_cards_json,
 *           parent_reflect_sessions.home_climate_analysed_at
 *
 * Helper for the view layer:
 *   home_climate_render_cards(string $cards_json): string
 *     Returns ready-to-insert HTML (Tailwind-styled) for the 5 cards.
 *
 * Cost: ~₹3 per session (one Sonnet call). Idempotent so re-renders are free.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/claude.php';


// ─────────────────────────────────────────────────────────────
// Schema: add cached column
// ─────────────────────────────────────────────────────────────
function _home_climate_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('home_climate_cards_json', $names, true)) {
            db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN home_climate_cards_json TEXT");
        }
        if (!in_array('home_climate_analysed_at', $names, true)) {
            db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN home_climate_analysed_at TEXT");
        }
    } catch (Throwable $e) {
        error_log('[home_climate ALTER] ' . $e->getMessage());
    }
    $done = true;
}


// ─────────────────────────────────────────────────────────────
// Axis metadata — UI labels and built-in fallback exercises
// ─────────────────────────────────────────────────────────────
function _home_climate_axes(): array {
    return [
        'couple_harmony' => [
            'sig_field'  => 'sig_marital_stress',
            'emoji'      => '💑',
            'label_en'   => 'Couple harmony',
            'label_hi'   => 'पति-पत्नी का साथ',
            'low_band_label_en' => 'Strained',
            'high_band_label_en' => 'Strong',
            'fallback_exercise_en' => 'This week, find one 10-minute slot — kids asleep, phones away — for an unhurried conversation with your partner. Not about parenting logistics. About them, or about you, or about a memory you both share. Often what helps couples carrying child-care weight is small windows where you remember each other as humans, not co-managers.',
            'fallback_exercise_hi' => 'इस हफ़्ते कोई एक 10-minute slot निकालें — बच्चे सो रहे हों, phone दूर हो — और partner से बिना rush के बात करें। बच्चे की planning नहीं, उनके बारे में, अपने बारे में, या कोई पुरानी याद। जो parents एक साथ बच्चे का बोझ उठा रहे हैं उन्हें ऐसी छोटी खिड़कियाँ बहुत मदद करती हैं जहाँ आप एक दूसरे को सिर्फ़ co-manager नहीं, इंसान की तरह याद कर सकें।',
        ],
        'joint_family' => [
            'sig_field'  => 'sig_in_law_stress',
            'emoji'      => '👵',
            'label_en'   => 'Joint family balance',
            'label_hi'   => 'घर-परिवार का संतुलन',
            'low_band_label_en' => 'Tense',
            'high_band_label_en' => 'Smooth',
            'fallback_exercise_en' => 'This week, pick one specific topic where you\'d like more space (your child\'s schedule, food choices, screen time — choose ONE). Practice a soft, clear sentence: "I appreciate the advice, and I\'d like to try our way for the next two weeks." Repeat it warmly if pushed. You\'re not rejecting; you\'re asking for room. One topic only.',
            'fallback_exercise_hi' => 'इस हफ़्ते एक specific topic चुनें जहाँ आप थोड़ा space चाहते हैं — बच्चे का schedule, खाने की पसंद, या screen time (सिर्फ़ एक चीज़ चुनें)। एक soft, clear वाक्य practice करें: "आपकी सलाह बहुत अच्छी है, मैं अगले दो हफ़्ते अपने तरीके से try करना चाहती हूँ।" अगर ज़ोर पड़े तो वही warmly दोहराएँ। आप मना नहीं कर रही हैं — सिर्फ़ थोड़ी जगह माँग रही हैं। एक topic ही, ज़्यादा नहीं।',
        ],
        'parent_wellbeing' => [
            'sig_field'  => 'sig_parent_burnout',
            'emoji'      => '🌱',
            'label_en'   => 'Your wellbeing',
            'label_hi'   => 'आपकी अपनी देखभाल',
            'low_band_label_en' => 'Depleted',
            'high_band_label_en' => 'Restored',
            'fallback_exercise_en' => 'This week, do one 10-minute thing that is purely for you — guilt-free. Not "self-care" as a brand. A walk without earphones. A cup of chai standing in the sun. A book chapter. The point is not the activity; the point is choosing one small window where you\'re not managing anyone. Children of restored parents are calmer — this is not selfish, it\'s structural.',
            'fallback_exercise_hi' => 'इस हफ़्ते कोई एक 10-minute चीज़ करें जो सिर्फ़ आपके लिए हो — बिना guilt के। "Self-care" वाला brand नहीं — असली काम। बिना earphone के walk। धूप में खड़े होकर चाय। एक chapter पढ़ना। काम क्या है यह matter नहीं करता; matter यह करता है कि आप एक छोटी सी खिड़की चुनें जहाँ आप किसी और को manage नहीं कर रहीं। जिन parents की अपनी ज़रूरतें थोड़ी पूरी होती हैं, उनके बच्चे ज़्यादा शांत रहते हैं — यह selfish नहीं, structural है।',
        ],
        'child_climate' => [
            'sig_field'  => 'sig_child_distress',
            'emoji'      => '🧒',
            'label_en'   => "Child's emotional climate",
            'label_hi'   => 'बच्चे का मनोभाव',
            'low_band_label_en' => 'Distressed',
            'high_band_label_en' => 'Settled',
            'fallback_exercise_en' => 'This week, replace one "don\'t do that" with naming the feeling first. When your child does something that frustrates you, before reacting, try: "I can see you\'re feeling [tired / angry / left out]. Let\'s figure this out together." You\'re not approving the behaviour — you\'re labelling the inside state, which is what actually settles a dysregulated child. One swap a day. Small.',
            'fallback_exercise_hi' => 'इस हफ़्ते एक "मत करो" को feeling को नाम देने से बदलें। जब बच्चा कुछ ऐसा करे जो आपको परेशान करे, react करने से पहले try करें: "मुझे दिख रहा है तुम्हें [थकान / गुस्सा / अकेला] महसूस हो रहा है। हम मिलकर सोचते हैं।" आप behaviour approve नहीं कर रही हैं — आप उसके अंदर की feeling को नाम दे रही हैं, और यही dysregulated बच्चे को settle करता है। दिन में एक swap। छोटा सा।',
        ],
        'support_network' => [
            'sig_field'  => 'sig_isolation',
            'emoji'      => '🤝',
            'label_en'   => 'Support around you',
            'label_hi'   => 'आपके आस-पास का साथ',
            'low_band_label_en' => 'Alone',
            'high_band_label_en' => 'Supported',
            'fallback_exercise_en' => 'This week, reach out to one specific person — a friend you haven\'t spoken to properly in months, a cousin, an old colleague. One WhatsApp message: "Was thinking of you. How are you doing?" That\'s it. No agenda. You are not asking for help. You are widening the circle by one. Isolation isn\'t fixed by big interventions; it\'s loosened by tiny consistent threads.',
            'fallback_exercise_hi' => 'इस हफ़्ते एक specific इंसान को message करें — कोई दोस्त जिससे महीनों से proper बात नहीं हुई, cousin, या पुराने colleague। एक WhatsApp message: "तुम्हारी याद आ रही थी। कैसे हो?" बस। कोई agenda नहीं। आप help नहीं माँग रहीं। बस अपना circle एक से बढ़ा रही हैं। अकेलापन बड़े interventions से नहीं, छोटे-छोटे consistent धागों से ढीला होता है।',
        ],
    ];
}


// ─────────────────────────────────────────────────────────────
// Scoring + bands
// ─────────────────────────────────────────────────────────────
/**
 * Convert a 0-1 stress signal into a 0-100 "harmony" score and a band.
 *
 * Stress 0.0 (no issue) → score 95 ("Strong")
 * Stress 0.5 (moderate) → score 50 ("Developing")
 * Stress 1.0 (severe)   → score 5 ("Strained")
 */
function _home_climate_score_band(float $signal): array {
    // Cap inputs
    $signal = max(0.0, min(1.0, $signal));
    // Invert: high stress → low score
    $score = (int) round(100 - ($signal * 100));
    // Smooth the extremes so even a "clean" reading doesn't land at 100 (overclaim)
    if ($score >= 95) $score = 92;
    if ($score <= 5)  $score = 8;

    if ($score >= 80)      $band = 'strong';
    elseif ($score >= 65)  $band = 'holding';
    elseif ($score >= 45)  $band = 'developing';
    elseif ($score >= 25)  $band = 'needs_care';
    else                   $band = 'strained';

    $band_labels = [
        'strong'      => 'Strong',
        'holding'     => 'Holding well',
        'developing'  => 'Developing',
        'needs_care'  => 'Needs care',
        'strained'    => 'Under strain',
    ];

    return [
        'score' => $score,
        'band'  => $band,
        'band_label' => $band_labels[$band],
    ];
}


// ─────────────────────────────────────────────────────────────
// Main analysis function
// ─────────────────────────────────────────────────────────────
/**
 * Generate the 5-axis cards for a completed reflection session.
 *
 * Pulls session + turns, computes scores from existing sig_* columns,
 * calls Claude ONCE to generate per-axis findings + tailored exercises,
 * saves to home_climate_cards_json. Idempotent: skip if already done
 * unless $force=true.
 *
 * Returns true on success.
 */
function home_climate_analyse(int $session_id, bool $force = false): bool {
    _home_climate_ensure_columns();

    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) {
        error_log("[home_climate] session $session_id not found");
        return false;
    }
    if ($session['status'] !== 'completed') {
        error_log("[home_climate] session $session_id not completed (status={$session['status']})");
        return false;
    }
    if (!$force && !empty($session['home_climate_cards_json'])) {
        return true;  // already done
    }

    // Build axis snapshot from existing sig_* columns
    $axes = _home_climate_axes();
    $axis_scores = [];
    foreach ($axes as $key => $meta) {
        $signal_raw = (float) ($session[$meta['sig_field']] ?? 0.0);
        $sb = _home_climate_score_band($signal_raw);
        $axis_scores[$key] = [
            'signal_raw' => $signal_raw,
            'score'      => $sb['score'],
            'band'       => $sb['band'],
            'band_label' => $sb['band_label'],
        ];
    }

    // Determine language from parent's children
    $cs = db()->prepare("SELECT mother_tongue FROM children WHERE parent_id = ?");
    $cs->execute([(int)$session['parent_id']]);
    $hi = 0; $en = 0; $kids_count = 0;
    while ($row = $cs->fetch()) {
        $kids_count++;
        $mt = strtolower(trim((string)$row['mother_tongue']));
        if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi++; else $en++;
    }
    $language = ($hi >= $en && $hi > 0) ? 'hi' : ($en > 0 ? 'en' : 'hi');

    // Pull all turns for context
    $tst = db()->prepare("SELECT question, transcript, emotions_json
                          FROM parent_reflect_turns
                          WHERE session_id = ? AND transcript IS NOT NULL
                          ORDER BY turn_no ASC");
    $tst->execute([$session_id]);
    $turns = $tst->fetchAll();

    // Build conversation context for Claude
    $convo = [];
    foreach ($turns as $t) {
        $q = trim((string)$t['question']);
        $a = trim((string)$t['transcript']);
        if ($q !== '') $convo[] = "AI: " . mb_substr($q, 0, 200);
        if ($a !== '') $convo[] = "Parent: " . mb_substr($a, 0, 400);
    }
    $convo_text = implode("\n", $convo);
    if (mb_strlen($convo_text) > 6000) {
        $convo_text = mb_substr($convo_text, 0, 6000) . "\n[...transcript truncated]";
    }

    // Build axis input for Claude
    $axis_input_lines = [];
    foreach ($axes as $key => $meta) {
        $s = $axis_scores[$key];
        $label = $language === 'hi' ? $meta['label_hi'] : $meta['label_en'];
        $axis_input_lines[] = sprintf("- %s (%s): score %d/100 (%s)",
            $key, $label, $s['score'], $s['band_label']);
    }
    $axis_input = implode("\n", $axis_input_lines);

    // ── Claude prompt: produce JSON with per-axis finding + exercise ──
    $lang_block = $language === 'hi'
        ? "Write findings and exercises in WARM CONVERSATIONAL HINDI (Devanagari, 'आप' respectful). English words for modern concepts (therapy, anxiety, support, screen time) are fine — code-switching is natural in Indian homes."
        : "Write findings and exercises in warm, conversational English.";

    $sys = <<<SYS
You are a senior family psychologist summarising a parent's voice reflection into a structured 5-axis "Home Climate" report.

{$lang_block}

You are given the per-axis numeric scores (already computed from the conversation's emotional signals) and the full conversation transcript. Your job: for EACH of the 5 axes, produce:
  - "finding": 2-3 sentence specific observation grounded in what the parent ACTUALLY SAID. Use 1-2 word phrases from their answers where natural. Do NOT speculate beyond what was shared. Honest, never preachy.
  - "exercise": ONE concrete thing to try this week, ~10 minutes or less. Specific, doable, framed as "you might try..." or "one thing that often helps..." — never "you should". India-aware (joint families, izzat, log kya kahenge, financial reality of therapy).

CRITICAL RULES:
- If a score is 80+ (Strong/Holding), the finding should NAME a strength the parent demonstrated. Don't manufacture problems.
- If a score is below 45 (Developing/Needs care/Strained), the finding can be honest about what's hard, but ALWAYS validates before suggesting.
- Never diagnose. Never label.
- The exercise must be specific to this parent's situation as revealed in the transcript. Generic advice ("do self-care") = failure. Pull a hook from their words.
- Avoid lists in exercises. One coherent paragraph each.

Output JSON ONLY in this shape:
{
  "couple_harmony":   { "finding": "...", "exercise": "..." },
  "joint_family":     { "finding": "...", "exercise": "..." },
  "parent_wellbeing": { "finding": "...", "exercise": "..." },
  "child_climate":    { "finding": "...", "exercise": "..." },
  "support_network":  { "finding": "...", "exercise": "..." },
  "weakest_axis": "key of the lowest-scored axis",
  "strongest_axis": "key of the highest-scored axis"
}
SYS;

    $usr = "=== Axis scores (already computed) ===\n"
         . $axis_input
         . "\n\n=== Conversation transcript ===\n"
         . $convo_text
         . "\n\nProduce the structured JSON now. JSON only, no preamble.";

    $resp = function_exists('claude_chat')
        ? claude_chat($sys, [['role' => 'user', 'content' => $usr]], 2000, 0.5)
        : '';

    if (trim((string)$resp) === '') {
        error_log("[home_climate] empty Claude response for session $session_id");
        return _home_climate_save_fallback($session_id, $axis_scores, $axes, $language);
    }

    // Strip code fences if any
    $clean = trim((string)$resp);
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }

    $parsed = json_decode($clean, true);
    if (!is_array($parsed)) {
        error_log("[home_climate] could not parse Claude JSON for session $session_id");
        return _home_climate_save_fallback($session_id, $axis_scores, $axes, $language);
    }

    // Build final cards structure
    $cards = [];
    foreach ($axes as $key => $meta) {
        $s = $axis_scores[$key];
        $ai_block = $parsed[$key] ?? [];
        $finding = trim((string)($ai_block['finding'] ?? ''));
        $exercise = trim((string)($ai_block['exercise'] ?? ''));
        if ($exercise === '') {
            $exercise = $language === 'hi' ? $meta['fallback_exercise_hi'] : $meta['fallback_exercise_en'];
        }
        $cards[$key] = [
            'emoji'      => $meta['emoji'],
            'label'      => $language === 'hi' ? $meta['label_hi'] : $meta['label_en'],
            'score'      => $s['score'],
            'band'       => $s['band'],
            'band_label' => $s['band_label'],
            'finding'    => $finding,
            'exercise'   => $exercise,
        ];
    }

    $payload = [
        'cards'          => $cards,
        'weakest_axis'   => $parsed['weakest_axis']  ?? null,
        'strongest_axis' => $parsed['strongest_axis'] ?? null,
        'language'       => $language,
        'kids_count'     => $kids_count,
        'analysed_at'    => gmdate('Y-m-d H:i:s'),
    ];

    db()->prepare("UPDATE parent_reflect_sessions
                   SET home_climate_cards_json = ?, home_climate_analysed_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $session_id]);

    return true;
}

/**
 * Save fallback cards when Claude is unavailable or returns garbage.
 * Uses hardcoded exercises + a generic finding referencing the score.
 */
function _home_climate_save_fallback(int $session_id, array $axis_scores, array $axes, string $language): bool {
    $cards = [];
    foreach ($axes as $key => $meta) {
        $s = $axis_scores[$key];
        $label = $language === 'hi' ? $meta['label_hi'] : $meta['label_en'];
        $cards[$key] = [
            'emoji'      => $meta['emoji'],
            'label'      => $label,
            'score'      => $s['score'],
            'band'       => $s['band'],
            'band_label' => $s['band_label'],
            'finding'    => $language === 'hi'
                ? sprintf("इस axis पर आपका score %d/100 है — %s।", $s['score'], $s['band_label'])
                : sprintf("Your score on this axis is %d/100 — %s.", $s['score'], $s['band_label']),
            'exercise'   => $language === 'hi' ? $meta['fallback_exercise_hi'] : $meta['fallback_exercise_en'],
        ];
    }
    $payload = [
        'cards' => $cards,
        'language' => $language,
        'fallback' => true,
        'analysed_at' => gmdate('Y-m-d H:i:s'),
    ];
    db()->prepare("UPDATE parent_reflect_sessions
                   SET home_climate_cards_json = ?, home_climate_analysed_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $session_id]);
    return true;
}


// ─────────────────────────────────────────────────────────────
// VIEW LAYER — render cards as HTML for insertion in parent-reflect.php
// ─────────────────────────────────────────────────────────────
/**
 * Returns HTML for the 5-axis card grid given the cards_json from the
 * parent_reflect_sessions row. Self-contained — no external CSS dependency
 * beyond the Tailwind classes already loaded site-wide.
 */
function home_climate_render_cards(?string $cards_json): string {
    if (empty($cards_json)) return '';
    $data = json_decode($cards_json, true);
    if (!is_array($data) || empty($data['cards'])) return '';
    $cards = $data['cards'];
    $lang  = $data['language'] ?? 'en';

    // Band → color classes
    $band_colors = [
        'strong'      => ['ring' => 'ring-emerald-300', 'badge_bg' => 'bg-emerald-100', 'badge_text' => 'text-emerald-800', 'score' => 'text-emerald-700'],
        'holding'     => ['ring' => 'ring-teal-300',    'badge_bg' => 'bg-teal-100',    'badge_text' => 'text-teal-800',    'score' => 'text-teal-700'],
        'developing'  => ['ring' => 'ring-amber-300',   'badge_bg' => 'bg-amber-100',   'badge_text' => 'text-amber-800',   'score' => 'text-amber-700'],
        'needs_care'  => ['ring' => 'ring-orange-300',  'badge_bg' => 'bg-orange-100',  'badge_text' => 'text-orange-800',  'score' => 'text-orange-700'],
        'strained'    => ['ring' => 'ring-rose-300',    'badge_bg' => 'bg-rose-100',    'badge_text' => 'text-rose-800',    'score' => 'text-rose-700'],
    ];

    $header_label = $lang === 'hi' ? 'घर का माहौल — 5 axes पर' : 'Home Climate — across 5 dimensions';
    $exercise_label = $lang === 'hi' ? 'इस हफ़्ते try करें:' : 'Try this week:';

    $html  = '<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">';
    $html .= '  <h3 class="text-base font-bold text-slate-900 mb-1">' . htmlspecialchars($header_label) . '</h3>';
    $html .= '  <p class="text-xs text-slate-500 mb-4">' . ($lang === 'hi'
        ? 'आपकी आज की reflection के आधार पर'
        : 'Based on your reflection today') . '</p>';
    $html .= '  <div class="space-y-3">';

    foreach ($cards as $key => $c) {
        $colors = $band_colors[$c['band']] ?? $band_colors['developing'];
        $html .= '<div class="border border-slate-200 rounded-xl p-4 ring-1 ' . $colors['ring'] . '">';
        $html .=   '<div class="flex items-start justify-between mb-2">';
        $html .=     '<div class="flex items-center gap-2">';
        $html .=       '<span class="text-2xl">' . $c['emoji'] . '</span>';
        $html .=       '<span class="font-semibold text-slate-900">' . htmlspecialchars($c['label']) . '</span>';
        $html .=     '</div>';
        $html .=     '<div class="text-right">';
        $html .=       '<div class="text-2xl font-bold ' . $colors['score'] . '">' . (int)$c['score'] . '</div>';
        $html .=       '<div class="text-[10px] uppercase tracking-wider font-bold ' . $colors['badge_bg'] . ' ' . $colors['badge_text'] . ' px-2 py-0.5 rounded inline-block">' . htmlspecialchars($c['band_label']) . '</div>';
        $html .=     '</div>';
        $html .=   '</div>';
        if (!empty($c['finding'])) {
            $html .= '<p class="text-sm text-slate-700 leading-relaxed mb-2">' . htmlspecialchars($c['finding']) . '</p>';
        }
        if (!empty($c['exercise'])) {
            $html .= '<div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mt-2">';
            $html .=   '<div class="text-[11px] uppercase tracking-wider font-bold text-amber-800 mb-1">' . htmlspecialchars($exercise_label) . '</div>';
            $html .=   '<p class="text-xs text-amber-900 leading-relaxed">' . htmlspecialchars($c['exercise']) . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    $html .= '  </div>';
    $html .= '</div>';
    return $html;
}
