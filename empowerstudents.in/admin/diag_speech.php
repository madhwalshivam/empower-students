<?php
/**
 * /admin/diag_speech.php — temporary diagnostic for the Speech & Language test.
 *
 * Reads-only by default. Optional repair buttons (clearly labelled).
 * Delete this file once the speech module test is verified.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/catalogue.php';
if (file_exists(__DIR__ . '/../includes/catalogue_alias.php')) {
    require_once __DIR__ . '/../includes/catalogue_alias.php';
}

$msg = '';

// ─── Repair actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'promote_inprogress' && !empty($_POST['assessment_id'])) {
        $aid = (int)$_POST['assessment_id'];
        // Only promote if currently in_progress and has any data
        $st = db()->prepare("SELECT * FROM assessments WHERE id = ? AND status = 'in_progress'");
        $st->execute([$aid]);
        $row = $st->fetch();
        if ($row) {
            db()->prepare("UPDATE assessments SET status='done',
                           ai_summary = COALESCE(ai_summary, 'Manually promoted by admin (no AI summary was generated). Re-take the assessment for a full report.'),
                           completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)
                           WHERE id = ?")
                ->execute([$aid]);
            $msg = "Assessment #{$aid} marked done.";
        } else {
            $msg = "Assessment #{$aid} not found or not in_progress.";
        }
    }

    if ($action === 'change_module' && !empty($_POST['assessment_id']) && !empty($_POST['new_module'])) {
        $aid = (int)$_POST['assessment_id'];
        $nm  = trim((string)$_POST['new_module']);
        db()->prepare("UPDATE assessments SET module = ? WHERE id = ?")->execute([$nm, $aid]);
        $msg = "Assessment #{$aid} module changed to '{$nm}'.";
    }

    if ($action === 'refund_charge' && !empty($_POST['ledger_id'])) {
        $lid = (int)$_POST['ledger_id'];
        $st = db()->prepare("SELECT * FROM wallet_ledger WHERE id = ?");
        $st->execute([$lid]);
        $row = $st->fetch();
        if ($row && (int)$row['amount'] < 0) {
            $refund = -(int)$row['amount'];
            wallet_post((int)$row['parent_id'], $refund, 'refund_' . $row['service_key'], (int)$row['ref_id'],
                        "Manual refund of ledger #{$lid} ({$row['service_key']})", 'admin');
            $msg = "Refunded ₹{$refund} to parent #{$row['parent_id']}.";
        }
    }

    header('Location: /admin/diag_speech.php?msg=' . urlencode($msg));
    exit;
}

if (!empty($_GET['msg'])) $msg = (string)$_GET['msg'];

// ─── Diagnostic readout ───
$page_title = 'Speech diagnostic';
require __DIR__ . '/../includes/header.php';

// All parents with their children
$parents = db()->query("SELECT p.id, p.whatsapp, p.name, p.credits,
                              (SELECT COUNT(*) FROM children c WHERE c.parent_id = p.id) AS n_children
                       FROM parents p ORDER BY p.id DESC")->fetchAll();

?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px;font-family:system-ui,sans-serif;">

  <h1 style="font-size:24px;font-weight:700;margin-bottom:8px;">🔍 Speech &amp; Language diagnostic</h1>
  <p style="color:#64748B;font-size:14px;margin-bottom:20px;">Read-only inspection of catalogue ownership and speech assessments. Use repair buttons cautiously.</p>

  <?php if ($msg): ?>
    <div style="background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#7A4F01;">
      <?= e($msg) ?>
    </div>
  <?php endif; ?>

  <!-- Reverse alias check -->
  <h2 style="font-size:16px;font-weight:600;margin:18px 0 6px;">Alias lookup test</h2>
  <div style="background:#F1F5F9;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;line-height:1.6;margin-bottom:18px;">
    <?php if (function_exists('legacy_keys_for_catalogue')): ?>
      ✓ <b>legacy_keys_for_catalogue()</b> is available.<br>
      mod_speech_language → [<?= e(implode(', ', legacy_keys_for_catalogue('mod_speech_language'))) ?>]
    <?php else: ?>
      ✗ <b>legacy_keys_for_catalogue()</b> is NOT defined. The patched <code>includes/catalogue_alias.php</code> isn't loaded.
    <?php endif; ?>
  </div>

  <!-- module.php on-disk check -->
  <h2 style="font-size:16px;font-weight:600;margin:18px 0 6px;">module.php on-disk patch check</h2>
  <div style="background:#F1F5F9;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;line-height:1.6;margin-bottom:18px;">
    <?php
    $modphp_path = __DIR__ . '/../module.php';
    if (file_exists($modphp_path)) {
        $modphp = file_get_contents($modphp_path);
        $size = strlen($modphp);
        $mtime = date('Y-m-d H:i:s', filemtime($modphp_path));
        $has_alias_call = strpos($modphp, 'legacy_keys_for_catalogue($service_key)') !== false
                       || strpos($modphp, 'legacy_keys_for_catalogue(') !== false;
        $has_old_query  = strpos($modphp, 'SELECT * FROM assessments WHERE child_id = ? AND module = ?') !== false;
        $has_session_marker = strpos($modphp, 'catalogue_assessment_return') !== false;
        ?>
        File: /module.php (<?= number_format($size) ?> bytes, mtime <?= e($mtime) ?>)<br>
        <?= $has_alias_call    ? '✓' : '✗' ?> calls <b>legacy_keys_for_catalogue()</b> for the Report tab<br>
        <?= !$has_old_query    ? '✓' : '✗' ?> old query (<code>module = ?</code>) is gone<br>
        <?= $has_session_marker ? '✓' : '✗' ?> sets <b>catalogue_assessment_return</b> session marker<br>
        <br>
        <?php if (!$has_alias_call): ?>
          <span style="color:#B91C1C;font-weight:bold;">⚠ The patched module.php is NOT on the server.</span>
          The Report tab will keep returning empty no matter what — re-upload <code>module.php</code> from the cleanup_fix zip.
        <?php else: ?>
          <span style="color:#15803D;">module.php has the alias-aware Report tab code.</span>
        <?php endif; ?>
        <?php
    } else {
        echo '<span style="color:#B91C1C;">module.php not found.</span>';
    }
    ?>
  </div>

  <!-- Run the actual Report-tab query and see what comes back -->
  <h2 style="font-size:16px;font-weight:600;margin:18px 0 6px;">Live Report-tab query trace (child #1, mod_speech_language)</h2>
  <pre style="background:#F1F5F9;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;line-height:1.6;margin-bottom:18px;white-space:pre-wrap;"><?php
    $trace_cid = 1;
    $trace_key = 'mod_speech_language';
    $alias_keys = function_exists('legacy_keys_for_catalogue')
        ? legacy_keys_for_catalogue($trace_key)
        : [$trace_key];
    $placeholders = implode(',', array_fill(0, count($alias_keys), '?'));
    $sql = "SELECT id, child_id, module, status, score, completed_at FROM assessments
            WHERE child_id = ? AND module IN ($placeholders) AND status = 'done'
            ORDER BY id DESC LIMIT 5";
    echo "alias_keys = [" . e(implode(', ', $alias_keys)) . "]\n";
    echo "SQL: " . e($sql) . "\n";
    echo "params: [{$trace_cid}, " . e(implode(', ', $alias_keys)) . "]\n\n";

    $st = db()->prepare($sql);
    $st->execute(array_merge([$trace_cid], $alias_keys));
    $rows = $st->fetchAll();
    if (empty($rows)) {
        echo "<span style='color:#B91C1C;font-weight:bold;'>0 rows returned ✗</span>\n";
        echo "But row #17 with module='speech', child_id=1, status='done' exists in your data.\n";
        echo "Possible causes:\n";
        echo "  - status column has a trailing space or different case\n";
        echo "  - module column has a trailing space\n";
        echo "  - child_id is stored as text, not int\n";
    } else {
        echo "<span style='color:#15803D;'>" . count($rows) . " rows returned ✓</span>\n";
        foreach ($rows as $r) {
            echo "  #" . (int)$r['id'] . "  module=" . e($r['module']) . "  status=" . e($r['status'])
               . "  score=" . ($r['score'] ?? '—') . "  completed=" . e($r['completed_at'] ?? '—') . "\n";
        }
    }

    // ALSO run a "loose" query — no status filter, no IN clause — to see EVERYTHING for child 1
    echo "\n--- Sanity check: ALL assessments for child #{$trace_cid} ---\n";
    $sanity = db()->prepare("SELECT id, module, status, LENGTH(module) AS module_len, LENGTH(status) AS status_len FROM assessments WHERE child_id = ? ORDER BY id DESC LIMIT 10");
    $sanity->execute([$trace_cid]);
    foreach ($sanity->fetchAll() as $r) {
        echo "  #" . (int)$r['id']
           . "  module='" . e($r['module']) . "' (len " . (int)$r['module_len'] . ")"
           . "  status='" . e($r['status']) . "' (len " . (int)$r['status_len'] . ")\n";
    }
    ?></pre>

  <!-- Parents -->
  <h2 style="font-size:16px;font-weight:600;margin:18px 0 6px;">Parents (newest first)</h2>
  <table style="width:100%;border-collapse:collapse;font-size:13px;background:white;border:1px solid #E2E8F0;border-radius:8px;overflow:hidden;">
    <thead style="background:#F8FAFC;text-align:left;">
      <tr><th style="padding:8px;">ID</th><th style="padding:8px;">Name / WhatsApp</th><th style="padding:8px;">Credits</th><th style="padding:8px;">Children</th><th style="padding:8px;">Inspect</th></tr>
    </thead>
    <tbody>
      <?php foreach ($parents as $p): ?>
        <tr style="border-top:1px solid #F1F5F9;">
          <td style="padding:8px;">#<?= (int)$p['id'] ?></td>
          <td style="padding:8px;"><strong><?= e($p['name'] ?: '—') ?></strong><br><span style="color:#64748B;font-size:11px;"><?= e($p['whatsapp']) ?></span></td>
          <td style="padding:8px;">₹<?= (int)$p['credits'] ?></td>
          <td style="padding:8px;"><?= (int)$p['n_children'] ?></td>
          <td style="padding:8px;"><a href="?inspect=<?= (int)$p['id'] ?>#parent<?= (int)$p['id'] ?>" style="color:#6366F1;text-decoration:underline;">Inspect →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
  // Detailed inspection of one parent
  $inspect_pid = (int)($_GET['inspect'] ?? 0);
  if ($inspect_pid > 0):
    $children = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY id ASC");
    $children->execute([$inspect_pid]);
    $children = $children->fetchAll();

    // All wallet ledger rows for this parent — speech-related and module-related
    $ledger = db()->prepare("SELECT * FROM wallet_ledger
                             WHERE parent_id = ?
                               AND (service_key LIKE 'mod_%'
                                 OR service_key IN ('speech','spontaneous','care_pack'))
                             ORDER BY id DESC LIMIT 50");
    $ledger->execute([$inspect_pid]);
    $ledger = $ledger->fetchAll();
  ?>

  <h2 id="parent<?= $inspect_pid ?>" style="font-size:18px;font-weight:700;margin:30px 0 10px;">
    🔎 Parent #<?= $inspect_pid ?>
  </h2>

  <h3 style="font-size:14px;font-weight:600;color:#475569;margin:14px 0 6px;">Children</h3>
  <ul style="background:white;border:1px solid #E2E8F0;border-radius:8px;padding:14px 22px;font-size:13px;">
    <?php foreach ($children as $c): ?>
      <li><strong>#<?= (int)$c['id'] ?> <?= e($c['name']) ?></strong>
        — DOB <?= e($c['dob']) ?> · age <?= number_format(calc_age_years($c['dob']), 1) ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <h3 style="font-size:14px;font-weight:600;color:#475569;margin:14px 0 6px;">Wallet ledger (speech / module / care_pack rows, newest first)</h3>
  <table style="width:100%;border-collapse:collapse;font-size:12px;background:white;border:1px solid #E2E8F0;border-radius:8px;overflow:hidden;">
    <thead style="background:#F8FAFC;text-align:left;">
      <tr>
        <th style="padding:6px;">id</th>
        <th style="padding:6px;">when</th>
        <th style="padding:6px;">service_key</th>
        <th style="padding:6px;">amount</th>
        <th style="padding:6px;">ref_id</th>
        <th style="padding:6px;">balance after</th>
        <th style="padding:6px;">reason</th>
        <th style="padding:6px;">actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($ledger)): ?>
        <tr><td colspan="8" style="padding:14px;text-align:center;color:#94A3B8;">No matching ledger rows.</td></tr>
      <?php else: foreach ($ledger as $l): ?>
        <tr style="border-top:1px solid #F1F5F9;">
          <td style="padding:6px;">#<?= (int)$l['id'] ?></td>
          <td style="padding:6px;"><?= e(substr($l['created_at'], 0, 16)) ?></td>
          <td style="padding:6px;font-family:monospace;"><?= e($l['service_key']) ?></td>
          <td style="padding:6px;color:<?= (int)$l['amount'] < 0 ? '#DC2626' : '#16A34A' ?>;font-weight:600;">
            <?= (int)$l['amount'] >= 0 ? '+' : '' ?><?= (int)$l['amount'] ?>
          </td>
          <td style="padding:6px;"><?= $l['ref_id'] !== null ? (int)$l['ref_id'] : '—' ?></td>
          <td style="padding:6px;"><?= (int)$l['balance_after'] ?></td>
          <td style="padding:6px;color:#64748B;"><?= e($l['reason'] ?: '') ?></td>
          <td style="padding:6px;">
            <?php if ((int)$l['amount'] < 0): ?>
              <form method="post" style="margin:0;display:inline;" onsubmit="return confirm('Refund ₹<?= -(int)$l['amount'] ?> to parent?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="refund_charge">
                <input type="hidden" name="ledger_id" value="<?= (int)$l['id'] ?>">
                <button style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;">Refund</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h3 style="font-size:14px;font-weight:600;color:#475569;margin:18px 0 6px;">Speech-related assessments for this parent's children</h3>

  <?php
  $cids = array_column($children, 'id');
  if (!empty($cids)) {
      $ph = implode(',', array_fill(0, count($cids), '?'));
      $st = db()->prepare("SELECT * FROM assessments
                           WHERE child_id IN ($ph)
                             AND module IN ('mod_speech_language','speech','spontaneous')
                           ORDER BY id DESC");
      $st->execute($cids);
      $assessments = $st->fetchAll();
  } else { $assessments = []; }
  ?>

  <table style="width:100%;border-collapse:collapse;font-size:12px;background:white;border:1px solid #E2E8F0;border-radius:8px;overflow:hidden;">
    <thead style="background:#F8FAFC;text-align:left;">
      <tr>
        <th style="padding:6px;">id</th>
        <th style="padding:6px;">child_id</th>
        <th style="padding:6px;">module</th>
        <th style="padding:6px;">status</th>
        <th style="padding:6px;">score</th>
        <th style="padding:6px;">created</th>
        <th style="padding:6px;">completed</th>
        <th style="padding:6px;">summary preview</th>
        <th style="padding:6px;">actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($assessments)): ?>
        <tr><td colspan="9" style="padding:14px;text-align:center;color:#94A3B8;">No speech assessments found at all. The Finish click never reached <code>finalize_assessment</code> — try retaking the test.</td></tr>
      <?php else: foreach ($assessments as $a): ?>
        <tr style="border-top:1px solid #F1F5F9;">
          <td style="padding:6px;">#<?= (int)$a['id'] ?></td>
          <td style="padding:6px;"><?= (int)$a['child_id'] ?></td>
          <td style="padding:6px;font-family:monospace;"><?= e($a['module']) ?></td>
          <td style="padding:6px;">
            <span style="background:<?= $a['status']==='done' ? '#DCFCE7' : '#FEF3C7' ?>;color:<?= $a['status']==='done' ? '#166534' : '#854D0E' ?>;padding:2px 8px;border-radius:99px;font-weight:600;">
              <?= e($a['status']) ?>
            </span>
          </td>
          <td style="padding:6px;"><?= $a['score'] !== null ? number_format((float)$a['score'], 1) : '—' ?></td>
          <td style="padding:6px;"><?= e(substr($a['created_at'], 0, 16)) ?></td>
          <td style="padding:6px;"><?= e($a['completed_at'] ? substr($a['completed_at'], 0, 16) : '—') ?></td>
          <td style="padding:6px;color:#64748B;max-width:240px;">
            <?= e(mb_substr((string)($a['ai_summary'] ?? ''), 0, 80)) ?><?= mb_strlen((string)($a['ai_summary'] ?? '')) > 80 ? '…' : '' ?>
          </td>
          <td style="padding:6px;">
            <?php if ($a['status'] === 'in_progress'): ?>
              <form method="post" style="margin:0 0 4px 0;" onsubmit="return confirm('Mark this in-progress assessment as done? It will appear in the Report tab but with a placeholder summary (you can re-take for a real AI report).');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="promote_inprogress">
                <input type="hidden" name="assessment_id" value="<?= (int)$a['id'] ?>">
                <button style="background:#FEF3C7;color:#854D0E;border:1px solid #FCD34D;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;">Promote to done</button>
              </form>
            <?php endif; ?>
            <?php if ($a['module'] !== 'mod_speech_language'): ?>
              <form method="post" style="margin:0;" onsubmit="return confirm('Re-tag this row\\'s module from <?= e($a['module']) ?> to mod_speech_language? Only useful if the alias lookup isn\\'t finding it.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_module">
                <input type="hidden" name="assessment_id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="new_module" value="mod_speech_language">
                <button style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;">Re-tag → mod_speech_language</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($a['ai_summary']): ?>
          <tr><td colspan="9" style="padding:8px 12px 14px;background:#F8FAFC;font-size:11px;color:#475569;">
            <strong>Full summary (id #<?= (int)$a['id'] ?>):</strong><br>
            <pre style="white-space:pre-wrap;margin:6px 0 0;font-family:inherit;"><?= e(mb_substr((string)$a['ai_summary'], 0, 1500)) ?></pre>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <p style="font-size:12px;color:#64748B;margin-top:14px;">
    💡 <strong>How to read this:</strong> If you see an assessment with <code>status=in_progress</code>, the Finish click never completed
    (browser closed mid-AI-call, network interrupted). Click "Promote to done" to keep your ₹499 charge meaningful — the Report tab will then
    show the row, with a placeholder summary, and you can choose to re-take for a real AI report. If you see no rows at all, the assessment never
    reached the finalize step on the server — re-take it (you've already paid; it won't charge again because of the catalogue alias gate).
  </p>

  <?php endif; // inspect ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
