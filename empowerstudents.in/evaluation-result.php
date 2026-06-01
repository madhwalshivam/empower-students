<?php
/**
 * evaluation-result.php?session_id=N
 *
 * v3: shows the STRUCTURED LISTING report immediately after evaluation.
 *
 * Replaces the v2 narrative+chart screen. The listing names problem areas
 * with index/severity/urgency, shows the 7-day course plan tailored to
 * this parent, and the PDF status.
 *
 * If v3_listing_json is not yet populated, shows a 'preparing...' state
 * with auto-refresh polling.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
if (function_exists('db_init')) db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/parent_reflect_schema.php';
require_once __DIR__ . '/includes/parent_eval_v3.php';
require_once __DIR__ . '/includes/parent_reflect_home_climate.php';

require_parent();
$parent = current_parent();
$parent_id = (int)$parent['id'];

$session_id = (int)($_GET['session_id'] ?? 0);
$st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ? AND parent_id = ? AND status = 'completed'");
$st->execute([$session_id, $parent_id]);
$session = $st->fetch();

if (!$session) {
    $page_title = 'Result not found';
    require __DIR__ . '/includes/header.php';
    echo '<main class="max-w-3xl mx-auto px-4 py-12"><div class="bg-white border border-slate-200 rounded-2xl p-8 text-center"><h1>Result not found</h1><p>The evaluation you are looking for does not belong to your account or is not yet complete.</p><a href="/dashboard.php" class="text-emerald-600 underline">Go to dashboard</a></div></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Make sure v3 listing exists
if (empty($session['v3_listing_json'])) {
    pr_v3_generate_listing($session_id);
    $st->execute([$session_id, $parent_id]);
    $session = $st->fetch();
}

$listing_json = (string)($session['v3_listing_json'] ?? '');
$listing_ready = $listing_json !== '';

// Leda voice summary (from comprehensive_report.php's secondary track)
$summary_hi = (string)($session['summary_text_hi'] ?? '');
$summary_en = (string)($session['summary_text_en'] ?? '');
$audio_hi   = (string)($session['summary_audio_hi'] ?? '');
$audio_en   = (string)($session['summary_audio_en'] ?? '');
$pdf_path   = (string)($session['report_pdf_path'] ?? '');
$pdf_ready  = $pdf_path !== '';
$summary_ready = ($summary_hi !== '' && $summary_en !== '' && $audio_hi !== '' && $audio_en !== '');

$data = $listing_ready ? json_decode($listing_json, true) : null;
$language = $data['language'] ?? ($session['parent_summary_md'] ? 'hi' : 'hi');
$is_hindi = $language === 'hi';

$page_title = 'Your evaluation — EmpowerStudents';
require __DIR__ . '/includes/header.php';
?>

<main class="max-w-3xl mx-auto px-4 py-6">

<!-- Hero -->
<div class="bg-gradient-to-br from-emerald-600 to-teal-600 text-white rounded-2xl p-6 mb-4 shadow-lg">
  <div class="text-xs uppercase tracking-wider opacity-80 mb-2">Parent Evaluation · Complete</div>
  <h1 class="text-2xl font-bold mb-1">🌿 <?= $is_hindi ? 'जो आज हमने सुना' : 'What we heard today' ?></h1>
  <p class="text-sm opacity-90">
    <?= $is_hindi
      ? "नीचे आपके जीवन के 9 क्षेत्रों का एक एक listing है। हर क्षेत्र पर index, severity और urgency के साथ — कौनसा पहले attention माँगता है।"
      : "Below is a structured listing across 9 areas of your life. Each with an index, severity, and urgency — naming what needs attention first." ?>
  </p>
</div>

<!-- Structured Listing -->
<div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
  <h2 class="text-lg font-bold text-slate-900 mb-1">📋 <?= $is_hindi ? '9 क्षेत्रों का listing' : 'Your 9-area listing' ?></h2>
  <p class="text-xs text-slate-500 mb-4">
    <?= $is_hindi
      ? 'समस्या को नाम देना — समाधान की शुरुआत है।'
      : 'Naming the problem is the start of the solution.' ?>
  </p>

  <?php if ($listing_ready): ?>
    <?= pr_v3_render_listing_html($listing_json) ?>
  <?php else: ?>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 text-center" id="listingPending">
      <div class="animate-spin inline-block w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full mb-2"></div>
      <p class="text-sm text-slate-700">
        <?= $is_hindi
          ? 'आपका listing report तैयार हो रहा है — यह screen 30 second में refresh होगा।'
          : 'Your structured listing is being prepared — this screen will auto-refresh in 30 seconds.' ?>
      </p>
    </div>
  <?php endif; ?>
</div>

<!-- Leda Voice general advice (one paragraph, hindi+english) -->
<?php if ($summary_ready): ?>
<div class="bg-white border-2 border-emerald-200 rounded-2xl p-5 mb-4">
  <h2 class="text-lg font-bold text-slate-900 mb-3">🎙 <?= $is_hindi ? 'सामान्य परामर्श — Leda की आवाज़ में' : 'General guidance — in Leda voice' ?></h2>
  <div class="space-y-4">
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
      <div class="flex items-center justify-between gap-2 mb-2">
        <span class="text-xs uppercase tracking-wider font-bold text-amber-700">हिंदी · Leda</span>
        <?php if ($audio_hi): ?>
          <audio controls preload="none" src="<?= htmlspecialchars($audio_hi) ?>" class="h-8"></audio>
        <?php endif; ?>
      </div>
      <p class="text-slate-800 leading-relaxed" style="font-family:'Noto Sans Devanagari', sans-serif"><?= htmlspecialchars($summary_hi) ?></p>
    </div>
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4">
      <div class="flex items-center justify-between gap-2 mb-2">
        <span class="text-xs uppercase tracking-wider font-bold text-indigo-700">English · Leda</span>
        <?php if ($audio_en): ?>
          <audio controls preload="none" src="<?= htmlspecialchars($audio_en) ?>" class="h-8"></audio>
        <?php endif; ?>
      </div>
      <p class="text-slate-800 leading-relaxed"><?= htmlspecialchars($summary_en) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- PDF status -->
<div class="bg-slate-50 border border-slate-200 rounded-2xl p-5 mb-4">
  <h2 class="text-base font-bold text-slate-900 mb-2">📄 <?= $is_hindi ? 'Detailed PDF Report' : 'Detailed PDF Report' ?></h2>
  <?php if ($pdf_ready): ?>
    <p class="text-sm text-emerald-700 mb-3">✓ <?= $is_hindi ? 'आपकी detailed PDF report तैयार है।' : 'Your detailed PDF report is ready.' ?></p>
    <a href="<?= htmlspecialchars($pdf_path) ?>" target="_blank" class="inline-block px-5 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg">
      📥 <?= $is_hindi ? 'PDF download करें' : 'Download PDF' ?>
    </a>
  <?php else: ?>
    <p class="text-sm text-slate-700 mb-2">
      <?= $is_hindi
        ? 'Comprehensive PDF लगभग 1 घंटे में आपके dashboard पर आ जाएगा — full listing + narrative + per-area expansion।'
        : 'Comprehensive PDF will appear on your dashboard in about an hour — full listing + narrative + per-area expansion.' ?>
    </p>
    <p class="text-xs text-slate-500">⏳ <?= $is_hindi ? 'WhatsApp पर भी share करेंगे।' : 'Will also be shared on WhatsApp.' ?></p>
  <?php endif; ?>
</div>

<!-- 7-Day Course CTA -->
<div class="bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-2xl p-5 mb-4">
  <h2 class="text-lg font-bold text-orange-900 mb-2">🌱 <?= $is_hindi ? 'अगला क़दम — 7-Day Home Course' : 'Next — 7-Day Home Course' ?></h2>
  <p class="text-sm text-orange-800 mb-3">
    <?= $is_hindi
      ? "ऊपर के listing में जिन क्षेत्रों पर ध्यान चाहिए — 7-day course उन्हीं को address करता है। रोज़ AI voice interview + एक छोटा सा practice + meditation, affirmation और motivation — सब Leda की आवाज़ में।"
      : "The 7-day course addresses the areas flagged in your listing above. Daily AI voice interview + one small practice + meditation, affirmation, motivation — all in Leda voice." ?>
  </p>
  <div class="flex items-baseline gap-3 mb-3">
    <span class="text-3xl font-bold text-orange-900">₹4,000</span>
    <span class="text-xs text-orange-700"><?= $is_hindi ? '· एक ही payment' : '· one payment' ?></span>
  </div>
  <button type="button" id="startCourseBtn" data-sid="<?= $session_id ?>" disabled
          class="w-full sm:w-auto px-6 py-3 bg-slate-400 text-white font-bold rounded-lg cursor-not-allowed">
    <?= $is_hindi ? '7-Day Course (जल्द आएगा)' : '7-Day Course (Coming soon)' ?>
  </button>
  <p class="text-xs text-orange-700 mt-2">
    🔒 <?= $is_hindi ? 'Course engine v2 जल्द launch होगा। तब तक listing + PDF ही milegi।' : 'Course engine v2 launching soon. Until then, listing + PDF only.' ?>
  </p>
</div>

</main>

<script>
const LISTING_READY = <?= $listing_ready ? 'true' : 'false' ?>;
const SUMMARY_READY = <?= $summary_ready ? 'true' : 'false' ?>;
if (!LISTING_READY || !SUMMARY_READY) {
  setTimeout(() => { window.location.reload(); }, 30000);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
