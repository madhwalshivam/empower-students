<?php
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../includes/expert_report.php';
require_once __DIR__ . '/../includes/claude.php';
require_once __DIR__ . '/../includes/auth.php';     // for calc_age_years, age_band

ensure_expert_report_text_columns();

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) { header('Location: /admin/expert_orders.php'); exit; }

// Load order + parent + child
$st = db()->prepare("
    SELECT o.*, p.name AS parent_name, p.whatsapp, p.email,
           c.name AS child_name, c.dob, c.gender, c.class_grade,
           c.diagnosis, c.mother_tongue
      FROM expert_report_orders o
      JOIN parents p ON p.id = o.parent_id
      JOIN children c ON c.id = o.child_id
     WHERE o.id = ?
");
$st->execute([$order_id]);
$order = $st->fetch();
if (!$order) { flash('Order not found.', 'rose'); header('Location: /admin/expert_orders.php'); exit; }

$age  = calc_age_years($order['dob']);
$band = age_band($age);

// Load all assessment data for this child
$ast = db()->prepare("
    SELECT module, status, score, ai_summary, flags, raw_json, completed_at, level_reached
      FROM assessments
     WHERE child_id = ? AND status = 'done'
     ORDER BY module, completed_at DESC
");
$ast->execute([(int)$order['child_id']]);
$assessment_rows = $ast->fetchAll();
$by_module = [];
foreach ($assessment_rows as $r) {
    if (!isset($by_module[$r['module']])) $by_module[$r['module']] = $r;
}

$module_titles = [
    'health'              => 'Health',
    'pulse_check'         => 'Pulse / breath',
    'mind_power'          => 'Mind power',
    'emotions'            => 'Emotions',
    'behavior'            => 'Behaviour',
    'general_awareness'   => 'General awareness',
    'special_talent'      => 'Special talent',
    'speech'              => 'Speech',
    'spontaneous'         => 'Spontaneous speech',
    'math'                => 'Maths',
    'language'            => 'Language',
    'parent_index'        => 'Parent index',
    'diet'                => 'Diet',
];

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'ai_generate_draft') {
        $bundle = [];
        $all_flags = [];
        foreach ($by_module as $mod => $r) {
            $bundle[$mod] = [
                'title'        => $module_titles[$mod] ?? $mod,
                'score'        => $r['score'],
                'level'        => $r['level_reached'],
                'ai_summary'   => $r['ai_summary'],
                'flags'        => json_decode($r['flags'] ?? '[]', true) ?: [],
                'completed_at' => $r['completed_at'],
            ];
            foreach (($bundle[$mod]['flags']) as $f) $all_flags[] = ['module' => $mod, 'flag' => $f];
        }

        $sys = "You are a senior paediatric clinician (associated with Dr. P. K. Jha, AIIMS-trained neurosurgeon, 30+ yrs) writing a Detailed Expert Report for a parent. "
             . "Tone: warm, plain English, specific, with clinical insight a parent would not get from automated tools. "
             . "Indian context. Suggest clinical referral when flags warrant. Keep it personal, as if Dr. Jha's team has reviewed this child.";
        $user = "Child: " . $order['child_name'] . ", age " . round((float)$age, 1) . " yrs ($band), gender: " . $order['gender'] . ".\n"
              . "Diagnosis on record: " . ($order['diagnosis'] ?: 'none') . ".\n"
              . "Mother tongue: " . ($order['mother_tongue'] ?: 'unknown') . ".\n"
              . "Parent: " . $order['parent_name'] . " (" . $order['whatsapp'] . ").\n\n"
              . "Assessment results across modules:\n"
              . json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
              . "\n\nFlags raised:\n" . json_encode($all_flags, JSON_UNESCAPED_UNICODE)
              . "\n\nProduce a comprehensive parent-facing Detailed Expert Report with these sections (use the section headings exactly as below, in CAPS, on their own line):\n"
              . "EXECUTIVE SUMMARY\n(3-4 lines highlighting the overall picture)\n\n"
              . "STRENGTHS WE NOTICED\n(2-4 bullet points starting with ✓)\n\n"
              . "DOMAIN-BY-DOMAIN OBSERVATIONS\n(one paragraph per assessed module with what the score means clinically)\n\n"
              . "PRIORITY CONCERNS\n(specific issues to address, with reasoning. If none, say so plainly.)\n\n"
              . "OUR RECOMMENDATIONS\n(5-7 specific things to do over the next 4-12 weeks)\n\n"
              . "WHEN TO CONSULT A SPECIALIST\n(specific triggers and which type of specialist - paediatrician / OT / SLP / psychologist / neurologist)\n\n"
              . "PERSONAL NOTE FROM OUR TEAM\n(2-3 lines of warm encouragement)\n\n"
              . "Maximum 1200 words. Plain text. Use line breaks generously between sections.";

        $draft = claude_chat($sys, [['role' => 'user', 'content' => $user]], 3500, 0.5);
        if ($draft === '') {
            flash('AI did not return a draft. Please try again or write manually.', 'rose');
        } else {
            db()->prepare("UPDATE expert_report_orders SET report_text = ? WHERE id = ?")
               ->execute([$draft, $order_id]);
            flash('AI draft generated. Edit it below and click "Save & deliver" when ready.', 'emerald');
        }
        header('Location: /admin/expert_report_edit.php?order_id=' . $order_id); exit;
    }

    if ($action === 'save_draft') {
        $text = trim($_POST['report_text'] ?? '');
        db()->prepare("UPDATE expert_report_orders SET report_text = ? WHERE id = ?")
           ->execute([$text, $order_id]);
        flash('Draft saved.', 'emerald');
        header('Location: /admin/expert_report_edit.php?order_id=' . $order_id); exit;
    }

    if ($action === 'save_and_deliver') {
        $text = trim($_POST['report_text'] ?? '');
        if ($text === '') {
            flash('Cannot deliver an empty report.', 'rose');
            header('Location: /admin/expert_report_edit.php?order_id=' . $order_id); exit;
        }
        db()->prepare("
            UPDATE expert_report_orders
               SET report_text = ?, status = 'delivered',
                   report_delivered_at = CURRENT_TIMESTAMP,
                   delivered_at        = CURRENT_TIMESTAMP,
                   admin_notes = COALESCE(admin_notes,'') || ?
             WHERE id = ?
        ")->execute([
            $text,
            "\n[" . date('Y-m-d H:i') . "] Delivered by " . admin_user(),
            $order_id,
        ]);
        flash('Report delivered to parent. They can now view it on /report.php.', 'emerald');
        header('Location: /admin/expert_orders.php'); exit;
    }
}

// Reload latest values after possible AI generation
$st->execute([$order_id]);
$order = $st->fetch();

admin_layout_open('Expert report — ' . $order['child_name']);
admin_render_flash();
?>

<a href="/admin/expert_orders.php" class="text-sm text-indigo-600 hover:underline">&larr; All expert orders</a>

<div class="grid lg:grid-cols-2 gap-5 mt-4">

  <!-- LEFT: Child summary + raw assessment data -->
  <div class="space-y-4">
    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h1 class="text-xl font-extrabold mb-1"><?= e($order['child_name']) ?>
        <span class="text-sm font-normal text-slate-500">· <?= number_format($age, 1) ?> yrs · <?= e($order['gender']) ?> · <?= e($band) ?></span>
      </h1>
      <p class="text-sm text-slate-600">
        Parent: <strong><?= e($order['parent_name']) ?></strong>
        · <a href="https://wa.me/<?= e(preg_replace('/\D/','',$order['whatsapp'])) ?>" target="_blank" class="text-emerald-700">📱 <?= e($order['whatsapp']) ?></a>
        <?= $order['email'] ? ' · ' . e($order['email']) : '' ?>
      </p>
      <?php if ($order['diagnosis']): ?>
        <p class="text-xs text-rose-700 mt-1">⚠️ Known diagnosis: <?= e($order['diagnosis']) ?></p>
      <?php endif; ?>
      <p class="text-xs text-slate-500 mt-2">
        Order #<?= (int)$order['id'] ?>
        · Source: <strong><?= $order['source'] === 'paid' ? '💳 Paid' : '🎁 Referral' ?></strong>
        · Status: <strong class="<?= $order['status'] === 'delivered' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= e($order['status']) ?></strong>
        · Ordered <?= e(date('d M Y H:i', strtotime($order['ordered_at']))) ?>
      </p>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl p-5">
      <h2 class="font-semibold mb-3">📊 Assessment data (<?= count($by_module) ?> modules)</h2>
      <?php if (!$by_module): ?>
        <p class="text-sm text-slate-500">No assessments completed yet.</p>
      <?php else: ?>
        <div class="space-y-3">
        <?php foreach ($by_module as $mod => $r):
            $flags = json_decode($r['flags'] ?? '[]', true) ?: [];
        ?>
          <details class="border border-slate-100 rounded-lg p-3 <?= $flags ? 'bg-amber-50' : 'bg-slate-50' ?>">
            <summary class="cursor-pointer text-sm font-medium flex items-center justify-between">
              <span>
                <?= e($module_titles[$mod] ?? $mod) ?>
                <?php if ($r['score'] !== null): ?>
                  <span class="text-xs text-slate-500 ml-2">score <?= round((float)$r['score'], 1) ?></span>
                <?php endif; ?>
              </span>
              <?php if ($flags): ?>
                <span class="text-xs bg-amber-200 text-amber-900 px-2 py-0.5 rounded-full"><?= count($flags) ?> flag(s)</span>
              <?php endif; ?>
            </summary>
            <div class="mt-3 space-y-2 text-xs">
              <?php if ($r['ai_summary']): ?>
                <div>
                  <p class="font-semibold text-slate-700">Summary:</p>
                  <p class="whitespace-pre-line text-slate-600 leading-relaxed"><?= e($r['ai_summary']) ?></p>
                </div>
              <?php endif; ?>
              <?php if ($flags): ?>
                <div>
                  <p class="font-semibold text-amber-800">Flags:</p>
                  <ul class="list-disc pl-4 text-amber-900">
                    <?php foreach ($flags as $f): ?>
                      <li><?= e($f['q'] ?? json_encode($f)) ?><?= !empty($f['critical']) ? ' <strong>(CRITICAL)</strong>' : '' ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <p class="text-[10px] text-slate-400">Completed: <?= e(substr($r['completed_at'], 0, 16)) ?></p>
            </div>
          </details>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Report editor -->
  <div class="space-y-4">
    <div class="bg-white border-2 border-indigo-200 rounded-2xl p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold">📝 Detailed Expert Report</h2>
        <?php if ($order['status'] === 'delivered'): ?>
          <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-1 rounded-full">✓ Delivered</span>
        <?php else: ?>
          <span class="text-xs bg-amber-100 text-amber-800 px-2 py-1 rounded-full">Pending</span>
        <?php endif; ?>
      </div>

      <?php if (empty($order['report_text'])): ?>
        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-3 text-sm">
          <p class="font-medium text-indigo-900">No report drafted yet.</p>
          <p class="text-xs text-indigo-800 mt-1">Click below to generate an AI-powered draft from the assessment data, then edit before delivering.</p>
        </div>
      <?php endif; ?>

      <form method="post" class="mb-3">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="ai_generate_draft">
        <button class="w-full bg-gradient-to-r from-indigo-500 to-purple-500 text-white font-semibold py-2.5 rounded-lg hover:opacity-90"
                onclick="return confirm('<?= empty($order['report_text']) ? 'Generate an AI draft from the assessment data?' : 'This will OVERWRITE the current draft with a fresh AI generation. Continue?' ?>');">
          ✨ <?= empty($order['report_text']) ? 'Generate AI draft' : 'Re-generate (overwrites current)' ?>
        </button>
      </form>

      <form method="post" class="space-y-2">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <textarea name="report_text" rows="22"
                  class="w-full border border-slate-300 rounded-lg p-3 font-mono text-sm leading-relaxed focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                  placeholder="Write the detailed report here, or click '✨ Generate AI draft' above for a starting point. The parent will see this exact text on their report page."><?= e($order['report_text'] ?? '') ?></textarea>
        <p class="text-xs text-slate-500">Plain text. Section headings in CAPS render as bold on the parent's view. Line breaks are preserved.</p>

        <div class="flex flex-wrap gap-2">
          <button type="submit" name="action" value="save_draft"
                  class="bg-slate-200 text-slate-800 px-4 py-2 rounded-lg text-sm hover:bg-slate-300">
            💾 Save draft (don't deliver yet)
          </button>
          <button type="submit" name="action" value="save_and_deliver"
                  class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-emerald-700"
                  onclick="return confirm('Deliver this report to the parent? They will be able to view it immediately.');">
            ✅ Save &amp; deliver to parent
          </button>
        </div>
      </form>

      <?php if ($order['status'] === 'delivered'): ?>
        <p class="text-xs text-emerald-700 mt-3">
          ✓ Delivered <?= e(date('d M Y H:i', strtotime($order['report_delivered_at'] ?? $order['delivered_at']))) ?>.
          Parent view: <code><a class="underline" href="/report.php?id=<?= (int)$order['child_id'] ?>" target="_blank">/report.php?id=<?= (int)$order['child_id'] ?></a></code>.
        </p>
      <?php endif; ?>

      <p class="text-xs text-slate-400 mt-3">
        <strong>After delivering, send the parent a WhatsApp:</strong>
        <a href="https://wa.me/<?= e(preg_replace('/\D/','',$order['whatsapp'])) ?>?text=<?= rawurlencode("Hello " . ($order['parent_name'] ?: 'there') . ", " . $order['child_name'] . "'s detailed expert report is ready. You can view it at https://empowerstudents.in/report.php?id=" . (int)$order['child_id'] . " — happy to discuss on a call.") ?>"
           target="_blank" class="text-emerald-700 underline">📱 WhatsApp the parent</a>.
      </p>
    </div>
  </div>
</div>

<?php admin_layout_close(); ?>
