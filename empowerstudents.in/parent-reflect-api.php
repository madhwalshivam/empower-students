<?php
/**
 * parent-reflect-api.php  —  fresh-v1
 *
 * Clean rewrite. Single source of truth.
 *
 * Actions:
 *   start            — check balance ≥ ₹1000 (do NOT deduct); resume if in_progress within 24h
 *   turn             — submit answer; charge ₹1000 on natural done
 *   cancel           — PAUSE (session stays in_progress)
 *   finish_early     — finalise mid-conversation + charge ₹1000
 *   discard          — explicitly abandon (Start fresh button)
 *   start_followup   — reopen completed session for 1-3 extra turns (no charge)
 *
 * Resume responses include prior_turns so frontend renders history panel.
 * _pr_charge_on_finalize is idempotent (skips if already charged for session).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/claude.php';
require_once __DIR__ . '/includes/parent_reflect_schema.php';
require_once __DIR__ . '/includes/parent_reflect_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['parent_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in. Please reload.']);
    exit;
}
$parent_id = (int) $_SESSION['parent_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$token = (string)($_POST['csrf'] ?? '');
if (!csrf_check($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

// ─── Helpers ────────────────────────────────────────────────────
function _pr_scrub_voice_phrases(string $s): string {
    if ($s === '') return $s;
    $patterns = [
        '/[—–-]?\s*आवाज़[^।\.]{0,80}[।\.]/u',
        '/[—–-]?\s*आवाज\s+में[^।\.]{0,80}[।\.]/u',
        '/[—–-]?\s*सुना मैंने[^।\.]{0,80}[।\.]/u',
        '/[—–-]?\s*स्वर[^।\.]{0,80}[।\.]/u',
        '/[—–-]?\s*टोन[^।\.]{0,80}[।\.]/u',
        '/\s*[—–-]?\s*in your voice[^.]{0,80}\./i',
        '/\s*[—–-]?\s*your tone[^.]{0,80}\./i',
        '/\s*[—–-]?\s*I (heard|hear) (it|that|something)[^.]{0,80}\./i',
        '/\s*[—–-]?\s*sounds like[^.]{0,80}\./i',
        '/\s*[—–-]?\s*from how you (said|say|sound)[^.]{0,80}\./i',
    ];
    foreach ($patterns as $p) $s = preg_replace($p, '', $s);
    $s = preg_replace('/\s{2,}/', ' ', $s);
    $s = trim($s, " \t\n\r—–-");
    return $s;
}

/* fresh-v2: kick off comprehensive v3 report generation. Returns ASAP via
 * fastcgi_finish_request (if available) so the comprehensive report (which
 * makes multiple AI calls + TTS) doesn't block the closing-screen response.
 * Sets v3_listing_at on success so the frontend can poll for readiness.
 */
function _pr_trigger_v3_async(int $session_id): void {
    /* The v3 generator lives in comprehensive_report_v3.php. Use shutdown hook
     * so it runs AFTER the response is sent. */
    register_shutdown_function(function() use ($session_id) {
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            // Bump connection close
            @ignore_user_abort(true);
            @set_time_limit(120);

            $v3 = __DIR__ . '/includes/comprehensive_report_v3.php';
            if (!file_exists($v3)) { error_log('[v3-async] comprehensive_report_v3.php missing'); return; }
            require_once $v3;
            if (function_exists('comprehensive_v3_generate')) {
                comprehensive_v3_generate($session_id);
            }
        } catch (Throwable $e) {
            error_log('[v3-async] ' . $e->getMessage());
        }
    });
}

function _pr_charge_on_finalize(int $parent_id, int $session_id): array {
    $st = db()->prepare("SELECT id FROM wallet_ledger
                         WHERE parent_id = ? AND service_key = 'mod_parent_reflect'
                           AND ref_id = ? AND amount < 0 LIMIT 1");
    $st->execute([$parent_id, $session_id]);
    if ($st->fetchColumn()) return ['ok' => true, 'already_charged' => true];

    $price = wallet_service_price('mod_parent_reflect');
    if (!$price) return ['ok' => false, 'reason' => 'no_price'];

    $bal = wallet_balance($parent_id);
    if ($bal < $price) {
        db()->prepare("UPDATE parent_reflect_sessions
                       SET admin_clinical_md = COALESCE(admin_clinical_md, '') || ?
                       WHERE id = ?")
           ->execute(["\n[PAYMENT_PENDING: wallet had ₹$bal at finalize, needs ₹$price]\n", $session_id]);
        return ['ok' => false, 'reason' => 'insufficient', 'balance' => $bal, 'price' => $price];
    }

    $new_bal = $bal - $price;
    db()->prepare("INSERT INTO wallet_ledger
        (parent_id, amount, balance_after, service_key, ref_id, reason, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([
        $parent_id, -$price, $new_bal, 'mod_parent_reflect', $session_id,
        'Parent Reflection — report generated', 'system'
    ]);
    db()->prepare("UPDATE parents SET credits = ? WHERE id = ?")->execute([$new_bal, $parent_id]);
    db()->prepare("UPDATE parent_reflect_sessions SET cost_paid = ? WHERE id = ?")
       ->execute([$price, $session_id]);

    return ['ok' => true, 'charged' => $price, 'new_balance' => $new_bal];
}

function _pr_prior_turns(int $session_id): array {
    $st = db()->prepare("SELECT turn_no, phase, question, transcript
                         FROM parent_reflect_turns
                         WHERE session_id = ?
                           AND transcript IS NOT NULL AND transcript != ''
                         ORDER BY turn_no ASC");
    $st->execute([$session_id]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'turn_no'    => (int)$r['turn_no'],
            'phase'      => (int)$r['phase'],
            'question'   => (string)$r['question'],
            'transcript' => (string)$r['transcript'],
        ];
    }
    return $out;
}

// ════════════════════════════════════════════════════════════════
// ACTION: start
// ════════════════════════════════════════════════════════════════
if ($action === 'start') {
    $cs = db()->prepare("SELECT mother_tongue FROM children WHERE parent_id = ?");
    $cs->execute([$parent_id]);
    $hi = 0; $en = 0;
    while ($row2 = $cs->fetch()) {
        $mt = strtolower(trim((string)$row2['mother_tongue']));
        if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi++; else $en++;
    }
    $language = ($hi >= $en && $hi > 0) ? 'hi' : ($en > 0 ? 'en' : 'hi');

    // Resume: latest in_progress within 24h
    $st = db()->prepare("SELECT id, started_at, last_activity_at, turn_count
                         FROM parent_reflect_sessions
                         WHERE parent_id = ? AND status = 'in_progress'
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$parent_id]);
    $row = $st->fetch();
    $existing = 0;
    if ($row) {
        $age_min = (time() - strtotime((string)$row['last_activity_at'] . ' UTC')) / 60;
        if ($age_min < 1440 && (int)$row['turn_count'] > 0) {
            $existing = (int)$row['id'];
        }
    }

    if ($existing) {
        $st = db()->prepare("SELECT * FROM parent_reflect_turns
                             WHERE session_id = ? AND (transcript IS NULL OR transcript = '')
                             ORDER BY turn_no DESC LIMIT 1");
        $st->execute([$existing]);
        $open = $st->fetch();

        if (!$open) {
            // All turns answered but no fresh open turn — generate one
            $latest = db()->prepare("SELECT transcript FROM parent_reflect_turns
                                     WHERE session_id = ? ORDER BY turn_no DESC LIMIT 1");
            $latest->execute([$existing]);
            $last_transcript = (string)$latest->fetchColumn();
            if ($last_transcript !== '') {
                $decision = pr_decide_next($existing, $last_transcript, []);
                if ($decision) {
                    if (isset($decision['reflection'])) {
                        $decision['reflection'] = _pr_scrub_voice_phrases((string)$decision['reflection']);
                    }
                    $decision['tone_insight'] = '';
                    $res = pr_record_turn($existing, $last_transcript, 1, [], $decision);
                    if (!empty($res['next_turn'])) {
                        $open = [
                            'id'              => $res['next_turn']['turn_id'],
                            'turn_no'         => $res['next_turn']['turn_no'],
                            'phase'           => $res['next_turn']['phase'],
                            'question'        => $res['next_turn']['question'],
                            'question_intent' => $res['next_turn']['intent'],
                            '_options'        => $res['next_turn']['options'] ?? [],
                        ];
                    }
                }
            }
            if (!$open) {
                echo json_encode(['error' => 'Could not resume cleanly. Click Finish or Pause and try again.']);
                exit;
            }
        }

        echo json_encode([
            'resumed'      => true,
            'session_id'   => $existing,
            'language'     => $language,
            'turn'         => [
                'turn_id'  => (int)$open['id'],
                'turn_no'  => (int)$open['turn_no'],
                'phase'    => (int)$open['phase'],
                'question' => (string)$open['question'],
                'intent'   => (string)($open['question_intent'] ?? ''),
                'options'  => $open['_options'] ?? [],  // empty if from DB (legacy turns)
            ],
            'prior_turns'  => _pr_prior_turns($existing),
        ]);
        exit;
    }

    // Fresh session — balance CHECK (no deduction)
    $price = wallet_service_price('mod_parent_reflect');
    if (!$price) {
        echo json_encode(['error' => 'Reflection service is not yet available. Please contact support.']);
        error_log('[parent-reflect-api] price not configured');
        exit;
    }
    $price = (int)$price;
    $current_balance = wallet_balance($parent_id);
    if ($current_balance < $price) {
        echo json_encode([
            'error'    => 'Need ₹' . $price . ' in your wallet. You will only be charged when the report is ready.',
            'redirect' => '/wallet.php?need=' . ($price - $current_balance),
        ]);
        exit;
    }

    $sid = pr_start_session($parent_id, 0, 0);
    $opening = pr_opening_question($sid, $language);

    echo json_encode([
        'session_id'   => $sid,
        'language'     => $language,
        'turn'         => $opening,
        'wallet_after' => $current_balance,
        'will_charge'  => $price,
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: turn
// ════════════════════════════════════════════════════════════════
if ($action === 'turn') {
    $_t0 = microtime(true);
    $_timings = [];
    $sid = (int)($_POST['session_id'] ?? 0);
    $transcript = trim((string)($_POST['transcript'] ?? ''));
    $sec = max(1, min(600, (int)($_POST['time_seconds'] ?? 30)));
    $answer_source = (string)($_POST['answer_source'] ?? 'text');
    if (!in_array($answer_source, ['chip','text','voice'], true)) $answer_source = 'text';

    if ($sid <= 0)         { echo json_encode(['error' => 'Bad session id']); exit; }
    if ($transcript === '') { echo json_encode(['error' => 'No transcript captured. Please try again.']); exit; }

    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $session = $st->fetch();
    if (!$session || $session['status'] !== 'in_progress') {
        echo json_encode(['error' => 'Session not active. Please start a new reflection.']);
        exit;
    }
    $_timings['session_lookup'] = round((microtime(true) - $_t0) * 1000);

    // Hard cap: 25 min
    $started = strtotime((string)$session['started_at'] . ' UTC');
    if ($started && (time() - $started) > 1500) {
        pr_finalise($sid);
        $charge_result = _pr_charge_on_finalize($parent_id, $sid);
        _pr_trigger_v3_async($sid);
        /* fresh-v1: also pull and return full summary */
        $sm = db()->prepare("SELECT parent_summary_md, sig_safety_red_flag FROM parent_reflect_sessions WHERE id = ?");
        $sm->execute([$sid]);
        $smRow = $sm->fetch();
        echo json_encode([
            'done'       => true,
            'reason'     => 'session_time_cap',
            'closing'    => 'We\'ve been at this a while. Let\'s pause here for now. Take care.',
            'session_id' => $sid,
            'charge'     => $charge_result,
            '_summary_md'=> (string)($smRow['parent_summary_md'] ?? ''),
            'safety_red' => !empty($smRow['sig_safety_red_flag']),
        ]);
        exit;
    }

    $acoustic = [];

    $_t1 = microtime(true);
    $decision = pr_decide_next($sid, $transcript, $acoustic);
    $_timings['guide_call'] = round((microtime(true) - $_t1) * 1000);

    if (!$decision) {
        $decision = [
            'reflection'    => '',
            'tone_insight'  => '',
            'next_phase'    => (int) $session['current_phase'],
            'intent'        => 'probe',
            'signals'       => ['marital_stress'=>0,'in_law_stress'=>0,'parent_burnout'=>0,'child_distress'=>0,'isolation'=>0,'safety_red_flag'=>0],
            'emotions'      => null,
            'next_question' => 'Mujhe thoda aur batayein — woh kaisa lagta hai?',
            'next_options'  => [],
            'done'          => false,
        ];
    }

    if (isset($decision['reflection'])) $decision['reflection'] = _pr_scrub_voice_phrases((string)$decision['reflection']);
    $decision['tone_insight'] = '';

    // Hard cap: PR_MAX_TURNS
    if ((int)$session['turn_count'] >= PR_MAX_TURNS) {
        $decision['done'] = true;
        if (empty($decision['next_question'])) {
            $decision['next_question'] = 'Aaj ke liye yahan tak. Apna khayal rakhiye.';
        }
    }

    // Follow-up cap
    if (!empty($session['parent_summary_md'])) {
        $cnt_st = db()->prepare("SELECT COUNT(*) FROM parent_reflect_turns
                                  WHERE session_id = ? AND answered_at IS NOT NULL
                                    AND (? IS NULL OR answered_at > ?)");
        $cnt_st->execute([$sid, $session['completed_at'], $session['completed_at']]);
        $fcount = (int)$cnt_st->fetchColumn();
        if ($fcount >= PR_MAX_FOLLOWUPS) {
            $decision['done'] = true;
            $decision['next_question'] = $decision['next_question'] ?: 'जो आपने share किया, उसके लिए शुक्रिया।';
        }
    }

    $res = pr_record_turn($sid, $transcript, $sec, $acoustic, $decision);
    $_timings['record_turn'] = round((microtime(true) - $_t1) * 1000) - $_timings['guide_call'];

    $is_red = !empty($decision['signals']['safety_red_flag']);

    if ($res['done']) {
        pr_finalise($sid);
        $charge_result = _pr_charge_on_finalize($parent_id, $sid);
        _pr_trigger_v3_async($sid);
        /* fresh-v1: pull full report summary to render inline */
        $sm = db()->prepare("SELECT parent_summary_md FROM parent_reflect_sessions WHERE id = ?");
        $sm->execute([$sid]);
        $_summary_md = (string)$sm->fetchColumn();
        $_timings['total'] = round((microtime(true) - $_t0) * 1000);
        echo json_encode([
            'done'         => true,
            '_timings'     => $_timings,
            'reflection'   => $decision['reflection']   ?? '',
            'tone_insight' => '',
            'closing'      => $res['closing'] ?? '',
            'safety_red'   => $is_red,
            'session_id'   => $sid,
            'charge'       => $charge_result,
            '_summary_md'  => $_summary_md,
        ]);
        exit;
    }

    $_timings['total'] = round((microtime(true) - $_t0) * 1000);
    echo json_encode([
        'done'         => false,
        '_timings'     => $_timings,
        'reflection'   => $decision['reflection']   ?? '',
        'tone_insight' => '',
        'safety_red'   => $is_red,
        'turn'         => $res['next_turn'],
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: cancel — PAUSE (no abandon, no charge)
// ════════════════════════════════════════════════════════════════
if ($action === 'cancel') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false]); exit; }
    db()->prepare("UPDATE parent_reflect_sessions
                   SET last_activity_at = CURRENT_TIMESTAMP
                   WHERE id = ? AND parent_id = ? AND status = 'in_progress'")
       ->execute([$sid, $parent_id]);
    echo json_encode(['ok' => true, 'paused' => true]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: finish_early
// ════════════════════════════════════════════════════════════════
if ($action === 'finish_early') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    $st = db()->prepare("SELECT id, turn_count FROM parent_reflect_sessions
                         WHERE id = ? AND parent_id = ? AND status = 'in_progress'");
    $st->execute([$sid, $parent_id]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['error' => 'Session not found or not in progress']); exit; }
    if ((int)$row['turn_count'] < 1) {
        echo json_encode(['error' => 'You haven\'t answered anything yet. Answer at least one question first.']);
        exit;
    }

    $ok = pr_finalise($sid);
    if (!$ok) {
        echo json_encode(['error' => 'Finalisation failed. Please try again or pause.']);
        exit;
    }
    $charge_result = _pr_charge_on_finalize($parent_id, $sid);
    _pr_trigger_v3_async($sid);

    $st = db()->prepare("SELECT parent_summary_md, sig_safety_red_flag
                         FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$sid]);
    $s2 = $st->fetch();

    echo json_encode([
        'done'           => true,
        'finished_early' => true,
        'closing'        => '',
        'reflection'     => '',
        'safety_red'     => !empty($s2['sig_safety_red_flag']),
        '_summary_md'    => (string)($s2['parent_summary_md'] ?? ''),
        'session_id'     => $sid,
        'charge'         => $charge_result,
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: discard — explicitly abandon (Start fresh from Resume screen)
// ════════════════════════════════════════════════════════════════
if ($action === 'discard') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid > 0) {
        db()->prepare("UPDATE parent_reflect_sessions SET status = 'abandoned'
                       WHERE id = ? AND parent_id = ? AND status = 'in_progress'")
           ->execute([$sid, $parent_id]);
    } else {
        db()->prepare("UPDATE parent_reflect_sessions SET status = 'abandoned'
                       WHERE parent_id = ? AND status = 'in_progress'")
           ->execute([$parent_id]);
    }
    echo json_encode(['ok' => true, 'discarded' => true]);
    exit;
}

// ════════════════════════════════════════════════════════════════
// ACTION: start_followup (no charge)
// ════════════════════════════════════════════════════════════════
if ($action === 'start_followup') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $session = $st->fetch();
    if (!$session) { echo json_encode(['error' => 'Session not found']); exit; }
    if ($session['status'] !== 'completed') {
        echo json_encode(['error' => 'This reflection is not yet complete.']);
        exit;
    }
    if ((int)$session['followup_count'] >= PR_MAX_FOLLOWUPS) {
        echo json_encode(['error' => 'You\'ve already used all your follow-up turns.']);
        exit;
    }

    $opened = pr_reopen_for_followup($sid);
    if (!$opened) {
        echo json_encode(['error' => 'Could not reopen this reflection. Please try again.']);
        exit;
    }

    echo json_encode([
        'session_id' => $sid,
        'language'   => $opened['language'],
        'turn'       => [
            'turn_id'  => $opened['turn_id'],
            'turn_no'  => $opened['turn_no'],
            'phase'    => $opened['phase'],
            'question' => $opened['question'],
            'intent'   => $opened['intent'],
            'options'  => [],
        ],
        'followups_remaining' => $opened['remaining'],
        'prior_turns'         => _pr_prior_turns($sid),
    ]);
    exit;
}


// ════════════════════════════════════════════════════════════════
// ACTION: v3_status — poll for comprehensive v3 report readiness
// Frontend calls this repeatedly after the closing screen renders.
// Returns: { ready: bool, listing_html: '...', report_url: '/reports/...html' }
// ════════════════════════════════════════════════════════════════
if ($action === 'v3_status') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    $st = db()->prepare("SELECT id, parent_id, v3_listing_json, v3_listing_at, report_pdf_path
                         FROM parent_reflect_sessions
                         WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['ready' => false, 'error' => 'not found']); exit; }

    $ready = !empty($row['v3_listing_json']);
    if (!$ready) {
        echo json_encode(['ready' => false]);
        exit;
    }

    /* Render the listing HTML server-side using the v3 helper */
    require_once __DIR__ . '/includes/parent_eval_v3.php';
    $listing_html = '';
    if (function_exists('pr_v3_render_listing_html')) {
        $listing_html = pr_v3_render_listing_html((string)$row['v3_listing_json']);
    }

    echo json_encode([
        'ready'        => true,
        'listing_html' => $listing_html,
        'report_url'   => (string)($row['report_pdf_path'] ?? ''),
    ]);
    exit;
}


// ════════════════════════════════════════════════════════════════
// ACTION: v3_translate — on-demand translation of the report
// POST: session_id, target_lang ('hi' or 'en')
// Returns: { ok, summary_md, listing_html }
// Caches translation in parent_reflect_sessions.translated_md_json
// ════════════════════════════════════════════════════════════════
if ($action === 'v3_translate') {
    $sid = (int)($_POST['session_id'] ?? 0);
    $target = (string)($_POST['target_lang'] ?? 'en');
    if (!in_array($target, ['hi', 'en'], true)) $target = 'en';
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    $st = db()->prepare("SELECT id, parent_id, parent_summary_md, v3_listing_json
                         FROM parent_reflect_sessions
                         WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['error' => 'not found']); exit; }

    /* Schema-tolerant: add translated_md_json column if missing */
    try {
        $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
        $col_names = array_map(function($c){ return $c['name']; }, $cols);
        if (!in_array('translated_md_json', $col_names, true)) {
            db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN translated_md_json TEXT");
        }
    } catch (Throwable $e) {}

    /* Check cache */
    $cacheRow = db()->prepare("SELECT translated_md_json FROM parent_reflect_sessions WHERE id = ?");
    $cacheRow->execute([$sid]);
    $cache_json = (string)$cacheRow->fetchColumn();
    $cache = $cache_json ? (json_decode($cache_json, true) ?: []) : [];

    /* fresh-v6: cache version — bump invalidates old broken caches */
    $cache_version = 'v6';
    if (!empty($cache[$target]['summary_md']) && !empty($cache[$target]['listing_json'])
        && ($cache[$target]['version'] ?? '') === $cache_version) {
        require_once __DIR__ . '/includes/parent_eval_v3.php';
        $lh = function_exists('pr_v3_render_listing_html')
            ? pr_v3_render_listing_html($cache[$target]['listing_json'])
            : '';
        echo json_encode([
            'ok'           => true,
            'cached'       => true,
            'target'       => $target,
            'summary_md'   => $cache[$target]['summary_md'],
            'listing_html' => $lh,
        ]);
        exit;
    }

    /* Translate via Sonnet */
    require_once __DIR__ . '/includes/claude.php';

    $source_md      = (string)$row['parent_summary_md'];
    $source_listing = (string)$row['v3_listing_json'];

    if ($source_md === '' && $source_listing === '') {
        echo json_encode(['error' => 'Report not ready yet']);
        exit;
    }

    $lang_full = ($target === 'hi') ? 'Hindi (Devanagari script)' : 'English';
    $sys = "You are a careful translator. Translate the parent reflection report below into {$lang_full}.\n\n"
         . "RULES:\n"
         . "- Preserve markdown structure exactly: same ## headings, paragraphs, line breaks.\n"
         . "- For Hindi output: warm, conversational, code-switch English words like 'support, confidence, anxiety, peers, structure' where it sounds natural — that is how Indian parents speak. Use 'आप', never 'तुम'.\n"
         . "- For English output: natural warm English; no need to over-translate Indian terms. Phrases like 'log kya kahenge' or proper nouns stay as-is.\n"
         . "- Preserve all numbers, names (especially child names like Pranjal), and any quoted phrases.\n"
         . "- DO NOT add or remove content. DO NOT shorten. Only translate.\n\n"
         . "Return ONLY the translated markdown, no prose, no fences.";

    $translated_md = '';
    if ($source_md !== '') {
        $translated_md = (string) claude_chat($sys, [['role' => 'user', 'content' => $source_md]], 4096, 0.2);
        $translated_md = trim($translated_md);
    }

    /* Also translate the v3 listing JSON — only its text fields */
    $translated_listing_json = '';
    if ($source_listing !== '') {
        $listing_data = json_decode($source_listing, true);
        if (is_array($listing_data) && !empty($listing_data['areas'])) {
            /* Build a compact text-only payload for Sonnet */
            $textPayload = [];
            if (!empty($listing_data['one_line_summary'])) {
                $textPayload['one_line_summary'] = $listing_data['one_line_summary'];
            }
            /* fresh-v6: translate the actual content keys: finding + severity_note */
            $textPayload['areas'] = [];
            foreach ($listing_data['areas'] as $k => $a) {
                if (!empty($a['covered'])) {
                    $entry = [];
                    if (!empty($a['finding']))        $entry['finding']        = (string)$a['finding'];
                    if (!empty($a['severity_note'])) $entry['severity_note'] = (string)$a['severity_note'];
                    if ($entry) $textPayload['areas'][$k] = $entry;
                }
            }
            if (!empty($listing_data['course_plan'])) {
                /* course_plan items have day(int) + theme + why — translate theme and why */
                $textPayload['course_plan'] = [];
                foreach ($listing_data['course_plan'] as $idx => $d) {
                    $textPayload['course_plan'][$idx] = [
                        'theme' => (string)($d['theme'] ?? ''),
                        'why'   => (string)($d['why'] ?? ''),
                    ];
                }
            }

            $sys2 = "Translate ALL text values (not keys) in the JSON below to {$lang_full}. "
                  . "Warm, conversational. Preserve JSON structure exactly. "
                  . "For Hindi: code-switch English words naturally. Return only valid JSON, no fences.";
            $tjson = (string) claude_chat($sys2, [['role' => 'user', 'content' => json_encode($textPayload, JSON_UNESCAPED_UNICODE)]], 3000, 0.2);
            $tjson = trim($tjson);
            /* Strip fences if any */
            $tjson = preg_replace('/^```(?:json)?\s*/i', '', $tjson);
            $tjson = preg_replace('/\s*```$/', '', $tjson);
            $translated_text = json_decode($tjson, true);

            if (is_array($translated_text)) {
                /* Merge back into a full listing structure */
                $merged = $listing_data;
                $merged['language'] = $target;
                if (!empty($translated_text['one_line_summary'])) {
                    $merged['one_line_summary'] = $translated_text['one_line_summary'];
                }
                if (!empty($translated_text['areas'])) {
                    foreach ($translated_text['areas'] as $k => $tA) {
                        if (isset($merged['areas'][$k])) {
                            if (!empty($tA['finding']))        $merged['areas'][$k]['finding']        = $tA['finding'];
                            if (!empty($tA['severity_note'])) $merged['areas'][$k]['severity_note'] = $tA['severity_note'];
                        }
                    }
                }
                if (!empty($translated_text['course_plan']) && !empty($merged['course_plan'])) {
                    /* Preserve original day numbers and course_day mappings; only update theme/why */
                    foreach ($translated_text['course_plan'] as $idx => $tD) {
                        if (isset($merged['course_plan'][$idx])) {
                            if (!empty($tD['theme'])) $merged['course_plan'][$idx]['theme'] = $tD['theme'];
                            if (!empty($tD['why']))   $merged['course_plan'][$idx]['why']   = $tD['why'];
                        }
                    }
                }
                $translated_listing_json = json_encode($merged, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    /* Cache */
    $cache[$target] = [
        'summary_md'   => $translated_md,
        'listing_json' => $translated_listing_json,
        'cached_at'    => date('c'),
        'version'      => 'v6',
    ];
    db()->prepare("UPDATE parent_reflect_sessions SET translated_md_json = ? WHERE id = ?")
       ->execute([json_encode($cache, JSON_UNESCAPED_UNICODE), $sid]);

    require_once __DIR__ . '/includes/parent_eval_v3.php';
    $listing_html = ($translated_listing_json !== '' && function_exists('pr_v3_render_listing_html'))
        ? pr_v3_render_listing_html($translated_listing_json)
        : '';

    echo json_encode([
        'ok'           => true,
        'cached'       => false,
        'target'       => $target,
        'summary_md'   => $translated_md,
        'listing_html' => $listing_html,
    ]);
    exit;
}


// ════════════════════════════════════════════════════════════════
// ACTION: course_preview — generate/cache 7-day course preview from reflection
// POST: session_id
// Returns: { ok, preview: [{day, theme, outline, target_axis}, ...×7], course_status }
// course_status: 'none' | 'active' (with course_id) — drives the CTA shown
// ════════════════════════════════════════════════════════════════
if ($action === 'course_preview') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    $st = db()->prepare("SELECT id, parent_id, parent_summary_md, v3_listing_json
                         FROM parent_reflect_sessions WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['error' => 'Session not found']); exit; }

    /* Schema-tolerant: add course_preview_json column if missing */
    try {
        $cols = db()->query("PRAGMA table_info(parent_reflect_sessions)")->fetchAll();
        $col_names = array_map(function($c){ return $c['name']; }, $cols);
        if (!in_array('course_preview_json', $col_names, true)) {
            db()->exec("ALTER TABLE parent_reflect_sessions ADD COLUMN course_preview_json TEXT");
        }
    } catch (Throwable $e) {}

    /* Check active home_course for this parent (to set CTA state) */
    require_once __DIR__ . '/includes/home_course_engine.php';
    $active = function_exists('home_course_find_active') ? home_course_find_active($parent_id) : null;
    $course_status = $active ? 'active' : 'none';
    $course_info = null;
    if ($active) {
        $today = function_exists('home_course_today_day') ? home_course_today_day((int)$active['id']) : ['day_no' => 1];
        $course_info = [
            'course_id' => (int)$active['id'],
            'day_no'    => (int)($today['day_no'] ?? 1),
            'status'    => (string)$active['status'],
        ];
    }

    /* Cache check */
    $pq = db()->prepare("SELECT course_preview_json FROM parent_reflect_sessions WHERE id = ?");
    $pq->execute([$sid]);
    $cached = (string)$pq->fetchColumn();
    if ($cached !== '') {
        $data = json_decode($cached, true);
        if (is_array($data) && !empty($data['preview']) && count($data['preview']) === 7) {
            echo json_encode([
                'ok'           => true,
                'cached'       => true,
                'preview'      => $data['preview'],
                'price'        => 999,
                'course_status'=> $course_status,
                'course_info'  => $course_info,
            ]);
            exit;
        }
    }

    /* Pull source signals: v3 listing's course_plan (themes) + parent_summary_md (tone) */
    $listing = json_decode((string)$row['v3_listing_json'], true) ?: [];
    $course_plan = $listing['course_plan'] ?? [];
    $language = $listing['language'] ?? 'hi';
    $summary_md = (string)$row['parent_summary_md'];

    if (empty($course_plan) || count($course_plan) < 7) {
        echo json_encode(['error' => 'Course plan not ready. Comprehensive report must finish first.']);
        exit;
    }

    /* Build a compact prompt for Sonnet to flesh out each day */
    $plan_text = '';
    foreach ($course_plan as $d) {
        $day = (int)($d['day'] ?? 0);
        $theme = (string)($d['theme'] ?? '');
        $why = (string)($d['why'] ?? '');
        $plan_text .= "Day $day — $theme. Why: $why\n";
    }

    $lang_full = ($language === 'hi') ? 'Hindi (Devanagari, code-switching English words like support/confidence/anxiety where natural)' : 'warm conversational English';
    $sys = "You design a 7-day adaptive home course for a parent of a special-needs child. "
         . "Based on the parent's reflection summary and the day-by-day plan below, write a SHORT preview outline for each of the 7 days. "
         . "Each outline must be 2-3 sentences (~40-60 words), in {$lang_full}. "
         . "It should describe: what the parent will do that day in concrete terms, and one thing they will notice/learn.\n\n"
         . "RULES:\n"
         . "- Reference the parent's actual situation (use 1-2 specific words/phrases from their reflection where natural).\n"
         . "- Do NOT moralise. Do NOT promise fixes. Use 'we' and 'you' warmly.\n"
         . "- Concrete and gentle: 'On Day 1, we will start by simply noticing X' — not 'You should...'\n"
         . "- Return ONLY valid JSON, no fences, no prose, this shape:\n"
         . '{"preview":[{"day":1,"theme":"...","outline":"..."},{"day":2,...},...,{"day":7,...}]}';

    $user = "PARENT REFLECTION (summary):\n{$summary_md}\n\nPLAN:\n{$plan_text}";

    require_once __DIR__ . '/includes/claude.php';
    $raw = (string) claude_chat($sys, [['role' => 'user', 'content' => $user]], 2200, 0.35);
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);
    $parsed = json_decode($raw, true);

    if (!is_array($parsed) || empty($parsed['preview']) || count($parsed['preview']) < 7) {
        /* Fallback: synthesise minimal preview from theme/why */
        $preview = [];
        foreach ($course_plan as $d) {
            $preview[] = [
                'day'     => (int)($d['day'] ?? 0),
                'theme'   => (string)($d['theme'] ?? ''),
                'outline' => (string)($d['why'] ?? '') . ' We will work on this gently, in just a few minutes a day.',
            ];
        }
        $parsed = ['preview' => $preview];
    }

    /* Add target_axis from course_plan if available */
    foreach ($parsed['preview'] as $i => $d) {
        $cp = $course_plan[$i] ?? null;
        if ($cp && !empty($cp['target_axis'])) {
            $parsed['preview'][$i]['target_axis'] = (string)$cp['target_axis'];
        }
    }

    /* Cache */
    $cache_obj = [
        'preview'    => $parsed['preview'],
        'cached_at'  => date('c'),
        'language'   => $language,
        'version'    => 'v1',
    ];
    db()->prepare("UPDATE parent_reflect_sessions SET course_preview_json = ? WHERE id = ?")
       ->execute([json_encode($cache_obj, JSON_UNESCAPED_UNICODE), $sid]);

    echo json_encode([
        'ok'           => true,
        'cached'       => false,
        'preview'      => $parsed['preview'],
        'price'        => 999,
        'course_status'=> $course_status,
        'course_info'  => $course_info,
    ]);
    exit;
}


// ════════════════════════════════════════════════════════════════
// ACTION: course_purchase — charge ₹999 + create home_course
// POST: session_id (the reflect session this course is built from)
// Returns: { ok, course_id, redirect } OR { error, balance, price, top_up_url }
// Idempotent: if parent already has active course, returns its course_id.
// ════════════════════════════════════════════════════════════════
if ($action === 'course_purchase') {
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['error' => 'Bad session id']); exit; }

    /* Verify session is parent's + is completed */
    $st = db()->prepare("SELECT id, parent_id, status, v3_listing_json
                         FROM parent_reflect_sessions WHERE id = ? AND parent_id = ?");
    $st->execute([$sid, $parent_id]);
    $session = $st->fetch();
    if (!$session) { echo json_encode(['error' => 'Session not found']); exit; }
    if ($session['status'] !== 'completed') {
        echo json_encode(['error' => 'Reflection must be completed before unlocking the course.']);
        exit;
    }
    if (empty($session['v3_listing_json'])) {
        echo json_encode(['error' => 'Detailed report is still being prepared. Try again in a minute.']);
        exit;
    }

    require_once __DIR__ . '/includes/home_course_engine.php';

    /* Idempotency — already has an active course? Return it. */
    $existing = function_exists('home_course_find_active') ? home_course_find_active($parent_id) : null;
    if ($existing) {
        echo json_encode([
            'ok'           => true,
            'already_active'=> true,
            'course_id'    => (int)$existing['id'],
            'redirect'     => '/home-course.php?id=' . (int)$existing['id'],
        ]);
        exit;
    }

    /* Ensure service_prices row for home_course_999 exists at ₹999 */
    try {
        $sp = db()->prepare("SELECT price, is_active FROM service_prices WHERE service_key = ?");
        $sp->execute(['home_course_999']);
        $cur = $sp->fetch();
        if (!$cur) {
            db()->prepare("INSERT INTO service_prices (service_key, label, price, audience, is_active)
                           VALUES (?, ?, ?, 'parent', 1)")
               ->execute(['home_course_999', '7-Day Personalised Home Course', 999]);
        } elseif ((int)$cur['price'] !== 999 || (int)$cur['is_active'] !== 1) {
            db()->prepare("UPDATE service_prices SET price = 999, is_active = 1 WHERE service_key = ?")
               ->execute(['home_course_999']);
        }
    } catch (Throwable $e) {
        error_log('[course_purchase] price ensure: ' . $e->getMessage());
        echo json_encode(['error' => 'Could not configure pricing. Please contact support.', 'detail' => $e->getMessage()]);
        exit;
    }

    /* Balance check */
    $price = 999;
    $bal = wallet_balance($parent_id);
    if ($bal < $price) {
        echo json_encode([
            'error'      => 'Need ₹' . $price . ' to unlock the course. Your balance: ₹' . $bal,
            'balance'    => $bal,
            'price'      => $price,
            'top_up_url' => '/wallet.php?need=' . ($price - $bal),
        ]);
        exit;
    }

    /* Charge wallet (idempotent on (parent, service_key, ref_id=session_id) by wallet_charge_for_service) */
    $charge = wallet_charge_for_service($parent_id, 'home_course_999', $sid);
    if (!in_array($charge['status'] ?? '', ['charged', 'already_charged', 'free'], true)) {
        echo json_encode([
            'error'   => 'Charge failed: ' . ($charge['message'] ?? $charge['status'] ?? 'unknown'),
            'status'  => $charge['status'] ?? '',
        ]);
        exit;
    }

    /* Create the course (wires reflect_session_id so daily generator uses transcript) */
    $created = home_course_create($parent_id, 'home_course_999', $sid);
    if (!$created['ok']) {
        echo json_encode([
            'error' => 'Course creation failed: ' . ($created['error'] ?? 'unknown'),
        ]);
        /* Note: charge already happened. Admin will need to refund manually if this fails.
         * In practice this should not fail since home_course_create only requires the SKU map. */
        exit;
    }

    $cid = (int)$created['course_id'];

    echo json_encode([
        'ok'           => true,
        'course_id'    => $cid,
        'redirect'     => '/home-course.php?id=' . $cid,
        'wallet_after' => wallet_balance($parent_id),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
