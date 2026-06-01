<?php
/**
 * includes/eval_clinical.php
 *
 * Clinical 5-axis analysis layer for the speech evaluation.
 *
 * Called from eval_finalise() AFTER the existing parent-facing report is
 * generated. Computes 5 independent clinical axes and writes a structured
 * JSON report to eval_sessions.clinical_report_json that the UI renders as
 * 5 score cards (Articulation / Fluency / Vocabulary / Grammar / Narrative).
 *
 * Uses ONLY data already captured during the live interview:
 *   - eval_questions.user_answer            (Web Speech transcript)
 *   - eval_questions.acoustic_json          (confidence, WPM, pauses, silence)
 *   - eval_questions.time_seconds
 *   - eval_questions.is_correct + level + question_type
 *
 * No Google STT in v1. No audio DSP. Adding either later is a drop-in change
 * inside this file; the upstream API and DB shape don't change.
 *
 * Scoring philosophy:
 *   - PHP computes the OBJECTIVE numbers (medians, ratios, lexical diversity).
 *   - Claude Sonnet does the SUBJECTIVE clinical reading — finding,
 *     age-norm comparison, parent-facing language, per-axis exercise.
 *   - Server NEVER trusts Claude for numerical scoring. PHP supplies a
 *     pre-computed 0-100 raw score per axis as a "prior"; Claude can adjust
 *     within ±15 with a written reason, but cannot invent.
 *
 * Idempotent: re-running on an already-analysed session is a no-op unless
 * forced with $force=true.
 *
 * Returns true on success, false on any failure (failure does NOT block the
 * standard report; it just means the parent sees the markdown report only).
 */

require_once __DIR__ . '/claude.php';


/**
 * Main entry — analyse a completed evaluation session and persist results.
 */
function eval_clinical_analyse(int $session_id, bool $force = false): bool {
    // Load session + child
    $sst = db()->prepare("SELECT s.*, c.name AS child_name, c.dob AS child_dob,
                                 c.gender AS child_gender, c.mother_tongue AS child_mt
                          FROM eval_sessions s
                          JOIN children c ON c.id = s.child_id
                          WHERE s.id = ?");
    $sst->execute([$session_id]);
    $session = $sst->fetch();
    if (!$session) {
        error_log("[clinical] session $session_id not found");
        return false;
    }

    // Skip if already analysed and not forcing
    if (!$force) {
        // Check if column exists (may not yet on older DBs)
        try {
            $existing = db()->prepare("SELECT clinical_report_json FROM eval_sessions WHERE id = ?");
            $existing->execute([$session_id]);
            $cur = $existing->fetchColumn();
            if (!empty($cur)) return true;
        } catch (Throwable $_) {
            _clinical_ensure_columns();
        }
    }

    _clinical_ensure_columns();

    // Pull all answered questions
    $qst = db()->prepare("SELECT id, seq_no, level, question_type, prompt, user_answer,
                                 is_correct, time_seconds, acoustic_json, answer_mode
                          FROM eval_questions
                          WHERE session_id = ? AND is_correct IS NOT NULL
                          ORDER BY seq_no");
    $qst->execute([$session_id]);
    $questions = $qst->fetchAll();
    if (empty($questions)) {
        error_log("[clinical] session $session_id has no scored questions");
        return false;
    }

    // ── Step 1: compute objective per-axis priors in PHP ──
    $age_yrs = round((float) calc_age_years($session['child_dob']), 1);
    $is_hindi = preg_match('/[\x{0900}-\x{097F}]/u', (string)$session['child_mt'])
              || stripos((string)$session['child_mt'], 'hindi') !== false;
    $mt = (string)($session['child_mt'] ?: 'English');

    $priors = _clinical_compute_priors($questions, $age_yrs, $is_hindi);

    // ── Step 2: one Claude Sonnet call for the clinical reading ──
    $axes = _clinical_call_claude(
        $session,
        $questions,
        $priors,
        $age_yrs,
        $mt
    );

    if (!$axes) {
        error_log("[clinical] session $session_id — Claude analysis failed, no clinical report saved");
        return false;
    }

    // ── Step 3: merge priors back so the score we display is grounded ──
    // Claude is allowed to nudge ±15. Anything outside the band is clamped.
    foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $k) {
        if (!isset($axes[$k]) || !is_array($axes[$k])) continue;
        $claude_score = isset($axes[$k]['score']) ? (int)$axes[$k]['score'] : null;
        $prior_score  = (int) ($priors[$k]['score'] ?? 50);
        if ($claude_score === null) {
            $axes[$k]['score'] = $prior_score;
        } else {
            $axes[$k]['score'] = max(0, min(100, max($prior_score - 15, min($prior_score + 15, $claude_score))));
        }
        // Inject the prior debugging info so we can audit later
        $axes[$k]['_prior'] = $priors[$k];
    }

    // ── Step 4: overall band + headline ──
    $axes['overall'] = _clinical_overall($axes);
    $axes['child_name'] = $session['child_name'];
    $axes['age_years'] = $age_yrs;
    $axes['analysed_at'] = gmdate('Y-m-d H:i:s');

    // ── Step 5: persist ──
    $summary = _clinical_short_summary($axes);
    db()->prepare("UPDATE eval_sessions
                   SET clinical_report_json = ?,
                       clinical_axes_summary = ?
                   WHERE id = ?")
       ->execute([
           json_encode($axes, JSON_UNESCAPED_UNICODE),
           $summary,
           $session_id,
       ]);

    return true;
}


/**
 * Add the two clinical columns if missing. Idempotent.
 */
function _clinical_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = db()->query("PRAGMA table_info(eval_sessions)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('clinical_report_json', $names, true)) {
            db()->exec("ALTER TABLE eval_sessions ADD COLUMN clinical_report_json TEXT");
        }
        if (!in_array('clinical_axes_summary', $names, true)) {
            db()->exec("ALTER TABLE eval_sessions ADD COLUMN clinical_axes_summary TEXT");
        }
    } catch (Throwable $e) {
        error_log('[clinical_ensure_columns] ' . $e->getMessage());
    }
    $done = true;
}


/**
 * Compute objective per-axis priors from existing per-question data.
 * Returns:
 *   [axis => ['score' => 0-100, 'metrics' => [...]], ...]
 */
function _clinical_compute_priors(array $questions, float $age_yrs, bool $is_hindi): array {
    $n = count($questions);

    // Collect all signals
    $transcripts = [];
    $confidences = [];
    $wpms = [];
    $silences = [];
    $pause_counts = [];
    $ttfs_list = [];               // time-to-first-speech
    $durations = [];
    $correct_count = 0;
    $describe_answers = [];        // for narrative scoring

    foreach ($questions as $q) {
        $ans = trim((string)$q['user_answer']);
        if ($ans !== '' && $ans !== '(no response)' && stripos($ans, '(silence)') === false) {
            $transcripts[] = $ans;
        }
        $ac = [];
        if (!empty($q['acoustic_json'])) {
            $ac = json_decode($q['acoustic_json'], true) ?: [];
        }
        if (isset($ac['transcript_confidence'])) $confidences[] = (float)$ac['transcript_confidence'];
        if (isset($ac['wpm']))                    $wpms[] = (int)$ac['wpm'];
        if (isset($ac['silence_ratio']))          $silences[] = (float)$ac['silence_ratio'];
        if (isset($ac['pause_count']))            $pause_counts[] = (int)$ac['pause_count'];
        if (isset($ac['time_to_first_speech_sec']))$ttfs_list[] = (float)$ac['time_to_first_speech_sec'];
        if (isset($ac['duration_sec']))           $durations[] = (float)$ac['duration_sec'];
        if ($q['is_correct']) $correct_count++;

        if ($q['question_type'] === 'describe' && $ans !== '' && $ans !== '(no response)') {
            $describe_answers[] = $ans;
        }
    }

    // ── ARTICULATION — clarity of speech ──
    // Signal: median transcript_confidence (Web Speech's confidence is a
    // reasonable proxy for "did the engine understand the child's articulation").
    // Adjusted by: total speaking events (very few = low confidence in score).
    $median_conf = _median($confidences);
    $artic_score = (int) round($median_conf * 100);
    // Penalise if we have very few samples to base this on
    if (count($confidences) < 3) $artic_score = (int) round($artic_score * 0.8);
    $artic_score = max(0, min(100, $artic_score));

    // ── FLUENCY — rate, smoothness, hesitation ──
    // Age-appropriate WPM for Indian children (rough norms):
    //   3-5y: 70-100 WPM expected
    //   6-8y: 100-130
    //   9-12y: 130-160
    //   13+y: 150-180
    $expected_wpm = $age_yrs < 5 ? 85 : ($age_yrs < 9 ? 115 : ($age_yrs < 13 ? 145 : 170));
    $median_wpm = _median($wpms) ?: $expected_wpm;
    // Score 100 if at or above expected, scaling down to 30 at half-expected
    $wpm_score = max(30, min(100, (int) round(30 + 70 * ($median_wpm / $expected_wpm))));
    // Pause penalty: more than 4 pauses per question on average is concerning
    $avg_pauses = count($pause_counts) > 0 ? array_sum($pause_counts) / count($pause_counts) : 2;
    $pause_penalty = max(0, ($avg_pauses - 4) * 5);  // each pause above 4 → -5
    // Hesitation penalty: avg time-to-first-speech > 3s suggests retrieval struggle
    $avg_ttfs = count($ttfs_list) > 0 ? array_sum($ttfs_list) / count($ttfs_list) : 1.0;
    $ttfs_penalty = max(0, ($avg_ttfs - 3) * 8);
    $fluency_score = (int) max(0, min(100, $wpm_score - $pause_penalty - $ttfs_penalty));

    // ── VOCABULARY — lexical range ──
    // Compute type-token ratio over all transcripts
    $all_words = [];
    foreach ($transcripts as $t) {
        // Tokenize: split on whitespace + punctuation. Works for Hindi too.
        $tokens = preg_split('/[\s,.\?!।]+/u', mb_strtolower($t));
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) >= 2) $all_words[] = $tok;
        }
    }
    $total_words = count($all_words);
    $unique_words = count(array_unique($all_words));
    $ttr = $total_words > 0 ? ($unique_words / $total_words) : 0;
    // Age-appropriate words spoken across whole interview:
    //   younger kids should use 30+ unique words; older 60+
    $expected_unique = $age_yrs < 5 ? 25 : ($age_yrs < 9 ? 45 : 70);
    $unique_score = min(100, (int) round(100 * $unique_words / $expected_unique));
    // TTR > 0.6 is rich; < 0.4 is narrow
    $ttr_score = (int) max(20, min(100, round(($ttr) * 150)));
    $vocab_score = (int) round(0.6 * $unique_score + 0.4 * $ttr_score);
    $vocab_score = max(0, min(100, $vocab_score));

    // ── GRAMMAR — placeholder prior; Claude does most of this ──
    // Heuristic: child can produce some multi-word answers + correctness rate.
    $multi_word_count = 0;
    foreach ($transcripts as $t) {
        if (count(preg_split('/\s+/', trim($t))) >= 3) $multi_word_count++;
    }
    $multi_word_ratio = $n > 0 ? $multi_word_count / $n : 0;
    $accuracy_ratio = $n > 0 ? $correct_count / $n : 0;
    // Heavy on accuracy here — grammar errors typically lead to wrong answers
    $grammar_score = (int) round(40 * $multi_word_ratio + 60 * $accuracy_ratio);
    $grammar_score = max(0, min(100, $grammar_score));

    // ── NARRATIVE — only meaningful on describe-type questions ──
    if (empty($describe_answers)) {
        // No describe questions reached (low level child) — score is N/A,
        // we set a neutral 50 so it doesn't drag down overall
        $narrative_score = 50;
        $narrative_n = 0;
    } else {
        // Heuristic: longer + multi-sentence describe answers score better
        $word_counts = array_map(function($t) { return count(preg_split('/\s+/', trim($t))); }, $describe_answers);
        $avg_words = array_sum($word_counts) / count($word_counts);
        // Age-appropriate narrative length (words per describe answer):
        //   6-8y: 15+    9-12y: 25+    13+y: 40+
        $expected_words = $age_yrs < 9 ? 12 : ($age_yrs < 13 ? 22 : 35);
        $length_score = min(100, (int) round(100 * $avg_words / $expected_words));
        // Sentence-count heuristic: count sentence terminators (., ।, ?, !)
        $total_sentences = 0;
        foreach ($describe_answers as $t) {
            $total_sentences += max(1, preg_match_all('/[.।?!]+/u', $t));
        }
        $avg_sentences = $total_sentences / count($describe_answers);
        $sent_score = (int) max(40, min(100, round($avg_sentences * 50)));  // 2 sentences = 100
        $narrative_score = (int) round(0.7 * $length_score + 0.3 * $sent_score);
        $narrative_score = max(0, min(100, $narrative_score));
        $narrative_n = count($describe_answers);
    }

    return [
        'articulation' => [
            'score'   => $artic_score,
            'metrics' => [
                'median_transcript_confidence' => $median_conf,
                'samples' => count($confidences),
            ],
        ],
        'fluency' => [
            'score'   => $fluency_score,
            'metrics' => [
                'median_wpm' => $median_wpm,
                'expected_wpm' => $expected_wpm,
                'avg_pauses_per_q' => round($avg_pauses, 1),
                'avg_time_to_speak_sec' => round($avg_ttfs, 1),
            ],
        ],
        'vocabulary' => [
            'score'   => $vocab_score,
            'metrics' => [
                'total_words' => $total_words,
                'unique_words' => $unique_words,
                'expected_unique' => $expected_unique,
                'type_token_ratio' => round($ttr, 2),
            ],
        ],
        'grammar' => [
            'score'   => $grammar_score,
            'metrics' => [
                'multi_word_answer_ratio' => round($multi_word_ratio, 2),
                'overall_accuracy' => round($accuracy_ratio, 2),
            ],
        ],
        'narrative' => [
            'score'   => $narrative_score,
            'metrics' => [
                'describe_questions_count' => $narrative_n,
                'note' => $narrative_n === 0
                    ? 'No describe-type questions were reached (level too low) — score is neutral placeholder'
                    : 'Based on ' . $narrative_n . ' describe-type response(s)',
            ],
        ],
    ];
}


/** Median of a numeric array; 0 if empty. */
function _median(array $arr) {
    $arr = array_values(array_filter($arr, function($v){ return is_numeric($v); }));
    $n = count($arr);
    if ($n === 0) return 0;
    sort($arr);
    $mid = (int) floor($n / 2);
    if ($n % 2 === 1) return $arr[$mid];
    return ($arr[$mid - 1] + $arr[$mid]) / 2;
}


/**
 * One Claude Sonnet call. Sends transcripts + computed priors + child info.
 * Asks for clinical reading per axis: finding (1-2 sentences) + exercise.
 * Returns the structured JSON or null on failure.
 */
function _clinical_call_claude(array $session, array $questions, array $priors, float $age_yrs, string $mt): ?array {
    // Build the question/answer transcript block
    $transcript_lines = [];
    foreach ($questions as $q) {
        $v = $q['is_correct'] ? '✓' : '✗';
        $ac = json_decode($q['acoustic_json'] ?: '{}', true) ?: [];
        $conf = isset($ac['transcript_confidence']) ? round((float)$ac['transcript_confidence'] * 100) : '?';
        $wpm = isset($ac['wpm']) ? (int)$ac['wpm'] : '?';
        $transcript_lines[] = sprintf(
            "  Q%d (L%d, %s, %ds, %s conf, %s wpm) %s\n    Q: %s\n    A: \"%s\"",
            $q['seq_no'], $q['level'], $q['question_type'], $q['time_seconds'],
            $conf . '%', $wpm, $v,
            mb_substr((string)$q['prompt'], 0, 120),
            mb_substr((string)$q['user_answer'], 0, 200)
        );
    }
    $transcript_block = implode("\n", $transcript_lines);

    // Compact priors for the prompt
    $priors_block = "Server-computed objective priors (you may adjust each score ±15 with reason, but stay grounded):\n";
    foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $k) {
        $p = $priors[$k];
        $metrics = $p['metrics'];
        $priors_block .= "  - {$k}: prior_score={$p['score']} ; ";
        $bits = [];
        foreach ($metrics as $mk => $mv) {
            if (is_array($mv)) continue;
            $bits[] = "$mk=" . (is_float($mv) ? round($mv, 2) : $mv);
        }
        $priors_block .= implode(', ', $bits) . "\n";
    }

    $sys = <<<SYS
You are a senior speech-language pathologist (SLP) at EmpowerStudents, an Indian clinical service.
You are writing a structured 5-axis clinical assessment for a parent based on a brief adaptive voice-interview evaluation of their child.

Five axes, each scored 0-100 with a band, finding, and one targeted home exercise:

1. ARTICULATION — clarity of speech sound production (consonants, vowels, blends)
2. FLUENCY     — rate, smoothness, hesitation, presence of repetitions/blocks
3. VOCABULARY  — lexical range and word-finding compared to age peers
4. GRAMMAR     — sentence structure, tense, agreement, function words
5. NARRATIVE   — coherent multi-sentence expression (only if describe-type questions were attempted)

For each axis, decide a band based on the FINAL score (after your ±15 adjustment to the prior):
  - 80-100: "At or above age expectations"
  - 60-79:  "Approaching age expectations"
  - 40-59:  "Below age expectations — gentle support needed"
  - 0-39:   "Notable concern — therapy strongly recommended"

CRITICAL RULES:
- You are seeing the server-computed prior for each axis. You may adjust the score by AT MOST ±15 points,
  and ONLY if your clinical reading clearly justifies it. If you have no strong reason, keep the prior.
- The TRANSCRIPTS come from Web Speech recognition — they may have errors. Mentally allow for that.
- Indian context: be culturally appropriate. Hindi names, foods, festivals, family terms are normal.
- A child whose transcript shows "(no response)" or "(silence)" had trouble producing — note it factually,
  don't speculate about disability.
- For NARRATIVE: if `describe_questions_count` is 0, set score to null (it wasn't tested),
  finding to "Not assessed in this evaluation — the child did not reach narrative-level questions",
  exercise to "Re-evaluate when the child is comfortable with shorter answers first.".
- TONE: warm clinical professional. Address the parent as "you". Refer to child by name.
- Each FINDING: 2-3 short sentences naming what you saw, age-relative.
- Each EXERCISE: ONE specific 10-minute activity the parent can do this week. Concrete materials,
  step-by-step, Indian context. Format: short paragraph, not a list.
- Do NOT recommend therapy in every axis. Only recommend therapy when band is 0-39.

Output JSON ONLY (no fences, no prose, no commentary):
{
  "headline": "8-12 word summary of where the child is — names a primary strength + a primary growth area",
  "articulation": {"score": 0-100|null, "band": "...", "finding": "...", "exercise": "..."},
  "fluency":      {"score": 0-100|null, "band": "...", "finding": "...", "exercise": "..."},
  "vocabulary":   {"score": 0-100|null, "band": "...", "finding": "...", "exercise": "..."},
  "grammar":      {"score": 0-100|null, "band": "...", "finding": "...", "exercise": "..."},
  "narrative":    {"score": 0-100|null, "band": "...", "finding": "...", "exercise": "..."},
  "therapy_recommendation": "ONE sentence: should this child see one of our speech therapists? If yes, which axis is the priority? If no, say 'Home practice for 4 weeks then re-evaluate' or similar."
}
SYS;

    $user = "Child profile:\n"
          . "  Name: {$session['child_name']}\n"
          . "  Age: {$age_yrs} years\n"
          . "  Gender: " . ($session['child_gender'] ?: 'unspecified') . "\n"
          . "  Mother tongue: {$mt}\n"
          . "  Eval ended at level: L" . (int)$session['final_level'] . "\n"
          . "  Overall accuracy: " . (int)$session['final_pct'] . "%\n\n"
          . $priors_block . "\n"
          . "Full Q&A transcript ({n} questions):\n"
          . str_replace('{n}', (string)count($questions), '') 
          . $transcript_block . "\n\n"
          . "Now produce the structured 5-axis clinical assessment JSON. Be specific and grounded.";

    $j = claude_json($sys, $user, 2500, 0.4);
    if (!is_array($j)) return null;
    // Validate shape
    foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $k) {
        if (!isset($j[$k]) || !is_array($j[$k])) {
            error_log("[clinical] Claude returned malformed axis: $k");
            return null;
        }
        // Allow null score on narrative; coerce others to int
        if (isset($j[$k]['score']) && $j[$k]['score'] !== null) {
            $j[$k]['score'] = (int)$j[$k]['score'];
        }
    }
    return $j;
}


/**
 * Compute overall headline numbers from per-axis scores.
 * Excludes axes with null scores (e.g. narrative when not tested).
 */
function _clinical_overall(array $axes): array {
    $sum = 0; $n = 0; $weights = ['articulation'=>1.0,'fluency'=>1.0,'vocabulary'=>1.2,'grammar'=>1.0,'narrative'=>0.8];
    $wsum = 0;
    foreach (['articulation','fluency','vocabulary','grammar','narrative'] as $k) {
        if (!isset($axes[$k]['score']) || $axes[$k]['score'] === null) continue;
        $w = $weights[$k];
        $sum += $axes[$k]['score'] * $w;
        $wsum += $w;
        $n++;
    }
    $overall = $wsum > 0 ? (int) round($sum / $wsum) : 50;

    if ($overall >= 80)   $band = 'At or above age expectations';
    elseif ($overall >= 60) $band = 'Approaching age expectations';
    elseif ($overall >= 40) $band = 'Below age expectations';
    else                   $band = 'Notable concern';

    return [
        'score'         => $overall,
        'band'          => $band,
        'axes_assessed' => $n,
    ];
}


/**
 * Short 1-line summary suitable for an admin list / lead view.
 */
function _clinical_short_summary(array $axes): string {
    $overall = $axes['overall']['score'] ?? '?';
    $headline = $axes['headline'] ?? '';
    return "Score {$overall}/100 · {$headline}";
}
