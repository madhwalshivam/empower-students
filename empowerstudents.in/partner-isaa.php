<?php
/**
 * partner-isaa.php
 *
 * Conduct an ISAA assessment, one item at a time. Loads item N (1..40),
 * shows full description + testing guidance, 5 radio options, save+next.
 *
 * URL params:
 *   ?id=<assessment_id>       — required (the assessment being conducted)
 *   ?item=<1..40>             — optional (which item to display; defaults to first unanswered)
 *
 * POST handlers:
 *   action=create_for_child  — create a fresh in-progress assessment for a child
 *                              registered by this partner (used from queue page)
 *   action=save              — save the score for one item, advance to next
 *   action=submit            — final submit (calls AI to generate report)
 *   action=cancel            — abandon (sets status='cancelled')
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/auth.php';        // for calc_age_years()
require_once __DIR__ . '/includes/partner_auth.php';
require_once __DIR__ . '/includes/isaa_helpers.php';

$is_admin_test = !empty($_SESSION['admin_id']);
if ($is_admin_test) {
    // Admin-test mode: synthesize a "virtual partner" so all queries that
    // filter by partner_id work. Admin can administer any assessment for testing.
    $partner = [
        'id'                  => 0,           // 0 = admin sentinel
        'name'                => 'Admin (test)',
        'whatsapp'            => 'admin',
        'qualification'       => 'Administrator',
        'institution'         => 'EmpowerStudents',
        'can_administer_isaa' => 1,
        'revenue_share'       => 0,           // admin doesn't get partner payout
        'status'              => 'active',
    ];
} else {
    $partner = require_partner();
    if ((int)$partner['can_administer_isaa'] !== 1) {
        $_SESSION['flash_error'] = 'Your account is not certified for ISAA.';
        header('Location: /partner-dashboard.php');
        exit;
    }
}

// ────────────────────────────────────────────────────────────
// POST handlers
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_for_child') {
        // Partner is starting a fresh ISAA on one of their registered children
        // (Admin in test mode can start one for ANY child)
        $cid = (int)($_POST['child_id'] ?? 0);
        if ($is_admin_test) {
            $st = db()->prepare("SELECT * FROM children WHERE id = ?");
            $st->execute([$cid]);
        } else {
            $st = db()->prepare("SELECT * FROM children WHERE id = ? AND registered_by_partner_id = ?");
            $st->execute([$cid, (int)$partner['id']]);
        }
        $child = $st->fetch();
        if (!$child) {
            $_SESSION['flash_error'] = 'You can only start an ISAA for a child you registered.';
            header('Location: /partner-isaa-queue.php');
            exit;
        }

        // Refuse if there's already an active or completed assessment for this child
        $st = db()->prepare("SELECT id FROM isaa_assessments
                             WHERE child_id = ? AND status IN ('paid','in_progress','submitted')");
        $st->execute([$cid]);
        if ($exists = $st->fetchColumn()) {
            header('Location: /partner-isaa.php?id=' . (int)$exists);
            exit;
        }

        db()->prepare("INSERT INTO isaa_assessments
            (child_id, parent_id, partner_id, status, paid_at, started_at)
            VALUES (?, ?, ?, 'in_progress', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
           ->execute([$cid, (int)$child['parent_id'], $is_admin_test ? null : (int)$partner['id']]);
        $aid = (int) db()->lastInsertId();
        header('Location: /partner-isaa.php?id=' . $aid);
        exit;
    }

    $aid = (int)($_POST['assessment_id'] ?? 0);
    if ($is_admin_test) {
        $st = db()->prepare("SELECT * FROM isaa_assessments WHERE id = ?");
        $st->execute([$aid]);
    } else {
        $st = db()->prepare("SELECT * FROM isaa_assessments WHERE id = ? AND partner_id = ?");
        $st->execute([$aid, (int)$partner['id']]);
    }
    $assess = $st->fetch();
    if (!$assess) {
        header('Location: /partner-isaa-queue.php');
        exit;
    }

    if ($assess['status'] === 'submitted') {
        header('Location: /partner-isaa-view.php?id=' . $aid);
        exit;
    }

    if ($action === 'save') {
        $item_no = (int)($_POST['item_no'] ?? 0);
        $score   = (int)($_POST['score'] ?? 0);
        $notes   = trim((string)($_POST['notes'] ?? ''));
        if ($item_no < 1 || $item_no > 40 || $score < 1 || $score > 5) {
            $_SESSION['flash_error'] = 'Invalid response.';
            header('Location: /partner-isaa.php?id=' . $aid . '&item=' . $item_no);
            exit;
        }

        // Upsert response
        db()->prepare("INSERT INTO isaa_responses (assessment_id, item_no, score, notes)
                       VALUES (?, ?, ?, ?)
                       ON CONFLICT (assessment_id, item_no)
                       DO UPDATE SET score = excluded.score, notes = excluded.notes")
           ->execute([$aid, $item_no, $score, $notes ?: null]);

        // Bump status to in_progress if it was paid
        if ($assess['status'] === 'paid') {
            db()->prepare("UPDATE isaa_assessments SET status = 'in_progress', started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = ?")
               ->execute([$aid]);
        }

        // Advance: go to next unanswered item, or to a specified next item
        $next = (int)($_POST['next_item'] ?? ($item_no + 1));
        if ($next > 40) {
            // All done? Go to submit-confirm view
            $count = (int) db()->query("SELECT COUNT(*) FROM isaa_responses WHERE assessment_id = $aid")->fetchColumn();
            if ($count >= 40) {
                header('Location: /partner-isaa.php?id=' . $aid . '&review=1');
                exit;
            }
            $next = 1;  // will land on first unanswered via the GET handler
        }
        header('Location: /partner-isaa.php?id=' . $aid . '&item=' . $next);
        exit;
    }

    if ($action === 'submit') {
        // Final submit: compute scores, generate AI report, mark submitted
        $count = (int) db()->query("SELECT COUNT(*) FROM isaa_responses WHERE assessment_id = $aid")->fetchColumn();
        if ($count < 40) {
            $_SESSION['flash_error'] = 'Please complete all 40 items before submitting (' . $count . '/40 done).';
            header('Location: /partner-isaa.php?id=' . $aid);
            exit;
        }

        // Pull responses
        $resp = [];
        $st = db()->prepare("SELECT item_no, score FROM isaa_responses WHERE assessment_id = ?");
        $st->execute([$aid]);
        foreach ($st->fetchAll() as $r) $resp[(int)$r['item_no']] = (int)$r['score'];

        $scores = isaa_compute_scores($resp);
        $total = (int)$scores['total'];
        $cat   = isaa_classify($total);
        $pct   = isaa_disability_pct($total);
        $hc    = isaa_high_concern_items($resp);

        // Pull child for AI context
        $child_st = db()->prepare("SELECT * FROM children WHERE id = ?");
        $child_st->execute([(int)$assess['child_id']]);
        $child = $child_st->fetch();

        // Generate AI report (~10-20s for English) — then Hindi (~10-20s more)
        $report    = isaa_generate_report($child, $total, $cat, $pct, $scores['domains'], $hc);
        $report_hi = isaa_generate_report_hindi($child, $total, $cat, $pct, $scores['domains'], $hc);

        // Generate share credentials (token + PIN). Retry up to 3 times if a token collision happens.
        $share_token = null; $share_pin = null;
        for ($try = 0; $try < 3; $try++) {
            $cred = isaa_generate_share_credentials();
            $exists = db()->prepare("SELECT 1 FROM isaa_assessments WHERE share_token = ?");
            $exists->execute([$cred['token']]);
            if (!$exists->fetchColumn()) {
                $share_token = $cred['token'];
                $share_pin   = $cred['pin'];
                break;
            }
        }

        // Persist
        db()->prepare("UPDATE isaa_assessments
                       SET status = 'submitted',
                           submitted_at = CURRENT_TIMESTAMP,
                           total_score = ?, category = ?, disability_pct = ?,
                           domain_scores_json = ?,
                           summary_md = ?, advice_md = ?,
                           summary_md_hi = ?, advice_md_hi = ?,
                           share_token = ?, share_pin = ?
                       WHERE id = ?")
           ->execute([
               $total, $cat, $pct,
               json_encode($scores['domains'], JSON_UNESCAPED_UNICODE),
               $report['summary_md'],
               $report['advice_md'],
               $report_hi['summary_md_hi'],
               $report_hi['advice_md_hi'],
               $share_token, $share_pin,
               $aid,
           ]);

        // Credit partner earnings (50% of ₹999 = ₹499.50, stored as 49950 paise)
        // via existing partner_payouts ledger. Skipped when admin runs in test mode.
        if (!$is_admin_test) {
            try {
                $share = (float)($partner['revenue_share'] ?? 0.50);
                $partner_amt = round(999 * $share, 2);
                db()->prepare("INSERT INTO partner_payouts
                    (partner_id, parent_id, service_key, gross_amount, partner_amount, share_rate_used, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')")
                   ->execute([
                       (int)$partner['id'],
                       (int)$assess['parent_id'],
                       'mod_isaa_assessment',
                       999,
                       $partner_amt,
                       $share,
                   ]);
            } catch (Throwable $e) {
                error_log('[isaa partner payout] ' . $e->getMessage());
            }
        }

        $_SESSION['flash_ok'] = 'Assessment submitted. Report generated.';
        header('Location: /partner-isaa-view.php?id=' . $aid);
        exit;
    }

    if ($action === 'cancel') {
        db()->prepare("UPDATE isaa_assessments SET status = 'cancelled' WHERE id = ?")->execute([$aid]);
        $_SESSION['flash_ok'] = 'Assessment cancelled.';
        header('Location: /partner-isaa-queue.php');
        exit;
    }
}

// ────────────────────────────────────────────────────────────
// GET: render the form
// ────────────────────────────────────────────────────────────
$aid = (int)($_GET['id'] ?? 0);
if ($is_admin_test) {
    $st = db()->prepare("SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                                p.name AS parent_name
                         FROM isaa_assessments a
                         JOIN children c ON c.id = a.child_id
                         LEFT JOIN parents p ON p.id = a.parent_id
                         WHERE a.id = ?");
    $st->execute([$aid]);
} else {
    $st = db()->prepare("SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                                p.name AS parent_name
                         FROM isaa_assessments a
                         JOIN children c ON c.id = a.child_id
                         LEFT JOIN parents p ON p.id = a.parent_id
                         WHERE a.id = ? AND a.partner_id = ?");
    $st->execute([$aid, (int)$partner['id']]);
}
$assess = $st->fetch();
if (!$assess) {
    $_SESSION['flash_error'] = 'Assessment not found or not assigned to you.';
    header('Location: ' . ($is_admin_test ? '/admin/isaa-test.php' : '/partner-isaa-queue.php'));
    exit;
}
if ($assess['status'] === 'submitted') {
    header('Location: /partner-isaa-view.php?id=' . $aid);
    exit;
}
if ($assess['status'] === 'cancelled') {
    $_SESSION['flash_error'] = 'This assessment was cancelled.';
    header('Location: /partner-isaa-queue.php');
    exit;
}

// Pull existing responses
$resp_rows = db()->prepare("SELECT item_no, score, notes FROM isaa_responses WHERE assessment_id = ?");
$resp_rows->execute([$aid]);
$responses = [];
foreach ($resp_rows->fetchAll() as $r) {
    $responses[(int)$r['item_no']] = ['score' => (int)$r['score'], 'notes' => (string)($r['notes'] ?? '')];
}
$completed_count = count($responses);

// Two review modes:
//  - is_review (full review with submit option): only when all 40 done
//  - is_partial_review: ?review=1 with fewer than 40 done — shows progress without submit option
$is_review         = !empty($_GET['review']) && $completed_count >= 40;
$is_partial_review = !empty($_GET['review']) && $completed_count < 40;

// Determine which item to show
$requested_item = isset($_GET['item']) ? (int)$_GET['item'] : 0;
if ($requested_item < 1 || $requested_item > 40) {
    // Auto-pick first unanswered (or item 1 if all answered)
    $requested_item = 1;
    for ($i = 1; $i <= 40; $i++) {
        if (!isset($responses[$i])) { $requested_item = $i; break; }
        if ($i === 40) $requested_item = 40;  // all done — show last
    }
}

// Pull current item from question bank
$qst = db()->prepare("SELECT * FROM isaa_questions WHERE item_no = ?");
$qst->execute([$requested_item]);
$question = $qst->fetch();

$age = round((float)calc_age_years($assess['child_dob']), 1);
$progress_pct = (int) round($completed_count * 100 / 40);

// Calculate previous/next item navigation
$prev_item = $requested_item > 1 ? $requested_item - 1 : null;
$next_item = $requested_item < 40 ? $requested_item + 1 : null;

$page_title = 'ISAA — ' . $assess['child_name'];
require __DIR__ . '/includes/header.php';
?>

<main class="max-w-3xl mx-auto px-4 py-6">

  <!-- Header: child + progress -->
  <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4">
    <div class="flex items-baseline justify-between gap-3 mb-3 flex-wrap">
      <div>
        <h1 class="text-lg font-bold text-slate-900">ISAA — <?= e($assess['child_name']) ?></h1>
        <p class="text-xs text-slate-500"><?= $age ?> yrs · <?= e($assess['child_gender'] ?: '—') ?> · Parent: <?= e($assess['parent_name'] ?? '—') ?></p>
      </div>
      <div class="flex items-center gap-2">
        <a href="<?= $is_admin_test ? '/admin/isaa-test.php' : '/partner-isaa-queue.php' ?>"
           class="text-xs text-slate-500 hover:text-indigo-600 hover:underline">
          ← <?= $is_admin_test ? 'Admin test' : 'Queue' ?>
        </a>
      </div>
    </div>

    <!-- Progress bar -->
    <div class="flex items-center gap-2">
      <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
        <div class="h-full brand-grad transition-all" style="width: <?= $progress_pct ?>%;"></div>
      </div>
      <span class="text-xs font-semibold text-slate-600 whitespace-nowrap"><?= $completed_count ?> / 40</span>
    </div>

    <?php if (!$is_review): ?>
      <p class="text-[11px] text-slate-500 mt-2 leading-snug">
        💾 Each answer auto-saves. Safe to pause anytime — just close the tab and come back later from your <?= $is_admin_test ? 'admin test page' : 'queue' ?>. 40 items take 40-60 minutes.
      </p>
    <?php endif; ?>
  </div>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-3">
      <?= e($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <?php if ($is_review): ?>
    <!-- ════════════════ REVIEW SCREEN before submit ════════════════ -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="text-lg font-bold text-slate-900 mb-2">✓ All 40 items answered</h2>
      <p class="text-sm text-slate-600 mb-4">
        Review before submitting. After submission, the AI will generate a parent-facing report (this takes ~20 seconds).
      </p>

      <!-- Quick scores preview -->
      <?php $preview = isaa_compute_scores(array_map(fn($r) => $r['score'], $responses)); ?>
      <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 mb-4">
        <p class="text-sm"><strong>Total ISAA score:</strong> <?= (int)$preview['total'] ?> / 200
          → <strong><?= e(isaa_category_label(isaa_classify((int)$preview['total']))) ?></strong>
          · Disability: <strong><?= isaa_disability_pct((int)$preview['total']) ?>%</strong>
        </p>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-2 text-xs">
          <?php foreach ($preview['domains'] as $dno => $d): ?>
            <div class="text-slate-700">D<?= $dno ?>: <?= (int)$d['raw'] ?>/<?= (int)$d['max'] ?> (<?= (int)$d['pct'] ?>%)</div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Per-item summary list -->
      <details class="mb-4">
        <summary class="cursor-pointer text-sm font-semibold text-slate-700 hover:text-indigo-600">View all 40 responses</summary>
        <div class="mt-3 space-y-1 max-h-80 overflow-y-auto pr-2">
          <?php
          $all_q = db()->query("SELECT item_no, item_label, domain_no FROM isaa_questions ORDER BY item_no")->fetchAll();
          foreach ($all_q as $q):
              $r = $responses[(int)$q['item_no']] ?? null;
              $score = $r['score'] ?? 0;
              $score_color = $score >= 4 ? 'bg-rose-100 text-rose-800' : ($score === 3 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700');
          ?>
            <div class="flex items-baseline gap-2 text-xs">
              <span class="text-slate-400 w-7">#<?= (int)$q['item_no'] ?></span>
              <span class="flex-1 text-slate-700 truncate" title="<?= e($q['item_label']) ?>"><?= e($q['item_label']) ?></span>
              <span class="px-2 py-0.5 rounded font-semibold <?= $score_color ?>"><?= $score ?></span>
              <a href="/partner-isaa.php?id=<?= $aid ?>&item=<?= (int)$q['item_no'] ?>" class="text-indigo-600 hover:underline">edit</a>
            </div>
          <?php endforeach; ?>
        </div>
      </details>

      <form method="post" onsubmit="return submitFinalize(this)">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="assessment_id" value="<?= $aid ?>">
        <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full md:w-auto">
          ✓ Submit & generate report
        </button>
        <p class="text-xs text-slate-500 mt-2">Once submitted, the parent can view the report. A new ₹499.50 entry will be added to your earnings.</p>
      </form>

      <!-- Loading overlay shown while AI generates report -->
      <div id="submitOverlay" class="hidden fixed inset-0 z-[200] bg-slate-900/70 backdrop-blur-sm flex items-center justify-center px-4">
        <div class="bg-white rounded-2xl shadow-2xl p-7 max-w-sm w-full text-center">
          <div class="mx-auto mb-4 relative" style="width:84px; height:84px;">
            <svg viewBox="0 0 50 50" class="absolute inset-0" style="width:84px; height:84px; animation: tg-spin 1.6s linear infinite;">
              <defs>
                <linearGradient id="sgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stop-color="#4f46e5"/>
                  <stop offset="50%" stop-color="#06b6d4"/>
                  <stop offset="100%" stop-color="#10b981"/>
                </linearGradient>
              </defs>
              <circle cx="25" cy="25" r="22" fill="none" stroke="url(#sgGrad)" stroke-width="3" stroke-linecap="round" stroke-dasharray="90 60"/>
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
              <span style="font-size:32px;">🧠</span>
            </div>
          </div>
          <h3 class="text-lg font-bold text-slate-900 mb-2">EmpowerStudents is generating the report…</h3>
          <p class="text-sm text-slate-600">This takes 15–25 seconds. Please don't close the page.</p>
        </div>
      </div>
      <style>@keyframes tg-spin { to { transform: rotate(360deg); } }</style>
      <script>
      function submitFinalize(form) {
        var btn = form.querySelector('button');
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
        var ov = document.getElementById('submitOverlay');
        if (ov) ov.classList.remove('hidden');
        return true;
      }
      </script>
    </div>
  <?php elseif ($is_partial_review): ?>
    <!-- ════════════════ PARTIAL REVIEW (in-progress, not all 40 done yet) ════════════════ -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <div class="flex items-baseline justify-between gap-3 mb-3 flex-wrap">
        <h2 class="text-lg font-bold text-slate-900">Review your <?= $completed_count ?> answers so far</h2>
        <a href="/partner-isaa.php?id=<?= $aid ?>" class="text-sm text-indigo-600 hover:underline">↻ Continue from where you left off</a>
      </div>
      <p class="text-sm text-slate-600 mb-4">
        <?= 40 - $completed_count ?> items still to answer. Click any item to edit; click "Continue" to resume.
      </p>

      <?php
      // Quick total preview from what's answered so far
      $partial_resp_for_calc = [];
      foreach ($responses as $no => $r) $partial_resp_for_calc[$no] = $r['score'];
      $partial_preview = isaa_compute_scores($partial_resp_for_calc);
      ?>
      <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 mb-4 text-sm">
        <p class="text-slate-700">
          <strong>Running total (partial):</strong> <?= (int)$partial_preview['total'] ?> from <?= $completed_count ?> items.
          <span class="text-slate-500 text-xs">Final score and report only available after all 40 items are answered.</span>
        </p>
      </div>

      <h3 class="text-sm font-semibold text-slate-700 mb-2">All 40 items</h3>
      <div class="space-y-1 max-h-96 overflow-y-auto pr-2 mb-4">
        <?php
        $all_q = db()->query("SELECT item_no, item_label, domain_no FROM isaa_questions ORDER BY item_no")->fetchAll();
        foreach ($all_q as $q):
            $r = $responses[(int)$q['item_no']] ?? null;
            $score = $r ? $r['score'] : 0;
            $score_color = !$r ? 'bg-slate-100 text-slate-400' :
                ($score >= 4 ? 'bg-rose-100 text-rose-800' :
                ($score === 3 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'));
        ?>
          <div class="flex items-baseline gap-2 text-xs py-1 border-b border-slate-100 last:border-0">
            <span class="text-slate-400 w-7">#<?= (int)$q['item_no'] ?></span>
            <span class="text-[10px] px-1 py-0.5 rounded bg-indigo-50 text-indigo-700">D<?= (int)$q['domain_no'] ?></span>
            <span class="flex-1 text-slate-700 truncate" title="<?= e($q['item_label']) ?>"><?= e($q['item_label']) ?></span>
            <span class="px-2 py-0.5 rounded font-semibold <?= $score_color ?>"><?= $r ? $score : '—' ?></span>
            <a href="/partner-isaa.php?id=<?= $aid ?>&item=<?= (int)$q['item_no'] ?>" class="text-indigo-600 hover:underline"><?= $r ? 'edit' : 'do' ?></a>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="flex flex-wrap gap-2 items-center">
        <a href="/partner-isaa.php?id=<?= $aid ?>"
           class="brand-grad text-white font-semibold px-5 py-2 rounded-lg hover:opacity-90">
          ↻ Continue
        </a>
        <a href="<?= $is_admin_test ? '/admin/isaa-test.php' : '/partner-isaa-queue.php' ?>"
           class="text-sm text-slate-500 hover:underline">⏸ Save &amp; come back later</a>
      </div>
    </div>

  <?php elseif ($question): ?>
    <!-- ════════════════ ITEM FORM ════════════════ -->
    <div class="bg-white border-2 border-indigo-200 rounded-2xl p-5">
      <div class="flex items-baseline gap-2 mb-2">
        <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 font-semibold">
          Domain <?= (int)$question['domain_no'] ?>
        </span>
        <span class="text-xs text-slate-500"><?= e($question['domain_label']) ?></span>
      </div>

      <h2 class="text-xl font-bold text-slate-900 mb-2">
        Item <?= (int)$question['item_no'] ?>: <?= e($question['item_label']) ?>
      </h2>

      <p class="text-sm text-slate-700 mb-3 leading-relaxed">
        <?= nl2br(e($question['description'])) ?>
      </p>

      <?php if (!empty($question['testing_guidance'])): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
          <p class="text-xs font-bold text-amber-800 uppercase tracking-wider mb-1">How to test</p>
          <p class="text-sm text-amber-900"><?= nl2br(e($question['testing_guidance'])) ?></p>

          <?php if (!empty($question['testing_guidance_hi'])): ?>
            <p class="text-xs font-bold text-rose-700 uppercase tracking-wider mt-3 mb-1">हिंदी में पूछने के लिए</p>
            <p class="text-sm text-rose-700" lang="hi" style="font-family: 'Inter', 'Noto Sans Devanagari', system-ui, sans-serif;"><?= nl2br(e($question['testing_guidance_hi'])) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" id="isaaItemForm" class="space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="assessment_id" value="<?= $aid ?>">
        <input type="hidden" name="item_no" value="<?= (int)$question['item_no'] ?>">
        <?php if ($next_item !== null): ?>
          <input type="hidden" name="next_item" value="<?= $next_item ?>">
        <?php else: ?>
          <input type="hidden" name="next_item" value="41">
        <?php endif; ?>

        <div class="space-y-2">
          <p class="text-xs font-bold text-slate-700 uppercase tracking-wider">Rating</p>
          <?php
            $cur_score = isset($responses[(int)$question['item_no']]) ? $responses[(int)$question['item_no']]['score'] : 0;

            $profile = (string)($question['rating_profile'] ?? 'frequency');

            // Each profile uses the SAME 1-5 score values (preserving the
            // 40-200 total range and ISAA classification) but presents
            // contextually appropriate labels and helper text.
            $opts_by_profile = [
                // Default — for behaviours that occur with varying frequency
                'frequency' => [
                    1 => ['Rarely',     'Up to 20%',  'Normal for age'],
                    2 => ['Sometimes',  '21-40%',     'May need attention but generally normal'],
                    3 => ['Frequently', '41-60%',     'Interferes with daily life · disabling'],
                    4 => ['Mostly',     '61-80%',    'Significantly hampers daily activities'],
                    5 => ['Always',     '81-100%',   'Major handicap · seldom appropriate'],
                ],
                // For graded behaviours / abilities — rated by intensity not frequency
                'severity' => [
                    1 => ['Not present',  'Like typical age',  'Behaviour is age-appropriate'],
                    2 => ['Mild',         'Slight difficulty', 'Mostly fine, occasional difficulty'],
                    3 => ['Moderate',     'Clear difficulty',  'Definitely interferes with daily life'],
                    4 => ['Marked',       'Significant',       'Significantly limits daily activities'],
                    5 => ['Severe',       'Major impairment',  'Almost always shows this difficulty'],
                ],
                // For historical / ability items — rated by clarity of presence
                'presence' => [
                    1 => ['Not present',       'No evidence',          'No history or sign of this'],
                    2 => ['Slight indication', 'Vague / uncertain',    'Some hint but unclear'],
                    3 => ['Some evidence',     'Probably present',     'Likely true but not strong'],
                    4 => ['Clearly present',   'Definitely true',      'Confirmed by report or observation'],
                    5 => ['Strongly present',  'Marked / pervasive',   'Strong, repeated, and obvious'],
                ],
            ];
            $opts = $opts_by_profile[$profile] ?? $opts_by_profile['frequency'];

            $profile_hint = [
                'frequency' => 'Rate how OFTEN this is observed.',
                'severity'  => 'Rate the SEVERITY (intensity), not frequency.',
                'presence'  => 'Rate how CLEARLY this is present (historical fact or ability).',
            ];
          ?>
          <?php if (!empty($profile_hint[$profile])): ?>
            <p class="text-xs text-indigo-600 italic mb-1"><?= e($profile_hint[$profile]) ?></p>
          <?php endif; ?>

          <?php foreach ($opts as $sc => $label):
              $is_picked = ($cur_score === $sc);
          ?>
            <label class="block border <?= $is_picked ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 bg-white' ?> rounded-lg p-3 cursor-pointer hover:border-indigo-400 transition">
              <input type="radio" name="score" value="<?= $sc ?>" <?= $is_picked ? 'checked' : '' ?> required class="mr-2">
              <span class="font-semibold text-slate-900"><?= $sc ?> — <?= e($label[0]) ?></span>
              <span class="text-xs text-slate-500 ml-1">(<?= e($label[1]) ?>)</span>
              <p class="text-xs text-slate-600 mt-1 ml-5"><?= e($label[2]) ?></p>
            </label>
          <?php endforeach; ?>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Notes (optional)</label>
          <textarea name="notes" rows="2" maxlength="500"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    placeholder="e.g. Mother reports child is fine at home but struggles at school"><?= e($responses[(int)$question['item_no']]['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center justify-between gap-3 pt-2 border-t border-slate-100">
          <?php if ($prev_item !== null): ?>
            <a href="/partner-isaa.php?id=<?= $aid ?>&item=<?= $prev_item ?>"
               class="text-sm text-slate-500 hover:text-indigo-600 hover:underline">← Item <?= $prev_item ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <div class="flex gap-2">
            <button class="brand-grad text-white font-semibold px-5 py-2 rounded-lg hover:opacity-90">
              <?= $next_item !== null ? 'Save & Next →' : 'Save & Review' ?>
            </button>
          </div>
        </div>
      </form>

      <!-- Save-and-exit + review actions, separate from the form so they don't trigger save -->
      <div class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs">
        <div class="flex flex-wrap gap-2 text-slate-500">
          <?php if ($completed_count > 0): ?>
            <a href="/partner-isaa.php?id=<?= $aid ?>&review=1"
               class="hover:text-indigo-600 hover:underline">📋 Review all <?= $completed_count ?> answers</a>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-3 items-center">
          <span class="text-emerald-700 font-medium">✓ Auto-saved</span>
          <a href="<?= $is_admin_test ? '/admin/isaa-test.php' : '/partner-isaa-queue.php' ?>"
             class="text-slate-500 hover:text-indigo-600 hover:underline">⏸ Pause &amp; come back later</a>
        </div>
      </div>
    </div>

    <!-- Quick navigator: jump to any item -->
    <details class="mt-4 bg-white border border-slate-200 rounded-2xl p-4">
      <summary class="cursor-pointer text-sm font-semibold text-slate-700 hover:text-indigo-600">Jump to item</summary>
      <div class="mt-3 grid grid-cols-8 md:grid-cols-10 gap-1.5 text-xs">
        <?php for ($i = 1; $i <= 40; $i++):
          $is_done    = isset($responses[$i]);
          $is_current = ($i === $requested_item);
          $cls = $is_current ? 'border-indigo-500 bg-indigo-100 text-indigo-800 font-bold' :
                 ($is_done ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-500');
        ?>
          <a href="/partner-isaa.php?id=<?= $aid ?>&item=<?= $i ?>"
             class="text-center py-1.5 rounded border <?= $cls ?> hover:border-indigo-400">
            <?= $i ?><?= $is_done ? ' ✓' : '' ?>
          </a>
        <?php endfor; ?>
      </div>
    </details>

    <!-- Cancel -->
    <div class="mt-4 text-center">
      <form method="post" onsubmit="return confirm('Cancel this assessment? All responses will be lost.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="assessment_id" value="<?= $aid ?>">
        <button class="text-xs text-slate-400 hover:text-rose-600 underline">Cancel assessment</button>
      </form>
    </div>

  <?php else: ?>
    <div class="bg-rose-50 border border-rose-200 rounded-lg p-4 text-rose-800">
      Item <?= $requested_item ?> not found. The ISAA scale has 40 items only.
    </div>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
