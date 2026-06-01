<?php
/**
 * partner-isaa-view.php
 *
 * View a submitted ISAA assessment report. Accessible by:
 *   - the partner who conducted it
 *   - the parent of the child
 *   - admin (any report, for QA)
 *
 * URL: ?id=<assessment_id>
 *
 * Features in this view:
 *   - Language toggle EN / हिं (Hindi) — both versions stored in DB
 *   - 🔊 Listen buttons (browser-native Web Speech API)
 *   - Print → save as PDF (browser native)
 *   - Share link: shows the parent the public URL + 4-digit PIN they can WhatsApp to family
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/partner_auth.php';
require_once __DIR__ . '/includes/isaa_helpers.php';
require_once __DIR__ . '/includes/markdown.php';

$aid = (int)($_GET['id'] ?? 0);
if ($aid <= 0) { http_response_code(404); exit('Not found.'); }

// ────────────────────────────────────────────────────────────
// REGENERATE handler — admin or partner only
// (Re-runs both English and Hindi AI generation with the latest prompts.
//  Useful when you want to refresh an old report with the new framing.)
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'regenerate'
    && csrf_check($_POST['csrf'] ?? '')) {

    // Permission: admin OR the partner who conducted it
    $cur_partner_h = current_partner();
    $is_admin_h    = !empty($_SESSION['admin_id']);

    // Pull the assessment + child for context
    $st = db()->prepare("SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                                c.mother_tongue AS child_mother_tongue
                         FROM isaa_assessments a
                         JOIN children c ON c.id = a.child_id
                         WHERE a.id = ?");
    $st->execute([$aid]);
    $assess_h = $st->fetch();

    $is_partner_h = $cur_partner_h && (int)$cur_partner_h['id'] === (int)($assess_h['partner_id'] ?? 0);

    if (!$assess_h) {
        http_response_code(404); exit('Not found.');
    }
    if (!$is_admin_h && !$is_partner_h) {
        http_response_code(403); exit('Not authorised.');
    }
    if ($assess_h['status'] !== 'submitted') {
        $_SESSION['flash_error'] = 'Can only regenerate submitted reports.';
        header('Location: /partner-isaa-view.php?id=' . $aid);
        exit;
    }

    // Pull responses and recompute (in case scoring was ever off)
    $resp_st = db()->prepare("SELECT item_no, score FROM isaa_responses WHERE assessment_id = ?");
    $resp_st->execute([$aid]);
    $resp = [];
    foreach ($resp_st->fetchAll() as $r) $resp[(int)$r['item_no']] = (int)$r['score'];

    if (count($resp) < 40) {
        $_SESSION['flash_error'] = 'Cannot regenerate: only ' . count($resp) . ' of 40 items answered.';
        header('Location: /partner-isaa-view.php?id=' . $aid);
        exit;
    }

    $scores_h = isaa_compute_scores($resp);
    $total_h  = (int)$scores_h['total'];
    $cat_h    = isaa_classify($total_h);
    $pct_h    = isaa_disability_pct($total_h);
    $hc_h     = isaa_high_concern_items($resp);

    // Generate fresh English + Hindi reports with the latest prompts
    $child_h = [
        'name'          => $assess_h['child_name'],
        'dob'           => $assess_h['child_dob'],
        'gender'        => $assess_h['child_gender'],
        'mother_tongue' => $assess_h['child_mother_tongue'],
    ];
    $report_en = isaa_generate_report($child_h, $total_h, $cat_h, $pct_h, $scores_h['domains'], $hc_h);
    $report_hi = isaa_generate_report_hindi($child_h, $total_h, $cat_h, $pct_h, $scores_h['domains'], $hc_h);

    // Generate share token + PIN if missing (so legacy reports get a share link too)
    $share_token = $assess_h['share_token'] ?: null;
    $share_pin   = $assess_h['share_pin']   ?: null;
    if (!$share_token) {
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
    }

    // Persist
    db()->prepare("UPDATE isaa_assessments
                   SET total_score = ?, category = ?, disability_pct = ?,
                       domain_scores_json = ?,
                       summary_md = COALESCE(?, summary_md),
                       advice_md  = COALESCE(?, advice_md),
                       summary_md_hi = COALESCE(?, summary_md_hi),
                       advice_md_hi  = COALESCE(?, advice_md_hi),
                       share_token = COALESCE(share_token, ?),
                       share_pin   = COALESCE(share_pin, ?)
                   WHERE id = ?")
       ->execute([
           $total_h, $cat_h, $pct_h,
           json_encode($scores_h['domains'], JSON_UNESCAPED_UNICODE),
           $report_en['summary_md'], $report_en['advice_md'],
           $report_hi['summary_md_hi'], $report_hi['advice_md_hi'],
           $share_token, $share_pin,
           $aid,
       ]);

    $_SESSION['flash_ok'] = 'Report regenerated with the latest framing (English + Hindi).';
    header('Location: /partner-isaa-view.php?id=' . $aid);
    exit;
}

$st = db()->prepare("SELECT a.*, c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                            c.parent_id AS c_parent_id,
                            p.name AS parent_name, p.whatsapp AS parent_whatsapp,
                            pn.name AS partner_name, pn.qualification AS partner_qual,
                            pn.institution AS partner_inst
                     FROM isaa_assessments a
                     JOIN children c ON c.id = a.child_id
                     LEFT JOIN parents p ON p.id = a.parent_id
                     LEFT JOIN partners pn ON pn.id = a.partner_id
                     WHERE a.id = ?");
$st->execute([$aid]);
$assess = $st->fetch();
if (!$assess) { http_response_code(404); exit('Assessment not found.'); }
if ($assess['status'] !== 'submitted') { http_response_code(404); exit('Assessment not yet completed.'); }

// Permission check
$cur_partner = current_partner();
$cur_parent  = function_exists('current_parent') ? current_parent() : null;
$is_admin    = !empty($_SESSION['admin_id']);

$is_partner = $cur_partner && (int)$cur_partner['id'] === (int)$assess['partner_id'];
$is_parent  = $cur_parent  && (int)$cur_parent['id']  === (int)$assess['parent_id'];

if (!$is_partner && !$is_parent && !$is_admin) {
    http_response_code(403);
    require __DIR__ . '/includes/header.php';
    echo '<main class="max-w-2xl mx-auto px-4 py-10"><div class="bg-rose-50 border border-rose-200 rounded-2xl p-6 text-rose-900">';
    echo '<h1 class="text-xl font-bold mb-2">Not authorised</h1>';
    echo '<p>Please sign in as the partner who conducted this assessment, or as the parent of the child.</p>';
    echo '<p class="mt-3 text-sm"><a href="/login.php" class="text-indigo-600 hover:underline">Parent sign-in</a> · ';
    echo '<a href="/partner-login.php" class="text-indigo-600 hover:underline">Partner sign-in</a></p>';
    echo '</div></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Pull responses
$resp_rows = db()->prepare("SELECT r.item_no, r.score, r.notes, q.item_label, q.domain_no, q.domain_label
                            FROM isaa_responses r
                            JOIN isaa_questions q ON q.item_no = r.item_no
                            WHERE r.assessment_id = ?
                            ORDER BY r.item_no");
$resp_rows->execute([$aid]);
$responses = $resp_rows->fetchAll();

$domain_scores = json_decode((string)$assess['domain_scores_json'], true) ?: [];

$age = round((float)calc_age_years($assess['child_dob']), 1);
$total = (int)$assess['total_score'];
$category = (string)$assess['category'];
$disability = (int)$assess['disability_pct'];

// Language toggle
$lang = ($_GET['lang'] ?? '') === 'hi' ? 'hi' : 'en';
$has_hi = !empty($assess['summary_md_hi']) && !empty($assess['advice_md_hi']);
if ($lang === 'hi' && !$has_hi) $lang = 'en';

$summary_md = $lang === 'hi' ? (string)$assess['summary_md_hi'] : (string)$assess['summary_md'];
$advice_md  = $lang === 'hi' ? (string)$assess['advice_md_hi']  : (string)$assess['advice_md'];

// Localised category labels
$cat_label = isaa_category_label($category);
$cat_label_hi = ['normal'=>'सामान्य सीमा में', 'mild'=>'हल्का ऑटिज़्म', 'moderate'=>'मध्यम ऑटिज़्म', 'severe'=>'गंभीर ऑटिज़्म'][$category] ?? $cat_label;
$cat_display = $lang === 'hi' ? $cat_label_hi : $cat_label;

// Card colour by category
$cat_card = [
    'normal'   => ['from'=>'from-emerald-50',  'to'=>'to-emerald-100',  'border'=>'border-emerald-200', 'text'=>'text-emerald-900', 'accent'=>'text-emerald-700'],
    'mild'     => ['from'=>'from-amber-50',    'to'=>'to-amber-100',    'border'=>'border-amber-200',   'text'=>'text-amber-900',   'accent'=>'text-amber-700'],
    'moderate' => ['from'=>'from-orange-50',   'to'=>'to-orange-100',   'border'=>'border-orange-200',  'text'=>'text-orange-900',  'accent'=>'text-orange-700'],
    'severe'   => ['from'=>'from-rose-50',     'to'=>'to-rose-100',     'border'=>'border-rose-300',    'text'=>'text-rose-900',    'accent'=>'text-rose-700'],
][$category] ?? ['from'=>'from-slate-50','to'=>'to-slate-100','border'=>'border-slate-200','text'=>'text-slate-900','accent'=>'text-slate-700'];

// Localised UI strings
$T = $lang === 'hi' ? [
    'report_title' => 'ISAA मूल्यांकन रिपोर्ट',
    'child' => 'बच्चा', 'years' => 'वर्ष', 'parent' => 'माता-पिता',
    'conducted_by' => 'मूल्यांकन कर्ता', 'submitted' => 'जमा किया',
    'total_score' => 'कुल ISAA स्कोर', 'out_of' => 'में से 200',
    'disability' => 'विकलांगता प्रतिशत (ISAA स्कोरिंग अनुसार)',
    'domain_breakdown' => 'डोमेन विश्लेषण',
    'summary_for_parents' => 'माता-पिता के लिए सारांश',
    'what_to_do_at_home' => 'घर पर क्या करें',
    'all_responses' => 'सभी 40 प्रतिक्रियाएँ देखें',
    'listen' => 'सुनें', 'stop' => 'रोकें',
    'print' => 'प्रिंट / PDF', 'share' => 'शेयर लिंक',
    'share_modal_title' => 'इस रिपोर्ट को शेयर करें',
    'share_url' => 'शेयर लिंक',
    'share_pin' => 'सुरक्षा कोड (PIN)',
    'share_helper' => 'यह लिंक और कोड पारिवारिक सदस्यों को WhatsApp पर भेजें। दूसरों को रिपोर्ट देखने के लिए दोनों चाहिए।',
    'share_wa' => 'WhatsApp पर भेजें',
    'copy' => 'कॉपी',
    'copied' => 'कॉपी हो गया',
    'disclaimer' => 'महत्वपूर्ण: ISAA NIMH (भारत सरकार) द्वारा निर्मित एक मानकीकृत स्क्रीनिंग टूल है। यह औपचारिक चिकित्सा निदान का विकल्प नहीं है। किसी भी चिंताजनक परिणाम के लिए कृपया बाल न्यूरोलॉजिस्ट या विकास विशेषज्ञ से परामर्श करें।',
] : [
    'report_title' => 'ISAA Assessment Report',
    'child' => 'Child', 'years' => 'years', 'parent' => 'Parent',
    'conducted_by' => 'Conducted by', 'submitted' => 'Submitted',
    'total_score' => 'Total ISAA score', 'out_of' => 'out of 200',
    'disability' => 'Disability percentage (per ISAA scoring)',
    'domain_breakdown' => 'Domain breakdown',
    'summary_for_parents' => 'Summary for parents',
    'what_to_do_at_home' => 'What you can do at home',
    'all_responses' => 'View all 40 item responses',
    'listen' => 'Listen', 'stop' => 'Stop',
    'print' => 'Print / PDF', 'share' => 'Share link',
    'share_modal_title' => 'Share this report',
    'share_url' => 'Share URL',
    'share_pin' => 'Security PIN',
    'share_helper' => 'WhatsApp this link AND the PIN to family members. They need both to view the report.',
    'share_wa' => 'Send via WhatsApp',
    'copy' => 'Copy',
    'copied' => 'Copied',
    'disclaimer' => 'Important: ISAA is a standardised screening tool developed by NIMH (Govt. of India). It supplements but does not replace a formal medical or developmental diagnosis. For any concerning result, please consult a paediatric neurologist or developmental specialist.',
];

$share_url_full = '';
if (!empty($assess['share_token'])) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $share_url_full = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'empowerstudents.in')
                    . '/isaa-report.php?t=' . urlencode((string)$assess['share_token']);
}

$page_title = $T['report_title'] . ' — ' . $assess['child_name'];
require __DIR__ . '/includes/header.php';
if (function_exists('md_css')) echo md_css();
?>

<main class="max-w-3xl mx-auto px-4 py-6" id="reportRoot">

  <!-- TOP BAR: title + actions -->
  <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap no-print">
    <h1 class="text-xl md:text-2xl font-bold text-slate-900"><?= e($T['report_title']) ?></h1>
    <div class="flex flex-wrap gap-2 text-sm">
      <?php if ($is_partner): ?>
        <a href="/partner-isaa-queue.php" class="text-slate-500 hover:text-indigo-600 hover:underline px-2 py-1">← Queue</a>
      <?php elseif ($is_admin): ?>
        <a href="/admin/isaa-test.php" class="text-slate-500 hover:text-indigo-600 hover:underline px-2 py-1">← Admin test</a>
      <?php elseif ($is_parent): ?>
        <a href="/dashboard.php" class="text-slate-500 hover:text-indigo-600 hover:underline px-2 py-1">← Dashboard</a>
      <?php endif; ?>

      <!-- Language toggle -->
      <?php if ($has_hi): ?>
        <div class="bg-slate-100 rounded-full flex p-0.5 text-xs">
          <a href="?id=<?= $aid ?>&lang=en"
             class="px-3 py-1 rounded-full <?= $lang === 'en' ? 'bg-white shadow text-indigo-700 font-semibold' : 'text-slate-500 hover:text-slate-700' ?>">EN</a>
          <a href="?id=<?= $aid ?>&lang=hi"
             class="px-3 py-1 rounded-full <?= $lang === 'hi' ? 'bg-white shadow text-indigo-700 font-semibold' : 'text-slate-500 hover:text-slate-700' ?>">हिं</a>
        </div>
      <?php endif; ?>

      <?php if (!empty($assess['share_token'])): ?>
        <button type="button" onclick="document.getElementById('shareModal').classList.remove('hidden')"
                class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1 rounded-lg text-xs font-semibold">
          🔗 <?= e($T['share']) ?>
        </button>
      <?php endif; ?>

      <button onclick="window.print()" class="bg-slate-100 text-slate-700 hover:bg-slate-200 px-3 py-1 rounded-lg text-xs font-semibold">
        🖨 <?= e($T['print']) ?>
      </button>

      <?php if ($is_partner || $is_admin): ?>
        <form method="post" class="m-0 inline" onsubmit="return regenerateConfirm(this)">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="regenerate">
          <button type="submit"
                  class="bg-amber-50 text-amber-800 hover:bg-amber-100 px-3 py-1 rounded-lg text-xs font-semibold border border-amber-200">
            ↻ <?= $lang === 'hi' ? 'दोबारा जनरेट करें' : 'Regenerate (EN+HI)' ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-3 text-sm mb-4 no-print">
      ✓ <?= e($_SESSION['flash_ok']) ?>
    </div>
    <?php unset($_SESSION['flash_ok']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4 no-print">
      <?= e($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- HEADER: child + clinician info -->
  <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4 print-clean">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-500 mb-1"><?= e($T['child']) ?></p>
        <p class="font-bold text-slate-900 text-lg leading-tight"><?= e($assess['child_name']) ?></p>
        <p class="text-xs text-slate-500 mt-0.5"><?= $age ?> <?= e($T['years']) ?> · <?= e($assess['child_gender'] ?: '—') ?></p>
        <p class="text-xs text-slate-500"><?= e($T['parent']) ?>: <?= e($assess['parent_name'] ?? '—') ?></p>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-500 mb-1"><?= e($T['conducted_by']) ?></p>
        <p class="font-semibold text-slate-900"><?= e($assess['partner_name'] ?? ($lang === 'hi' ? 'क्लिनिशियन' : 'Clinician')) ?></p>
        <p class="text-xs text-slate-500"><?= e($assess['partner_qual'] ?? '') ?>
          <?= $assess['partner_inst'] ? ' · ' . e($assess['partner_inst']) : '' ?></p>
        <p class="text-xs text-slate-500"><?= e($T['submitted']) ?>: <?= e(substr((string)$assess['submitted_at'], 0, 16)) ?></p>
      </div>
    </div>
  </div>

  <!-- HERO SCORE PANEL -->
  <div class="bg-gradient-to-br <?= $cat_card['from'] ?> <?= $cat_card['to'] ?> border-2 <?= $cat_card['border'] ?> rounded-2xl p-6 mb-4 print-clean">
    <div class="flex items-baseline justify-between gap-4 flex-wrap mb-3">
      <div>
        <p class="text-xs uppercase tracking-wider <?= $cat_card['accent'] ?> font-semibold mb-1"><?= e($T['total_score']) ?></p>
        <div class="flex items-baseline gap-2">
          <span class="text-5xl md:text-6xl font-black <?= $cat_card['text'] ?> leading-none"><?= $total ?></span>
          <span class="text-base <?= $cat_card['accent'] ?>"><?= e($T['out_of']) ?></span>
        </div>
        <p class="text-xl font-bold <?= $cat_card['text'] ?> mt-2"><?= e($cat_display) ?></p>
      </div>
      <div class="text-right">
        <p class="text-xs uppercase tracking-wider <?= $cat_card['accent'] ?> font-semibold mb-1"><?= e($T['disability']) ?></p>
        <p class="text-4xl font-bold <?= $cat_card['text'] ?>"><?= $disability ?>%</p>
      </div>
    </div>

    <!-- Domain breakdown -->
    <h3 class="text-xs uppercase tracking-wider <?= $cat_card['accent'] ?> font-semibold mt-5 mb-3"><?= e($T['domain_breakdown']) ?></h3>
    <div class="space-y-2.5">
      <?php
      $domain_label_hi = [
          1 => 'सामाजिक संबंध और परस्परता',
          2 => 'भावनात्मक प्रतिक्रिया',
          3 => 'भाषा और संचार',
          4 => 'व्यवहार पैटर्न',
          5 => 'संवेदी पहलू',
          6 => 'संज्ञानात्मक घटक',
      ];
      foreach ($domain_scores as $dno => $d):
          $dlabel = $lang === 'hi' ? ($domain_label_hi[(int)$dno] ?? $d['label']) : $d['label'];
      ?>
        <div>
          <div class="flex items-baseline justify-between gap-2 text-xs mb-1">
            <span class="<?= $cat_card['text'] ?> font-medium"><strong>D<?= (int)$dno ?>.</strong> <?= e($dlabel) ?></span>
            <span class="<?= $cat_card['accent'] ?> font-semibold"><?= (int)$d['raw'] ?>/<?= (int)$d['max'] ?> · <?= (int)$d['pct'] ?>%</span>
          </div>
          <div class="h-2.5 bg-white/60 rounded-full overflow-hidden border border-white/80">
            <?php $bar_color = (int)$d['pct'] >= 60 ? 'bg-rose-500' : ((int)$d['pct'] >= 40 ? 'bg-amber-500' : 'bg-emerald-500'); ?>
            <div class="h-full <?= $bar_color ?> transition-all" style="width: <?= max(2, min(100, (int)$d['pct'])) ?>%;"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- AI SUMMARY -->
  <?php if (!empty($summary_md)): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4 print-clean">
      <div class="flex items-baseline justify-between gap-2 mb-3 flex-wrap">
        <h2 class="text-base font-bold text-slate-900">📋 <?= e($T['summary_for_parents']) ?></h2>
        <button type="button" onclick="ttsToggle('summary', this)"
                class="no-print text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1 rounded-lg font-semibold">
          🔊 <span class="tts-label"><?= e($T['listen']) ?></span>
        </button>
      </div>
      <div id="ttsContent_summary" class="md-content text-sm text-slate-700 <?= $lang === 'hi' ? 'lang-hi' : '' ?>" lang="<?= $lang ?>">
        <?= md_render($summary_md) ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- AI ADVICE -->
  <?php if (!empty($advice_md)): ?>
    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4 print-clean">
      <div class="flex items-baseline justify-between gap-2 mb-3 flex-wrap">
        <h2 class="text-base font-bold text-slate-900">💡 <?= e($T['what_to_do_at_home']) ?></h2>
        <button type="button" onclick="ttsToggle('advice', this)"
                class="no-print text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1 rounded-lg font-semibold">
          🔊 <span class="tts-label"><?= e($T['listen']) ?></span>
        </button>
      </div>
      <div id="ttsContent_advice" class="md-content text-sm text-slate-700 <?= $lang === 'hi' ? 'lang-hi' : '' ?>" lang="<?= $lang ?>">
        <?= md_render($advice_md) ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ALL RESPONSES (collapsible) -->
  <details class="bg-white border border-slate-200 rounded-2xl p-4 mb-4 no-print-collapsed">
    <summary class="cursor-pointer text-sm font-semibold text-slate-700 hover:text-indigo-600">
      📄 <?= e($T['all_responses']) ?>
    </summary>
    <div class="mt-3 space-y-1">
      <?php
      $cur_d = 0;
      foreach ($responses as $r):
          if ((int)$r['domain_no'] !== $cur_d):
              $cur_d = (int)$r['domain_no'];
              $dlabel = $lang === 'hi' ? ($domain_label_hi[$cur_d] ?? $r['domain_label']) : $r['domain_label'];
      ?>
              <h4 class="text-xs uppercase tracking-wider font-bold text-indigo-700 mt-3 mb-1">
                D<?= $cur_d ?> · <?= e($dlabel) ?>
              </h4>
      <?php endif;
          $score = (int)$r['score'];
          $score_color = $score >= 4 ? 'bg-rose-100 text-rose-800' : ($score === 3 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700');
      ?>
        <div class="flex items-baseline gap-2 text-xs py-1 border-b border-slate-100 last:border-0">
          <span class="text-slate-400 w-7">#<?= (int)$r['item_no'] ?></span>
          <span class="flex-1 text-slate-700"><?= e($r['item_label']) ?></span>
          <span class="px-2 py-0.5 rounded font-semibold <?= $score_color ?>"><?= $score ?>/5</span>
        </div>
        <?php if (!empty($r['notes'])): ?>
          <p class="text-xs text-slate-500 italic ml-9 -mt-1 mb-1">↳ <?= e($r['notes']) ?></p>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </details>

  <!-- DISCLAIMER -->
  <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-900 print-clean">
    ⚠️ <?= e($T['disclaimer']) ?>
  </div>

</main>

<!-- ────────────────────── SHARE MODAL ────────────────────── -->
<?php if (!empty($assess['share_token'])): ?>
  <?php
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $share_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'empowerstudents.in')
               . '/isaa-report.php?t=' . urlencode((string)$assess['share_token']);
    $wa_msg = $lang === 'hi'
        ? "नमस्ते 🙏\n\n{$assess['child_name']} की ISAA रिपोर्ट यहाँ देखें:\n{$share_url}\n\nसुरक्षा कोड (PIN): {$assess['share_pin']}\n\n— EmpowerStudents"
        : "Hi 🙏\n\nView {$assess['child_name']}'s ISAA assessment report:\n{$share_url}\n\nSecurity PIN: {$assess['share_pin']}\n\n— EmpowerStudents";
    $wa_url = 'https://wa.me/?text=' . rawurlencode($wa_msg);
  ?>
  <div id="shareModal" class="hidden fixed inset-0 z-[200] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center px-4 no-print"
       onclick="if(event.target===this) this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-md w-full">
      <div class="flex items-baseline justify-between mb-4">
        <h3 class="text-lg font-bold text-slate-900">🔗 <?= e($T['share_modal_title']) ?></h3>
        <button onclick="document.getElementById('shareModal').classList.add('hidden')"
                class="text-slate-400 hover:text-slate-600">✕</button>
      </div>

      <div class="space-y-4">
        <div>
          <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider"><?= e($T['share_url']) ?></label>
          <div class="flex gap-2 mt-1">
            <input type="text" readonly value="<?= e($share_url) ?>" id="shareUrlInput"
                   class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-xs font-mono bg-slate-50 text-slate-700">
            <button onclick="copyToClip('shareUrlInput', this)"
                    class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 px-3 py-2 rounded-lg text-xs font-semibold whitespace-nowrap">
              📋 <span class="copy-label"><?= e($T['copy']) ?></span>
            </button>
          </div>
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider"><?= e($T['share_pin']) ?></label>
          <div class="flex gap-2 mt-1">
            <input type="text" readonly value="<?= e((string)$assess['share_pin']) ?>" id="sharePinInput"
                   class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-2xl font-bold tracking-[0.5em] bg-amber-50 text-amber-900 text-center">
            <button onclick="copyToClip('sharePinInput', this)"
                    class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 px-3 py-2 rounded-lg text-xs font-semibold whitespace-nowrap">
              📋 <span class="copy-label"><?= e($T['copy']) ?></span>
            </button>
          </div>
        </div>

        <p class="text-xs text-slate-600 leading-relaxed bg-slate-50 border border-slate-200 rounded-lg p-3">
          ℹ️ <?= e($T['share_helper']) ?>
        </p>

        <a href="<?= e($wa_url) ?>" target="_blank"
           class="block text-center bg-emerald-600 text-white font-semibold px-4 py-3 rounded-xl hover:bg-emerald-700">
          💬 <?= e($T['share_wa']) ?>
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<style>
  @media print {
    .no-print, .no-print * { display: none !important; }
    /* Force-expand all <details> for print */
    details { display: block !important; }
    details > * { display: block !important; }
    summary { display: none !important; }
    body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    main { max-width: 100% !important; padding: 0 !important; }
    .print-clean { box-shadow: none !important; page-break-inside: avoid; }
  }
  /* Better Hindi rendering — use Devanagari-friendly font */
  .lang-hi { font-family: 'Inter', 'Noto Sans Devanagari', system-ui, sans-serif; line-height: 1.7; }

  /* Markdown content styling */
  .md-content h2 { font-size: 1rem; font-weight: 700; color: #312e81; margin-top: 1.25rem; margin-bottom: 0.5rem; }
  .md-content h3 { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 1rem; margin-bottom: 0.35rem; }
  .md-content p  { margin-bottom: 0.75rem; }
  .md-content ul, .md-content ol { padding-left: 1.5rem; margin-bottom: 0.75rem; }
  .md-content ul li, .md-content ol li { margin-bottom: 0.4rem; }
  .md-content strong { color: #0f172a; }
</style>

<script>
// ────────────────────────────────────────────────────
// Regenerate report — confirmation + loading overlay
// ────────────────────────────────────────────────────
function regenerateConfirm(form) {
  var msg = "<?= $lang === 'hi'
              ? 'नई रिपोर्ट तैयार की जाएगी (अंग्रेज़ी और हिंदी दोनों)। पुरानी रिपोर्ट हट जाएगी। यह 30-40 सेकंड लेगा। जारी रखें?'
              : 'A fresh report will be generated in BOTH English and Hindi using the latest framing. The current report content will be replaced. Takes ~30-40 seconds. Continue?' ?>";
  if (!confirm(msg)) return false;

  // Disable button + show overlay
  var btn = form.querySelector('button[type=submit]');
  if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }

  var ov = document.createElement('div');
  ov.id = 'regenOverlay';
  ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.7);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:1rem;';
  ov.innerHTML = '<div style="background:white;border-radius:1rem;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);padding:2rem;max-width:24rem;text-align:center;">'
    + '<div style="margin:0 auto 1rem;width:64px;height:64px;border-radius:50%;border:4px solid #e0e7ff;border-top-color:#4f46e5;animation:spin 1s linear infinite"></div>'
    + '<h3 style="font-size:1.125rem;font-weight:700;color:#0f172a;margin-bottom:0.5rem">'
    + "<?= $lang === 'hi' ? 'रिपोर्ट तैयार हो रही है…' : 'Generating fresh report…' ?>"
    + '</h3>'
    + '<p style="font-size:0.875rem;color:#64748b">'
    + "<?= $lang === 'hi' ? 'अंग्रेज़ी और हिंदी दोनों जनरेट हो रहे हैं। 30-40 सेकंड लगेंगे।' : 'Generating English + Hindi versions. This takes 30-40 seconds.' ?>"
    + '</p></div>'
    + '<style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
  document.body.appendChild(ov);
  return true;
}

// ────────────────────────────────────────────────────
// Browser-native TTS (Web Speech API)
// ────────────────────────────────────────────────────
let _ttsActive = null; // {section, btn}

function ttsToggle(section, btn) {
  if (!('speechSynthesis' in window)) {
    alert("<?= $lang === 'hi' ? 'इस ब्राउज़र में आवाज़ सुविधा उपलब्ध नहीं है।' : 'Listen feature not available in this browser.' ?>");
    return;
  }

  // If clicking the active button, stop
  if (_ttsActive && _ttsActive.section === section) {
    window.speechSynthesis.cancel();
    _ttsResetButton(_ttsActive.btn);
    _ttsActive = null;
    return;
  }

  // Stop any ongoing speech
  if (_ttsActive) {
    window.speechSynthesis.cancel();
    _ttsResetButton(_ttsActive.btn);
  }

  const el = document.getElementById('ttsContent_' + section);
  if (!el) return;
  const text = el.innerText || el.textContent;
  if (!text.trim()) return;

  // Split into chunks at sentence boundaries — speechSynthesis fails on very long strings
  const chunks = _ttsChunk(text, 200);
  const lang = "<?= $lang === 'hi' ? 'hi-IN' : 'en-IN' ?>";

  let i = 0;
  function speakNext() {
    if (i >= chunks.length || _ttsActive === null) {
      _ttsResetButton(btn);
      _ttsActive = null;
      return;
    }
    const u = new SpeechSynthesisUtterance(chunks[i]);
    u.lang = lang;
    u.rate = 0.95;
    u.pitch = 1.0;
    // Try to pick a Hindi voice if available
    const voices = window.speechSynthesis.getVoices();
    const hiVoice = voices.find(v => v.lang === 'hi-IN' || v.lang.startsWith('hi'));
    const enVoice = voices.find(v => v.lang === 'en-IN' || v.lang.startsWith('en'));
    if (lang === 'hi-IN' && hiVoice) u.voice = hiVoice;
    else if (lang === 'en-IN' && enVoice) u.voice = enVoice;
    u.onend = function() { i++; speakNext(); };
    u.onerror = function() { _ttsResetButton(btn); _ttsActive = null; };
    window.speechSynthesis.speak(u);
  }

  _ttsActive = { section: section, btn: btn };
  const lbl = btn.querySelector('.tts-label');
  if (lbl) lbl.textContent = "<?= e($T['stop']) ?>";
  btn.classList.add('bg-rose-100', 'text-rose-700');
  btn.classList.remove('bg-indigo-50', 'text-indigo-700');

  speakNext();
}

function _ttsChunk(text, maxLen) {
  // Split into sentence-ish chunks for smoother TTS
  const sentences = text.replace(/\n+/g, '. ').split(/(?<=[.!?।])\s+/);
  const chunks = [];
  let cur = '';
  for (const s of sentences) {
    if ((cur + ' ' + s).length > maxLen && cur.length > 0) {
      chunks.push(cur.trim());
      cur = s;
    } else {
      cur = (cur + ' ' + s).trim();
    }
  }
  if (cur.trim()) chunks.push(cur.trim());
  return chunks.length ? chunks : [text];
}

function _ttsResetButton(btn) {
  if (!btn) return;
  const lbl = btn.querySelector('.tts-label');
  if (lbl) lbl.textContent = "<?= e($T['listen']) ?>";
  btn.classList.remove('bg-rose-100', 'text-rose-700');
  btn.classList.add('bg-indigo-50', 'text-indigo-700');
}

// Stop TTS when leaving page
window.addEventListener('beforeunload', function() {
  if ('speechSynthesis' in window) window.speechSynthesis.cancel();
});

// Voices may load asynchronously
if ('speechSynthesis' in window && window.speechSynthesis.getVoices().length === 0) {
  window.speechSynthesis.onvoiceschanged = function() { /* triggers a refresh internally */ };
}

// ────────────────────────────────────────────────────
// Copy-to-clipboard helper
// ────────────────────────────────────────────────────
function copyToClip(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  inp.select();
  inp.setSelectionRange(0, 99999);
  try {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(inp.value);
    } else {
      document.execCommand('copy');
    }
    const lbl = btn.querySelector('.copy-label');
    const original = lbl ? lbl.textContent : '';
    if (lbl) lbl.textContent = "<?= e($T['copied']) ?>";
    btn.classList.add('bg-emerald-100', 'text-emerald-700');
    btn.classList.remove('bg-indigo-100', 'text-indigo-700');
    setTimeout(function() {
      if (lbl) lbl.textContent = original;
      btn.classList.remove('bg-emerald-100', 'text-emerald-700');
      btn.classList.add('bg-indigo-100', 'text-indigo-700');
    }, 2000);
  } catch (e) { /* ignore */ }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
