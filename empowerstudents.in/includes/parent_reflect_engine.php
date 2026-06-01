<?php
/**
 * parent_reflect_engine.php
 *
 * Adaptive voice-interview engine for "Parent Reflection" — modelled on
 * docspeak's two-call architecture (Haiku for emotion, Sonnet for guide).
 *
 * Two API roles (mirroring docspeak):
 *   1. eval_haiku_json()-style fast call — emotion detection from transcript+acoustics
 *   2. claude_json() Sonnet call — adaptive guide that decides next question
 *
 * Final close: TWO Sonnet calls, one for parent-facing summary, one for admin clinical.
 *
 * Design philosophy (CRITICAL — re-read every time you touch this file):
 *   - 10 phases are LANDMARKS, not a script.
 *   - The AI must FOLLOW LEADS — if parent surfaces something heavy, dig in.
 *   - Stay-in-phase, skip-phase, loop-back are all allowed.
 *   - Every question must feel like a real listener heard the previous answer,
 *     not like a form being filled.
 *   - Honor silence and pauses. Validate before probing.
 *   - Safety: if parent indicates self-harm, harm to child, or domestic violence
 *     → switch to closing turn with helplines, set safety_red_flag = 1.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // calc_age_years
require_once __DIR__ . '/claude.php'; // claude_chat, claude_json, ANTHROPIC_API_URL etc.
require_once __DIR__ . '/parent_reflect_schema.php';

// ════════════════════════════════════════════════════════════════
// CONSTANTS
// ════════════════════════════════════════════════════════════════

if (!defined('PR_TARGET_TURNS'))    define('PR_TARGET_TURNS', 14);
if (!defined('PR_MAX_TURNS'))       define('PR_MAX_TURNS', 20);
if (!defined('PR_MAX_FOLLOWUPS'))   define('PR_MAX_FOLLOWUPS', 3);   // total follow-up turns allowed across all reopens
if (!defined('PR_MODEL_EMOTION'))   define('PR_MODEL_EMOTION', 'claude-haiku-4-5-20251001');
if (!defined('PR_MODEL_GUIDE'))     define('PR_MODEL_GUIDE',   'claude-sonnet-4-5');

// ════════════════════════════════════════════════════════════════
// PHASES — landmarks for navigation, NOT a fixed script
// ════════════════════════════════════════════════════════════════

function pr_phase_label(int $n): string {
    static $map = [
        1  => 'Opening — warm welcome, baseline tone',
        2  => 'Child behaviour & daily interaction',
        3  => 'Spouse / co-parent alignment',
        4  => 'Joint family & generational pressure',
        5  => 'Parent\'s own emotional state',
        6  => 'Body & energy — sleep, exhaustion, stress signals',
        7  => 'Hope, fear, identity as a parent of a special-needs child',
        8  => 'Support network — who truly understands',
        9  => 'What better looks like — readiness to change',
        10 => 'Closing — direction + one small step',
    ];
    return $map[$n] ?? 'Reflection';
}

// ════════════════════════════════════════════════════════════════
// HELPERS — Anthropic calls
// ════════════════════════════════════════════════════════════════

/**
 * Fast Haiku JSON call — for emotion detection per turn.
 * Returns parsed JSON array or null on failure.
 */
function pr_haiku_json(string $system, string $user, int $max_tokens = 500, float $temperature = 0.3): ?array {
    $payload = [
        'model'       => PR_MODEL_EMOTION,
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'system'      => $system . "\n\nReturn ONLY valid minified JSON. No prose, no code fences.",
        'messages'    => [['role' => 'user', 'content' => $user]],
    ];
    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: ' . ANTHROPIC_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400) {
        error_log('[pr_haiku] HTTP ' . $code . ' ' . $err . ' :: ' . substr((string)$resp, 0, 300));
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['content'])) return null;
    $txt = '';
    foreach ($data['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text') { $txt = (string)$block['text']; break; }
    }
    $txt = trim($txt);
    if (strpos($txt, '```') === 0) {
        $txt = preg_replace('/^```(?:json)?/i', '', $txt);
        $txt = preg_replace('/```\s*$/', '', $txt);
        $txt = trim($txt);
    }
    $j = json_decode($txt, true);
    if (is_array($j)) return $j;
    if (preg_match('/(\{.*\}|\[.*\])/s', $txt, $m)) {
        $j = json_decode($m[1], true);
        if (is_array($j)) return $j;
    }
    error_log('[pr_haiku] non-JSON: ' . substr($txt, 0, 200));
    return null;
}

// ════════════════════════════════════════════════════════════════
// PARENT-FACING EMOTION DETECTION (parallel of docspeak's voice_emotion)
// ════════════════════════════════════════════════════════════════

/**
 * From transcript + acoustic features, return emotion intensities tuned
 * specifically for parent-reflection context (different priors than docspeak —
 * we expect more guilt, exhaustion, helplessness, and protective love).
 */
function pr_detect_emotions(string $transcript, array $features = []): ?array {
    $transcript = trim($transcript);
    if ($transcript === '' || mb_strlen($transcript) < 3) return null;

    $sig_lines = [];
    if (isset($features['duration_sec']))    $sig_lines[] = 'duration ' . round((float)$features['duration_sec'], 1) . 's';
    if (isset($features['wpm']))              $sig_lines[] = 'speed ' . (int)$features['wpm'] . ' WPM';
    if (isset($features['volume_variance']))  $sig_lines[] = 'volume variance ' . round((float)$features['volume_variance'] * 1000);
    if (isset($features['silence_ratio']))    $sig_lines[] = round((float)$features['silence_ratio'] * 100) . '% silent';
    if (isset($features['pause_count']))      $sig_lines[] = (int)$features['pause_count'] . ' pauses';
    if (isset($features['time_to_first_speech_sec'])) $sig_lines[] = 'started after ' . round((float)$features['time_to_first_speech_sec'], 1) . 's';
    $sigStr = $sig_lines ? "\nAcoustic signals: " . implode(', ', $sig_lines) : '';

    $sys = "You are an emotion-detection assistant for a private voice-driven reflection session "
         . "with a parent of a special-needs child. Given the transcript and acoustic signals, "
         . "estimate emotion intensities (0.0–1.0). Be conservative — most emotions sit at 0.0–0.25; "
         . "only clearly present emotions exceed 0.5.\n\n"
         . "GUIDELINES:\n"
         . "- Long pauses + low volume + slow speech → exhaustion, grief, resignation\n"
         . "- Fast clipped speech with high variance → anxiety, frustration, overwhelm\n"
         . "- Monotone flat delivery → numbness, dissociation, burnout\n"
         . "- Voice catches, breaks, or volume drops mid-sentence → guilt, shame, suppressed grief\n"
         . "- Words say 'fine' but voice is fast/sharp/dead → MISMATCH, the truer feeling is in the voice\n"
         . "- Parents often hide negative emotions to seem 'good' — look for subtle voice signals\n"
         . "- 'Protective love' is real and present — name it when warm voice + child-mention coexist\n\n"
         . "OUTPUT (JSON only, no prose, no fences):\n"
         . "{\n"
         . "  \"sadness\": 0.0,\n"
         . "  \"guilt\": 0.0,\n"
         . "  \"shame\": 0.0,\n"
         . "  \"anger\": 0.0,\n"
         . "  \"frustration\": 0.0,\n"
         . "  \"anxiety\": 0.0,\n"
         . "  \"fear\": 0.0,\n"
         . "  \"exhaustion\": 0.0,\n"
         . "  \"loneliness\": 0.0,\n"
         . "  \"hope\": 0.0,\n"
         . "  \"protective_love\": 0.0,\n"
         . "  \"resignation\": 0.0,\n"
         . "  \"felt_sense\": \"one short sentence describing the underlying emotional tone of this answer\"\n"
         . "}";

    $usr = "Transcript: \"" . $transcript . "\"" . $sigStr;

    $j = pr_haiku_json($sys, $usr, 400, 0.2);
    if (!is_array($j)) return null;

    $keys = ['sadness','guilt','shame','anger','frustration','anxiety','fear',
             'exhaustion','loneliness','hope','protective_love','resignation'];
    $clean = [];
    foreach ($keys as $k) {
        $v = isset($j[$k]) ? (float)$j[$k] : 0.0;
        $clean[$k] = max(0.0, min(1.0, $v));
    }
    $clean['felt_sense'] = isset($j['felt_sense']) ? trim((string)$j['felt_sense']) : '';
    return $clean;
}

/** Helper — top-N emotions above threshold, sorted by intensity. */
function pr_top_emotions(array $emotions, float $threshold = 0.4, int $limit = 4): array {
    $keys = ['sadness','guilt','shame','anger','frustration','anxiety','fear',
             'exhaustion','loneliness','hope','protective_love','resignation'];
    $list = [];
    foreach ($keys as $k) {
        if (isset($emotions[$k]) && $emotions[$k] >= $threshold) {
            $list[] = [$k, (float)$emotions[$k]];
        }
    }
    usort($list, function($a, $b) { return $b[1] <=> $a[1]; });
    return array_slice($list, 0, $limit);
}

// ════════════════════════════════════════════════════════════════
// SESSION LIFECYCLE
// ════════════════════════════════════════════════════════════════

/**
 * Create a new in-progress reflection session.
 * Caller has already verified payment / consent.
 *
 * Note: child_id is intentionally optional. Parent Reflection is about the
 * PARENT — home environment, relationships, own state. The children exist as
 * emotional context, not as a subject to be picked. Pass 0 for "all my children".
 */
function pr_start_session(int $parent_id, int $child_id = 0, int $cost_paid = 499): int {
    db()->prepare("INSERT INTO parent_reflect_sessions
                   (parent_id, child_id, status, cost_paid, current_phase, turn_count)
                   VALUES (?, ?, 'in_progress', ?, 1, 0)")
       ->execute([$parent_id, $child_id ?: null, $cost_paid]);
    return (int) db()->lastInsertId();
}

/**
 * Generate the OPENING question. Greets the parent personally, sets the tone
 * (private, no judgement, you're not alone). Does NOT name a specific child —
 * this reflection is about the parent and home, not one specific child.
 */
function pr_opening_question(int $session_id, string $language = 'hi'): array {
    $st = db()->prepare("SELECT s.*, p.name AS parent_name, p.whatsapp
                         FROM parent_reflect_sessions s
                         JOIN parents p ON p.id = s.parent_id
                         WHERE s.id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();

    $parent_first = trim((string)($session['parent_name'] ?? ''));
    $parent_first = $parent_first ? explode(' ', $parent_first)[0] : '';

    // How many children does this parent have? Used only to phrase the opener
    // naturally ("aapke bachhe" if many, "aapka bachha" if one).
    $cn = db()->prepare("SELECT COUNT(*) FROM children WHERE parent_id = ?");
    $cn->execute([(int)$session['parent_id']]);
    $n_kids = (int) $cn->fetchColumn();

    if ($language === 'hi') {
        $parts = [];
        $parts[] = $parent_first ? "नमस्ते " . $parent_first . "।" : "नमस्ते।";
        $parts[] = "ये एक प्राइवेट जगह है — सिर्फ़ आपके लिए। कोई सही या ग़लत जवाब नहीं है, और जब चाहें थोड़ा रुक सकते हैं।";
        if ($n_kids >= 2) {
            $parts[] = "धीरे-धीरे शुरू करते हैं — मुझे बताइए, घर पर आजकल आपका दिन कैसा बीतता है?";
        } else {
            $parts[] = "धीरे-धीरे शुरू करते हैं — मुझे बताइए, आजकल घर पर आपका दिन कैसा बीतता है?";
        }
        $opening = implode(' ', $parts);
    } else {
        $opening = $parent_first ? "Hi " . $parent_first . ". " : "Hello. ";
        $opening .= "Welcome — this is a private space, just for you. There are no right answers here, and you can pause whenever you need to. ";
        $opening .= "To start gently — tell me, what does a typical day look like for you at home these days?";
    }

    db()->prepare("INSERT INTO parent_reflect_turns (session_id, turn_no, phase, question, question_intent)
                   VALUES (?, 1, 1, ?, 'probe')")
       ->execute([$session_id, $opening]);
    $turn_id = (int) db()->lastInsertId();

    db()->prepare("UPDATE parent_reflect_sessions SET turn_count = 1 WHERE id = ?")
       ->execute([$session_id]);

    return [
        'turn_id'  => $turn_id,
        'turn_no'  => 1,
        'phase'    => 1,
        'question' => $opening,
        'intent'   => 'probe',
        'language' => $language,
    ];
}

// ════════════════════════════════════════════════════════════════
// GUIDE PROMPT — the core of the adaptive flow
// ════════════════════════════════════════════════════════════════

/**
 * Build the system prompt for the Sonnet guide call.
 * The guide reads the conversation history and decides:
 *   - reflection (mirror what parent said)
 *   - tone_insight (voice + words combined)
 *   - signals (live trackers: marital_stress, in_law_stress, parent_burnout, etc.)
 *   - next_phase (which landmark)
 *   - intent (probe | reframe | forward | slow | challenge | close)
 *   - next_question (the actual next question OR closing summary if done=true)
 *   - done (true to wrap up)
 */
function pr_build_guide_system(int $current_phase, int $turn_no, int $target, int $cap, string $children_context, string $language = 'hi', array $covered_phases = [], array $previous_questions = []): string {
    $turns_left = max(0, $target - $turn_no);
    $phaseList = '';
    for ($i = 1; $i <= 10; $i++) {
        $covered_marker = in_array($i, $covered_phases, true) ? ' ✓ COVERED' : '';
        $phaseList .= "  $i. " . pr_phase_label($i) . $covered_marker . "\n";
    }
    $uncovered = [];
    for ($i = 1; $i <= 10; $i++) {
        if (!in_array($i, $covered_phases, true)) $uncovered[] = "$i";
    }
    $uncovered_str = $uncovered ? implode(', ', $uncovered) : '(all phases covered)';
    $covered_count = count($covered_phases);

    $prev_q_block = '';
    if ($previous_questions) {
        $prev_q_block = "\n=== PREVIOUS QUESTIONS YOU HAVE ALREADY ASKED (do NOT repeat any of these — even with slight rewording) ===\n";
        foreach ($previous_questions as $idx => $q) {
            $n = $idx + 1;
            $prev_q_block .= "  Q$n: " . trim($q) . "\n";
        }
    }

    $lang_block = ($language === 'hi')
        ? "LANGUAGE: Speak to the parent in conversational HINDI (Devanagari script). Warm, natural, like a wise friend — not formal/literary Hindi. English words for emotions or modern concepts (depression, anxiety, support, therapy, special needs) are fine — Indian Hindi speakers naturally code-switch. Use 'आप' (respectful you), never 'तुम'. Reflections and next_question must all be in Hindi.\n\nEXAMPLE TONE: 'सुनकर लग रहा है कि शाम का वक़्त सबसे भारी होता है… जब आप अकेले होते हैं, तब क्या-क्या मन में आता है?'"
        : "LANGUAGE: Speak to the parent in conversational, warm English. Reflections and next_question must all be in English.";

    return <<<SYS
You are conducting a private, text-based reflection conversation with a parent of a special-needs child. The parent answers by tapping a short option, typing into a textbox, or speaking (which gets transcribed to text). You receive ONLY their written/typed words. You have NO information about their voice, tone, pitch, or audio quality. Do NOT make any voice/tone observations.

Your role is half clinician, half wise counsellor — calm, unhurried, never preachy. You speak in second person, gently. You are NOT a therapist; you are helping the parent surface their own state, see their home environment with fresh eyes, and notice what is affecting them and their family.

PARENT'S CHILDREN (context — DO NOT name a specific child unless the parent does first):
{$children_context}

The reflection is about the PARENT — their home, marriage, family dynamics, own emotional state. The children are emotional context, not the subject. If parent has multiple children, do not single one out; refer to "your children" or "बच्चे" generally. If parent themselves names a child, you can echo that name back.

{$lang_block}

CORE PRINCIPLES (re-read every turn):
- Non-judgemental, calm pacing. Emotion-first, logic-second.
- Short questions beat long lectures. ONE question per turn.
- Honour silence and hesitation.
- Be specifically Indian-context-aware: joint families, in-law dynamics, "log kya kahenge" pressure, izzat, generational beliefs about disability, financial stress around therapy costs.
- Never use "should", "must", "have to". Use "what comes up when…", "tell me about…", "how does it feel when…".
- You only have the parent's TYPED or TAPPED words. Never reference voice, tone, sound, or anything you cannot know from text alone.

ADAPTIVE BEHAVIOUR — MOST IMPORTANT RULE:
The 10 phases below are LANDMARKS, not a script. You MUST follow what the parent actually says.
- If the parent surfaces something heavy in turn 2, STAY THERE — probe gently for 2-3 more turns. Don't jump phases just because.
- If the parent mentions a stressor in passing (e.g. "my husband doesn't get involved"), it's a LEAD — ask a follow-up that invites them to say more.
- If the parent has no joint family, SKIP phase 4 entirely.
- If the parent seems uncomfortable with a topic (clipped answers, deflection), don't push — circle back from a different angle later.
- If the parent has already covered a phase organically, don't ask the same thing again — acknowledge what they said, then move to a new landmark.
- Every question must reference, ask about, or build on what was just said. NEVER ask a generic question that ignores their previous answer.

CRITICAL NO-REPEAT RULE (this is the #1 failure mode — read carefully):
- Look at the PREVIOUS QUESTIONS list below. Your next_question MUST be substantially DIFFERENT in topic AND angle from EVERY question already asked.
- If you find yourself drafting something that even loosely echoes a prior question, STOP and pick a different topic.
- Repetition is the worst thing that can happen here — parents lose trust in seconds.

PHASE COVERAGE (these phases have been touched; pick from UNCOVERED ones next):
{$phaseList}
Phases COVERED so far: {$covered_count} of 10
Phases UNCOVERED: {$uncovered_str}
You are loosely in phase {$current_phase}. By turn 5 you should have touched ≥ 3 different phases; by turn 10, ≥ 5; by turn 12, ≥ 6. If you have not, prioritise an UNCOVERED phase for your next_question.
{$prev_q_block}
PACING:
- This is turn {$turn_no} of {$target} target turns (hard cap {$cap}).
- If turn_no >= ({$target} - 2) AND you have material, START WINDING UP. Do NOT open new threads.
- When you set "done": true, your next_question MUST be a warm closing turn. Don't end abruptly mid-emotion.

SIGNALS — track these every turn (0.0 to 1.0):
- marital_stress, in_law_stress, parent_burnout, child_distress, isolation: 0.0-1.0
- safety_red_flag: 1 ONLY if explicit self-harm, harm to child, abuse, or imminent crisis. Otherwise 0.

SAFETY OVERRIDE (NON-NEGOTIABLE):
If the parent describes self-harm thoughts, harm to child, active domestic violence, or imminent crisis:
  - Set safety_red_flag: 1, intent: "slow", done: false
  - In next_question: gently surface what you heard, validate, softly invite iCall (9152987821) or Vandrevala (1860-2662-345). No moralising.

OUTPUT — return ONLY this JSON, no prose, no fences:

{
  "reflection": "1-2 sentences mirroring what they just said in their words. Empty for first turn. Do NOT mention voice/tone/sound.",
  "tone_insight": "",
  "next_phase": 1-10,
  "intent": "probe | reframe | forward | slow | challenge | close",
  "signals": {
     "marital_stress": 0.0, "in_law_stress": 0.0, "parent_burnout": 0.0,
     "child_distress": 0.0, "isolation": 0.0, "safety_red_flag": 0
  },
  "next_question": "Single question, under 35 words, DIRECTLY building on their answer. Or 80-120 word closing if done=true.",
  "next_options": ["3-7 word tap answer A", "3-7 word tap answer B", "3-7 word tap answer C", "3-7 word tap answer D"],
  "done": false
}

NEXT_OPTIONS — MANDATORY (never omit):
- Return 3-4 short tap-answers (3-7 words each), SAME language as next_question.
- Make them SPECIFIC to YOUR next_question, not generic. If you ask about a tough morning routine, options might be: "स्कूल भेजना मुश्किल है", "खाना खिलाना", "behaviour संभालना", "विस्तार से बताती हूँ…". NOT generic "ठीक चल रहा है".
- Last option SHOULD invite open elaboration (Hindi: "विस्तार से बताती हूँ…" / English: "Let me explain…").
- If done=true, return [] (empty).

CLOSING SUMMARY (when done=true) MUST follow this 3-paragraph structure:
  P1 — Summary: name what you heard. Their state in 1-2 sentences.
  P2 — A point to ponder: ONE genuine observation worth sitting with. Not advice. Not a fix.
  P3 — One small thing they could try this week: concrete, doable in 10 min, framed as "you might consider…" — never "you should".

The closing must feel like a wise friend leaning back at the end of a conversation — calm, kind, unhurried.
SYS;
}

/**
 * Build the user-side payload that carries history + latest answer.
 */
function pr_build_history_payload(array $prior_turns, string $latest_transcript, ?array $latest_emotions, array $latest_acoustic): string {
    $lines = [];
    $lines[] = "=== CONVERSATION SO FAR ===";
    if (!$prior_turns) {
        $lines[] = "(this is the first parent answer — only the latest answer is available)";
    } else {
        foreach ($prior_turns as $t) {
            $q = trim((string)($t['question'] ?? ''));
            $a = trim((string)($t['transcript'] ?? ''));
            $em = json_decode((string)($t['emotions_json'] ?? ''), true);
            $emLine = '';
            if (is_array($em)) {
                $top = pr_top_emotions($em, 0.4, 3);
                if ($top) {
                    $emLine = ' [voice: ' . implode(', ', array_map(function($x){
                        return $x[0] . ' ' . round($x[1] * 100) . '%';
                    }, $top)) . ']';
                }
            }
            $lines[] = "Turn " . (int)$t['turn_no'] . " (phase " . (int)$t['phase'] . "):";
            $lines[] = "  AI asked: " . $q;
            if ($a !== '') {
                $lines[] = "  Parent said: " . $a . $emLine;
            } else {
                $lines[] = "  Parent: (no answer captured this turn)";
            }
        }
    }
    $lines[] = "";
    $lines[] = "=== LATEST PARENT ANSWER (this turn — to be analysed for the next question) ===";
    $lines[] = trim($latest_transcript);

    if (is_array($latest_emotions)) {
        $top = pr_top_emotions($latest_emotions, 0.35, 5);
        if ($top) {
            $list = array_map(function($x){ return $x[0] . ' ' . round($x[1] * 100) . '%'; }, $top);
            $lines[] = "Voice emotion signals: " . implode(', ', $list);
            if (!empty($latest_emotions['felt_sense'])) {
                $lines[] = "Felt sense: " . $latest_emotions['felt_sense'];
            }
        }
    }
    if ($latest_acoustic) {
        $a = [];
        foreach (['wpm','pause_count','silence_ratio','duration_sec'] as $k) {
            if (isset($latest_acoustic[$k])) {
                $val = $latest_acoustic[$k];
                $a[] = $k . '=' . (is_float($val) ? round($val, 2) : $val);
            }
        }
        if ($a) $lines[] = "Acoustic: " . implode(', ', $a);
    }
    $lines[] = "";
    $lines[] = "Now produce the JSON decision for the next turn. Remember: the next_question must DIRECTLY build on what the parent just said, not jump to a generic phase question.";
    return implode("\n", $lines);
}

/**
 * Run one guide turn. Returns decoded decision array on success, or null.
 */
function pr_decide_next(int $session_id, string $latest_transcript, array $latest_acoustic): ?array {
    $st = db()->prepare("SELECT s.*, p.name AS parent_name FROM parent_reflect_sessions s
                         JOIN parents p ON p.id = s.parent_id
                         WHERE s.id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return null;

    $current_phase = (int) $session['current_phase'];
    $turn_no = (int) $session['turn_count'];

    // Build children context from ALL of parent's children (not just one)
    $cs = db()->prepare("SELECT name, dob, gender, diagnosis, mother_tongue
                         FROM children WHERE parent_id = ? ORDER BY created_at ASC");
    $cs->execute([(int)$session['parent_id']]);
    $children = $cs->fetchAll();

    $hi_count = 0; $en_count = 0;
    if (!$children) {
        $children_context = "(No children on file. Treat the conversation as fully about the parent.)";
    } else {
        $lines = [];
        foreach ($children as $c) {
            $age = $c['dob'] ? round((float)calc_age_years($c['dob']), 1) : null;
            $bits = [];
            $bits[] = $c['name'] ?: '(unnamed)';
            if ($age !== null) $bits[] = $age . 'y';
            if (!empty($c['gender'])) $bits[] = $c['gender'];
            if (!empty($c['diagnosis'])) $bits[] = $c['diagnosis'];
            if (!empty($c['mother_tongue'])) $bits[] = 'mt: ' . $c['mother_tongue'];
            $lines[] = '- ' . implode(', ', $bits);

            $mt = strtolower(trim((string)$c['mother_tongue']));
            if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi_count++; else $en_count++;
        }
        $children_context = implode("\n", $lines);
    }

    // Pick language: hindi if any of parent's children has hindi mother_tongue
    $language = ($hi_count >= $en_count && $hi_count > 0) ? 'hi' : ($en_count > 0 ? 'en' : 'hi');

    // Pull prior turns
    $st = db()->prepare("SELECT * FROM parent_reflect_turns
                         WHERE session_id = ? ORDER BY turn_no ASC");
    $st->execute([$session_id]);
    $allTurns = $st->fetchAll();

    // Get emotions for the latest answer
    $emotions = pr_detect_emotions($latest_transcript, $latest_acoustic);

    /* Compute covered_phases from all prior turns (phases that have at least one answered turn) */
    $covered_phases = [];
    $previous_questions = [];
    foreach ($prior_turns as $pt) {
        $ph = (int)($pt['phase'] ?? 0);
        if ($ph >= 1 && $ph <= 10 && !in_array($ph, $covered_phases, true)) {
            $covered_phases[] = $ph;
        }
        $q = trim((string)($pt['question'] ?? ''));
        if ($q !== '') $previous_questions[] = $q;
    }
    sort($covered_phases);
    $sys = pr_build_guide_system($current_phase, $turn_no + 1, PR_TARGET_TURNS, PR_MAX_TURNS, $children_context, $language, $covered_phases, $previous_questions);
    $usr = pr_build_history_payload($allTurns, $latest_transcript, $emotions, $latest_acoustic);

    // Fire Sonnet — claude_chat returns text
    $reply = claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1200, 0.6);
    if ($reply === '') {
        error_log('[pr_decide_next] empty reply from claude_chat for session ' . $session_id);
        return null;
    }
    // Strip fences, parse JSON
    $txt = trim($reply);
    if (strpos($txt, '```') === 0) {
        $txt = preg_replace('/^```(?:json)?/i', '', $txt);
        $txt = preg_replace('/```\s*$/', '', $txt);
        $txt = trim($txt);
    }
    $data = json_decode($txt, true);
    if (!is_array($data) && preg_match('/(\{.*\})/s', $txt, $m)) {
        $data = json_decode($m[1], true);
    }
    if (!is_array($data)) {
        error_log('[pr_decide_next] non-JSON: ' . substr($txt, 0, 300));
        return null;
    }

    // Clean / clamp
    $signals_in = is_array($data['signals'] ?? null) ? $data['signals'] : [];
    $signals = [
        'marital_stress'   => max(0.0, min(1.0, (float)($signals_in['marital_stress']   ?? 0))),
        'in_law_stress'    => max(0.0, min(1.0, (float)($signals_in['in_law_stress']    ?? 0))),
        'parent_burnout'   => max(0.0, min(1.0, (float)($signals_in['parent_burnout']   ?? 0))),
        'child_distress'   => max(0.0, min(1.0, (float)($signals_in['child_distress']   ?? 0))),
        'isolation'        => max(0.0, min(1.0, (float)($signals_in['isolation']        ?? 0))),
        'safety_red_flag'  => empty($signals_in['safety_red_flag']) ? 0 : 1,
    ];

    $allowed_intents = ['probe','reframe','forward','slow','challenge','close'];
    $intent = (string)($data['intent'] ?? 'probe');
    if (!in_array($intent, $allowed_intents, true)) $intent = 'probe';

    return [
        'reflection'    => trim((string)($data['reflection']    ?? '')),
        'tone_insight'  => trim((string)($data['tone_insight']  ?? '')),
        'next_phase'    => max(1, min(10, (int)($data['next_phase'] ?? $current_phase))),
        'intent'        => $intent,
        'signals'       => $signals,
        'emotions'      => $emotions,
        'next_question' => trim((string)($data['next_question'] ?? '')),
        'done'          => !empty($data['done']),
    ];
}

/**
 * Persist a completed turn (the answer to the OPEN turn) and insert
 * the next AI question (or close the session if done).
 *
 * Returns ['done' => bool, 'next_turn' => array|null, 'closing' => string|null].
 */
function pr_record_turn(int $session_id, string $transcript, int $time_seconds, array $acoustic, array $decision): array {
    $now = date('Y-m-d H:i:s');

    // Find the OPEN turn (most recent with no transcript yet)
    $st = db()->prepare("SELECT * FROM parent_reflect_turns
                         WHERE session_id = ? AND (transcript IS NULL OR transcript = '')
                         ORDER BY turn_no DESC LIMIT 1");
    $st->execute([$session_id]);
    $open = $st->fetch();
    if (!$open) {
        error_log('[pr_record_turn] no open turn for session ' . $session_id);
        return ['done' => false, 'next_turn' => null, 'closing' => null];
    }

    // Update the open turn with the answer + interpretation
    db()->prepare("UPDATE parent_reflect_turns
                   SET transcript = ?, answered_at = CURRENT_TIMESTAMP,
                       time_seconds = ?, acoustic_json = ?, emotions_json = ?,
                       ai_reflection = ?, ai_tone_insight = ?, signals_json = ?
                   WHERE id = ?")
       ->execute([
           $transcript,
           $time_seconds,
           !empty($acoustic) ? json_encode($acoustic, JSON_UNESCAPED_UNICODE) : null,
           !empty($decision['emotions']) ? json_encode($decision['emotions'], JSON_UNESCAPED_UNICODE) : null,
           $decision['reflection'] ?? null,
           $decision['tone_insight'] ?? null,
           json_encode($decision['signals'] ?? [], JSON_UNESCAPED_UNICODE),
           (int) $open['id'],
       ]);

    // Update session current_phase + last_activity_at
    db()->prepare("UPDATE parent_reflect_sessions
                   SET current_phase = ?, last_activity_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([(int) $decision['next_phase'], $session_id]);

    if (!empty($decision['done'])) {
        // Closing turn — write a final turn row with the closing summary as the question,
        // no answer to come. Then return closing text so caller can speak it & finalise.
        $next_turn_no = (int) $open['turn_no'] + 1;
        db()->prepare("INSERT INTO parent_reflect_turns
                       (session_id, turn_no, phase, question, question_intent)
                       VALUES (?, ?, ?, ?, 'close')")
           ->execute([$session_id, $next_turn_no, (int)$decision['next_phase'], (string)$decision['next_question']]);
        db()->prepare("UPDATE parent_reflect_sessions SET turn_count = ? WHERE id = ?")
           ->execute([$next_turn_no, $session_id]);
        return [
            'done'     => true,
            'next_turn'=> null,
            'closing'  => (string) $decision['next_question'],
        ];
    }

    // Otherwise insert the next AI question as a new open turn
    $next_turn_no = (int) $open['turn_no'] + 1;
    db()->prepare("INSERT INTO parent_reflect_turns
                   (session_id, turn_no, phase, question, question_intent)
                   VALUES (?, ?, ?, ?, ?)")
       ->execute([
           $session_id,
           $next_turn_no,
           (int) $decision['next_phase'],
           (string) $decision['next_question'],
           (string) $decision['intent'],
       ]);
    $next_id = (int) db()->lastInsertId();
    db()->prepare("UPDATE parent_reflect_sessions SET turn_count = ? WHERE id = ?")
       ->execute([$next_turn_no, $session_id]);

    return [
        'done'     => false,
        'next_turn'=> [
            'turn_id'  => $next_id,
            'turn_no'  => $next_turn_no,
            'phase'    => (int) $decision['next_phase'],
            'question' => (string) $decision['next_question'],
            'options'  => array_values(array_filter(
                            is_array($decision['next_options'] ?? null) ? $decision['next_options'] : [],
                            function($x){ return is_string($x) && trim($x) !== ''; }
                          )),
            'intent'   => (string) $decision['intent'],
        ],
        'closing'  => null,
    ];
}

// ════════════════════════════════════════════════════════════════
// FINALISATION — generate parent + admin reports + aggregate signals
// (Implemented in Phase 3. Stubbed here so the engine compiles.)
// ════════════════════════════════════════════════════════════════

/**
 * Build the system prompt for the parent-facing summary report.
 *
 * Output is markdown the parent will read on the report screen. Warm,
 * non-clinical, hopeful, India-aware. Three sections: Where you are now,
 * A point to ponder, One small thing to try this week.
 */
function pr_build_parent_report_system(string $language, int $n_kids): string {
    $lang_block = ($language === 'hi')
        ? "Write this entire report in conversational HINDI (Devanagari). Warm, natural, non-formal. Use 'आप' (respectful you), never 'तुम'. English words for modern concepts (therapy, anxiety, parenting, support) are fine — Indians naturally code-switch."
        : "Write the entire report in warm, conversational English.";

    $kids_note = ($n_kids >= 2)
        ? "The parent has multiple children. Refer to them as 'your children' / 'आपके बच्चे' generally — do not single out a specific child unless the parent named one."
        : "The parent has one child on file.";

    return <<<SYS
You are summarising a private voice reflection conducted with a parent of a special-needs child. The reflection is over. You will produce a warm written report the parent will read.

{$lang_block}

CONTEXT:
{$kids_note}

TONE:
- Warm, hopeful, never clinical or preachy.
- Validate before suggesting. Recognise their effort.
- This parent is exhausted and carrying invisible weight. Speak to that.
- Avoid "you should". Use "you might consider", "one thing that often helps".
- India-aware: joint families, in-law dynamics, izzat, log kya kahenge, financial stress around therapy, generational beliefs about disability are real.
- DO NOT diagnose, DO NOT label, DO NOT prescribe specific therapies.

STRUCTURE — produce EXACTLY these markdown sections, in this order:

## Where you are now
*(2-3 sentences naming what you heard. Honest, validating. Reflect the dominant emotional tone of the conversation. Acknowledge their strengths — they showed up, they spoke, they care.)*

## A point to ponder
*(One thoughtful observation worth sitting with. Not advice. Not a fix. A noticing — perhaps a pattern, a contradiction the parent themselves surfaced, or an insight that respects their intelligence and invites their own reflection. 2-3 sentences.)*

## One small thing to try this week
*(Concrete, doable in 10 minutes a day, framed as "you might consider..." or "one thing that often helps parents in this place is...". Specific. NOT a list of don'ts.)*

## What modern parenting psychology says
*(One short paragraph — 2-3 sentences — connecting their situation to a real, evidence-based principle: e.g. attachment regulation, co-regulation under stress, secure base, repair after rupture, generational scripts. Name the principle in plain language. Connect it directly to what the parent is going through.)*

## How this helps your child
*(2-3 sentences explaining how this small change in the parent's state ripples to the child. Realistic, not magical. Children feel calm in calm bodies — that level of mechanism. Keep it grounded.)*

DO NOT include:
- Section headers other than the five above
- Diagnosis, advice to seek a specific therapy/medication
- Any reference to "you should" or "you must"
- Lists longer than 3 items
- Generic platitudes like "be kind to yourself"

Output ONLY the markdown report. No preamble, no JSON, no fences.
SYS;
}

/**
 * Generate the parent-facing summary report (markdown).
 * Called from pr_finalise(). Writes to parent_summary_md column.
 */
function pr_generate_parent_report(int $session_id): bool {
    $st = db()->prepare("SELECT s.*, p.name AS parent_name FROM parent_reflect_sessions s
                         JOIN parents p ON p.id = s.parent_id WHERE s.id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return false;

    // Pull all turns (full transcript)
    $st = db()->prepare("SELECT * FROM parent_reflect_turns
                         WHERE session_id = ? ORDER BY turn_no ASC");
    $st->execute([$session_id]);
    $turns = $st->fetchAll();
    if (!$turns) return false;

    // Determine language from any child's mother_tongue
    $cs = db()->prepare("SELECT mother_tongue FROM children WHERE parent_id = ?");
    $cs->execute([(int)$session['parent_id']]);
    $hi = 0; $en = 0;
    while ($row = $cs->fetch()) {
        $mt = strtolower(trim((string)$row['mother_tongue']));
        if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi++; else $en++;
    }
    $language = ($hi >= $en && $hi > 0) ? 'hi' : ($en > 0 ? 'en' : 'hi');
    $n_kids = $hi + $en;

    // Build conversation transcript for the model
    $lines = ["=== Conversation transcript ==="];
    foreach ($turns as $t) {
        $q = trim((string)$t['question']);
        $a = trim((string)($t['transcript'] ?? ''));
        $em = json_decode((string)($t['emotions_json'] ?? ''), true);
        $emLine = '';
        if (is_array($em)) {
            $top = pr_top_emotions($em, 0.4, 3);
            if ($top) {
                $emLine = ' [voice: ' . implode(', ', array_map(function($x){
                    return $x[0] . ' ' . round($x[1] * 100) . '%';
                }, $top)) . ']';
            }
        }
        $lines[] = "AI: " . $q;
        if ($a !== '') $lines[] = "Parent: " . $a . $emLine;
    }
    $lines[] = "";
    $lines[] = "Now produce the parent-facing report markdown using the structure specified.";
    $usr = implode("\n", $lines);

    $sys = pr_build_parent_report_system($language, $n_kids);

    $md = claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1500, 0.6);
    if (trim($md) === '') {
        error_log('[pr_generate_parent_report] empty Sonnet reply for session ' . $session_id);
        return false;
    }
    // Strip stray code fences if Sonnet added them
    $md = trim($md);
    if (strpos($md, '```') === 0) {
        $md = preg_replace('/^```(?:markdown|md)?/i', '', $md);
        $md = preg_replace('/```\s*$/', '', $md);
        $md = trim($md);
    }

    db()->prepare("UPDATE parent_reflect_sessions SET parent_summary_md = ? WHERE id = ?")
       ->execute([$md, $session_id]);

    return true;
}

/**
 * Re-open a completed session for follow-up turns. The parent is allowed up to
 * PR_MAX_FOLLOWUPS additional turns total across all reopens. Returns the
 * fresh "open" turn the parent can answer, or null if cap exceeded.
 *
 * The session goes back to status='in_progress' and turn_count is incremented.
 * On re-finalisation, parent_summary_md is regenerated to incorporate the
 * follow-up context.
 */
function pr_reopen_for_followup(int $session_id): ?array {
    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return null;
    if ($session['status'] !== 'completed') return null;
    if ((int)$session['followup_count'] >= PR_MAX_FOLLOWUPS) return null;

    // Find language
    $cs = db()->prepare("SELECT mother_tongue FROM children WHERE parent_id = ?");
    $cs->execute([(int)$session['parent_id']]);
    $hi = 0; $en = 0;
    while ($row = $cs->fetch()) {
        $mt = strtolower(trim((string)$row['mother_tongue']));
        if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi++; else $en++;
    }
    $language = ($hi >= $en && $hi > 0) ? 'hi' : ($en > 0 ? 'en' : 'hi');

    // Compose a warm opening question that invites them to add anything
    $remaining = PR_MAX_FOLLOWUPS - (int)$session['followup_count'];
    if ($language === 'hi') {
        $q = "वापस आने का शुक्रिया। पिछली बार जो हमने बात की, उसके बाद क्या कुछ ऐसा है जो आप जोड़ना चाहेंगी, या पूछना चाहेंगी? आराम से बताइए।";
    } else {
        $q = "Welcome back. Since our last conversation, is there anything you'd like to add, or anything you've been wanting to ask? Take your time.";
    }

    // Flip session back to in_progress and add a new "open" turn
    $next_turn_no = (int)$session['turn_count'] + 1;
    db()->prepare("UPDATE parent_reflect_sessions
                   SET status = 'in_progress',
                       turn_count = ?,
                       last_activity_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$next_turn_no, $session_id]);

    db()->prepare("INSERT INTO parent_reflect_turns (session_id, turn_no, phase, question, question_intent)
                   VALUES (?, ?, ?, ?, 'probe')")
       ->execute([$session_id, $next_turn_no, 10, $q]);
    $turn_id = (int) db()->lastInsertId();

    return [
        'turn_id'    => $turn_id,
        'turn_no'    => $next_turn_no,
        'phase'      => 10,
        'question'   => $q,
        'intent'     => 'probe',
        'language'   => $language,
        'remaining'  => $remaining,
    ];
}

/**
 * Mark session completed and generate the parent-facing report.
 * If this finalisation is closing a follow-up reopen, increment followup_count.
 */
function pr_finalise(int $session_id): bool {
    // Was this a follow-up close? Detect by checking if session was previously completed
    // and reopened — followup_count is only incremented on this transition.
    $st = db()->prepare("SELECT followup_count, parent_summary_md FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$session_id]);
    $pre = $st->fetch();
    $is_followup_close = $pre && !empty($pre['parent_summary_md']);  // had a report before = was completed before

    db()->prepare("UPDATE parent_reflect_sessions
                   SET status = 'completed',
                       completed_at = CURRENT_TIMESTAMP,
                       generated_at = CURRENT_TIMESTAMP
                   WHERE id = ? AND status = 'in_progress'")
       ->execute([$session_id]);

    if ($is_followup_close) {
        db()->prepare("UPDATE parent_reflect_sessions SET followup_count = followup_count + 1 WHERE id = ?")
           ->execute([$session_id]);
    }

    // Aggregate signals across all turns
    $st = db()->prepare("SELECT signals_json FROM parent_reflect_turns
                         WHERE session_id = ? AND signals_json IS NOT NULL");
    $st->execute([$session_id]);
    $rows = $st->fetchAll();
    $sums = [
        'marital_stress' => 0.0, 'in_law_stress' => 0.0, 'parent_burnout' => 0.0,
        'child_distress' => 0.0, 'isolation' => 0.0,
    ];
    $count = 0; $red_flag = 0;
    foreach ($rows as $r) {
        $s = json_decode((string)$r['signals_json'], true);
        if (!is_array($s)) continue;
        $count++;
        foreach (array_keys($sums) as $k) {
            if (isset($s[$k])) $sums[$k] += (float) $s[$k];
        }
        if (!empty($s['safety_red_flag'])) $red_flag = 1;
    }
    if ($count > 0) {
        foreach ($sums as $k => $v) $sums[$k] = round($v / $count, 3);
    }

    $max = max($sums['parent_burnout'], $sums['marital_stress'], $sums['in_law_stress'],
               $sums['child_distress'], $sums['isolation']);
    if ($red_flag === 1)        $risk = 'red';
    elseif ($max >= 0.6)        $risk = 'amber';
    else                        $risk = 'green';

    $hours_map = ['red' => 24, 'amber' => 48, 'green' => 72];
    $follow_by = date('Y-m-d H:i:s', time() + $hours_map[$risk] * 3600);

    db()->prepare("UPDATE parent_reflect_sessions
                   SET sig_marital_stress = ?, sig_in_law_stress = ?, sig_parent_burnout = ?,
                       sig_child_distress = ?, sig_isolation = ?, sig_safety_red_flag = ?,
                       admin_risk_level = ?, admin_follow_up_by = ?
                   WHERE id = ?")
       ->execute([
           $sums['marital_stress'], $sums['in_law_stress'], $sums['parent_burnout'],
           $sums['child_distress'], $sums['isolation'], $red_flag,
           $risk, $follow_by, $session_id,
       ]);

    // Generate parent-facing summary markdown (also re-runs after a followup close
    // so the report incorporates new turns)
    pr_generate_parent_report($session_id);

    return true;
}

// ════════════════════════════════════════════════════════════════
// CONVENIENCE QUERIES
// ════════════════════════════════════════════════════════════════

/** Most recent in-progress session for this parent (for resume). */
function pr_in_progress_for(int $parent_id): ?array {
    $st = db()->prepare("SELECT * FROM parent_reflect_sessions
                         WHERE parent_id = ? AND status = 'in_progress'
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$parent_id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Most recent completed session for this parent within last 7 days (for reload-shows-report). */
function pr_recent_complete_for(int $parent_id, int $days = 7): ?array {
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    $st = db()->prepare("SELECT s.*, c.name AS child_name
                         FROM parent_reflect_sessions s
                         LEFT JOIN children c ON c.id = s.child_id
                         WHERE s.parent_id = ? AND s.status = 'completed'
                           AND s.completed_at IS NOT NULL
                           AND s.completed_at >= ?
                         ORDER BY s.completed_at DESC LIMIT 1");
    $st->execute([$parent_id, $cutoff]);
    $r = $st->fetch();
    return $r ?: null;
}
