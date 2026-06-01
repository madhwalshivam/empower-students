<?php
/**
 * recover_eval.php — One-shot recovery for stuck in-progress evaluations.
 *
 * Usage:
 *   1. Upload to /empowerstudents.in/
 *   2. Log into empowerstudents.in normally (same parent account that ran the eval)
 *   3. Visit https://empowerstudents.in/recover_eval.php
 *   4. Pick which stuck session to recover (if multiple)
 *   5. Confirm — script force-finalizes it, generates the clinical report,
 *      and links you to the report page
 *   6. DELETE this file from the server
 *
 * What it does:
 *   - Lists all in_progress sessions for the logged-in parent (any age)
 *   - For each, shows: child name, started_at, questions_answered, last activity
 *   - On "Recover" click: marks status='completed', runs eval_finalise() which
 *     generates the markdown report AND triggers eval_clinical_analyse() for
 *     the 5-axis structured report
 *   - Redirects to /eval-speech.php which auto-shows the most recent report
 *
 * Safety:
 *   - Only operates on sessions belonging to the LOGGED-IN parent (no admin override)
 *   - Will not touch sessions with <2 answered questions (too little data — better to redo)
 *   - Skips sessions already 'completed' or 'abandoned'
 *   - Idempotent: re-running on an already-recovered session is a no-op
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/eval_schema.php';
require_once __DIR__ . '/includes/eval_engine.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$page_title = 'Recover stuck evaluation';

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$msg = '';
$msg_type = 'info';

// ── Find all in_progress sessions for this parent ──
$st = db()->prepare(
    "SELECT s.id, s.child_id, s.module, s.started_at, s.questions_asked,
            c.name AS child_name, c.dob AS child_dob,
            (SELECT COUNT(*) FROM eval_questions q WHERE q.session_id = s.id AND q.is_correct IS NOT NULL) AS scored_q,
            (SELECT MAX(answered_at) FROM eval_questions q WHERE q.session_id = s.id) AS last_answer_at
     FROM eval_sessions s
     LEFT JOIN children c ON c.id = s.child_id
     WHERE s.parent_id = ? AND s.status = 'in_progress'
     ORDER BY s.id DESC"
);
$st->execute([$parent_id]);
$stuck = $st->fetchAll();

// ── Handle recovery POST ──
if ($action === 'recover' && !empty($_POST['session_id'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = '❌ CSRF check failed. Please reload and try again.';
        $msg_type = 'error';
    } else {
        $sid = (int)$_POST['session_id'];

        // Re-verify ownership
        $own = db()->prepare("SELECT id, status FROM eval_sessions WHERE id = ? AND parent_id = ?");
        $own->execute([$sid, $parent_id]);
        $owned = $own->fetch();

        if (!$owned) {
            $msg = '❌ That session does not belong to you.';
            $msg_type = 'error';
        } elseif ($owned['status'] !== 'in_progress') {
            $msg = '⚠ That session is already ' . $owned['status'] . '. Visit /eval-speech.php to see the report.';
            $msg_type = 'info';
        } else {
            // Check it has enough data
            $cnt_st = db()->prepare("SELECT COUNT(*) FROM eval_questions WHERE session_id = ? AND is_correct IS NOT NULL");
            $cnt_st->execute([$sid]);
            $scored = (int)$cnt_st->fetchColumn();

            if ($scored < 2) {
                $msg = '❌ This session only has ' . $scored . ' scored question(s) — not enough to make a report. '
                     . 'Mark it abandoned and run a fresh evaluation instead.';
                $msg_type = 'error';
                // Mark it abandoned so it stops appearing in the resume list
                db()->prepare("UPDATE eval_sessions SET status = 'abandoned' WHERE id = ?")->execute([$sid]);
            } else {
                // Force-finalize: this generates report_md AND triggers eval_clinical_analyse()
                try {
                    $ok = eval_finalise($sid);
                    if ($ok) {
                        $msg = '✅ Session #' . $sid . ' recovered! '
                             . 'Click here to view your report → '
                             . '<a href="/eval-speech.php" style="color:#4f46e5;font-weight:bold;text-decoration:underline">Go to report</a>';
                        $msg_type = 'success';
                        // Reload the stuck list — should now be one fewer
                        $st->execute([$parent_id]);
                        $stuck = $st->fetchAll();
                    } else {
                        $msg = '❌ Recovery failed: eval_finalise() returned false. Check error log.';
                        $msg_type = 'error';
                    }
                } catch (Throwable $e) {
                    $msg = '❌ Recovery threw: ' . htmlspecialchars($e->getMessage());
                    $msg_type = 'error';
                    error_log('[recover_eval] ' . $e->getMessage());
                }
            }
        }
    }
}

// ── Render ──
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Recover stuck evaluation</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body { font-family: system-ui, -apple-system, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; background: #f8fafc; color: #1e293b; }
  h1 { color: #1e293b; margin-bottom: 0.25rem; }
  .lead { color: #64748b; font-size: 14px; margin-bottom: 2rem; }
  .card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
  .msg { padding: 1rem; border-radius: 8px; margin: 1rem 0; font-size: 14px; line-height: 1.5; }
  .msg.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
  .msg.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
  .msg.info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
  .empty { background: #f1f5f9; padding: 2rem; text-align: center; border-radius: 12px; color: #64748b; }
  .session { display: flex; gap: 1rem; align-items: flex-start; }
  .session-info { flex: 1; min-width: 0; }
  .session-info h3 { margin: 0 0 0.5rem; color: #1e293b; }
  .session-info .meta { color: #64748b; font-size: 13px; line-height: 1.6; }
  .session-info .meta strong { color: #1e293b; }
  .btn { display: inline-block; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; border: 0; font-size: 14px; }
  .btn-primary { background: #4f46e5; color: white; }
  .btn-primary:hover { background: #4338ca; }
  .btn-primary:disabled { background: #cbd5e1; cursor: not-allowed; }
  .pill { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 11px; font-weight: 600; }
  .pill.ok { background: #dcfce7; color: #166534; }
  .pill.warn { background: #fef3c7; color: #92400e; }
  .pill.bad { background: #fee2e2; color: #991b1b; }
  .footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center; }
  .footer code { background: #f1f5f9; padding: 0.1rem 0.4rem; border-radius: 4px; }
</style>
</head>
<body>

<h1>🛠 Recover stuck evaluation</h1>
<p class="lead">Logged in as: <strong><?= htmlspecialchars($parent['name'] ?: $parent['whatsapp']) ?></strong></p>

<?php if ($msg): ?>
  <div class="msg <?= $msg_type ?>"><?= $msg ?></div>
<?php endif; ?>

<?php if (empty($stuck)): ?>
  <div class="empty">
    🎉 No stuck sessions found for your account.<br>
    <br>
    <a href="/eval-speech.php" style="color:#4f46e5">Go to evaluation page →</a>
  </div>
<?php else: ?>
  <p style="color:#475569; font-size:14px;">
    Found <strong><?= count($stuck) ?></strong> in-progress session(s) on your account.
    For each, you can recover (force-finalize and generate the report) or skip.
  </p>

  <?php foreach ($stuck as $s):
    $age_yrs = $s['child_dob'] ? round((float)calc_age_years($s['child_dob']), 1) : '?';
    $started = $s['started_at'] ? strtotime((string)$s['started_at'] . ' UTC') : 0;
    $started_str = $started ? date('d M Y, h:i A', $started) : '—';
    $hours_ago = $started ? round((time() - $started) / 3600, 1) : '?';
    $scored = (int)$s['scored_q'];
    $can_recover = $scored >= 2;

    $pill_class = 'bad';
    $pill_text = 'Not enough data';
    if ($scored >= 6) { $pill_class = 'ok'; $pill_text = 'Plenty of data — clean report expected'; }
    elseif ($scored >= 2) { $pill_class = 'warn'; $pill_text = 'Some data — partial report'; }
  ?>
    <div class="card">
      <div class="session">
        <div class="session-info">
          <h3>
            <?= htmlspecialchars($s['child_name'] ?: '(unknown child)') ?>
            <span style="color:#64748b; font-size:14px; font-weight:normal;">
              · <?= $age_yrs ?> yrs · session #<?= (int)$s['id'] ?>
            </span>
          </h3>
          <div class="meta">
            <div>Started: <strong><?= htmlspecialchars($started_str) ?></strong> (<?= $hours_ago ?>h ago)</div>
            <div>Module: <strong><?= htmlspecialchars($s['module']) ?></strong></div>
            <div>Questions answered &amp; scored: <strong><?= $scored ?></strong> / <?= (int)$s['questions_asked'] ?> served</div>
            <div style="margin-top:0.5rem;"><span class="pill <?= $pill_class ?>"><?= $pill_text ?></span></div>
          </div>
        </div>
        <div>
          <?php if ($can_recover): ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="recover">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" class="btn btn-primary"
                      onclick="this.disabled=true; this.textContent='Recovering…'; this.form.submit();">
                Recover &amp; show report
              </button>
            </form>
            <p style="font-size:11px; color:#94a3b8; margin-top:0.5rem; text-align:right;">
              Takes 20-40 seconds<br>(AI generates the report)
            </p>
          <?php else: ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="recover">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" class="btn" style="background:#f1f5f9; color:#64748b;">
                Mark abandoned
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="footer">
  ⚠ <strong>Delete this file after use.</strong>
  Leaving <code>recover_eval.php</code> on the server is a minor security risk.
</div>

</body>
</html>
