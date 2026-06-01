<?php
/**
 * isaa-report.php
 *
 * Public ISAA report viewer — accessible via share link + PIN.
 * No login required. Used by parents to share the report with family.
 *
 * URL: /isaa-report.php?t={share_token}
 * After correct PIN entered, the report displays in the same page.
 *
 * Security: rate-limited to 5 PIN attempts per token per hour (IP+token combo).
 * Token alone (without PIN) reveals NOTHING about the child or report.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/catalogue.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/isaa_helpers.php';
require_once __DIR__ . '/includes/markdown.php';

// Rate-limit table (idempotent)
db()->exec("CREATE TABLE IF NOT EXISTS isaa_share_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT NOT NULL,
    ip          TEXT,
    success     INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT DEFAULT CURRENT_TIMESTAMP
)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_isaa_share_attempts ON isaa_share_attempts(token, created_at)");

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || strlen($token) > 32) {
    http_response_code(404);
    exit('Invalid link.');
}

// Look up assessment by token (but DON'T reveal anything until PIN passes)
$st = db()->prepare("SELECT * FROM isaa_assessments WHERE share_token = ? AND status = 'submitted'");
$st->execute([$token]);
$assess = $st->fetch();
if (!$assess) {
    http_response_code(404);
    require __DIR__ . '/includes/header.php';
    echo '<main class="max-w-md mx-auto px-4 py-10"><div class="bg-rose-50 border border-rose-200 rounded-2xl p-6 text-rose-900 text-center">';
    echo '<h1 class="text-xl font-bold mb-2">Link not found</h1>';
    echo '<p class="text-sm">This share link is invalid or has expired. Please ask the parent to send a fresh link.</p>';
    echo '</div></main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Lang toggle
$lang = ($_GET['lang'] ?? '') === 'hi' ? 'hi' : 'en';
$has_hi = !empty($assess['summary_md_hi']) && !empty($assess['advice_md_hi']);
if ($lang === 'hi' && !$has_hi) $lang = 'en';

// Have they already entered the right PIN this session?
$session_key = 'isaa_share_ok_' . $token;
$pin_ok = !empty($_SESSION[$session_key]);

// Handle PIN submission
$pin_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $pin_attempt = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: max 5 attempts per token per hour
    $st = db()->prepare("SELECT COUNT(*) FROM isaa_share_attempts
                         WHERE token = ? AND ip = ? AND success = 0
                           AND created_at > datetime('now', '-1 hour')");
    $st->execute([$token, $ip]);
    $recent_fails = (int)$st->fetchColumn();

    if ($recent_fails >= 5) {
        $pin_error = $lang === 'hi'
            ? 'बहुत सारे गलत प्रयास। कृपया 1 घंटे बाद पुनः प्रयास करें।'
            : 'Too many incorrect attempts. Please try again in an hour.';
    } elseif ($pin_attempt === (string)$assess['share_pin']) {
        // Correct PIN
        $_SESSION[$session_key] = true;
        $pin_ok = true;
        db()->prepare("INSERT INTO isaa_share_attempts (token, ip, success) VALUES (?, ?, 1)")
           ->execute([$token, $ip]);
    } else {
        $pin_error = $lang === 'hi'
            ? 'गलत PIN। कृपया अपने भेजने वाले से PIN दोबारा माँगें।'
            : 'Incorrect PIN. Please ask the sender for the correct 4-digit PIN.';
        db()->prepare("INSERT INTO isaa_share_attempts (token, ip, success) VALUES (?, ?, 0)")
           ->execute([$token, $ip]);
    }
}

if (!$pin_ok) {
    // ─────────────── PIN GATE PAGE ───────────────
    $page_title = $lang === 'hi' ? 'सुरक्षा कोड दर्ज करें' : 'Enter Security PIN';
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="max-w-md mx-auto px-4 py-10">
      <div class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 text-center">
        <div class="text-5xl mb-3">🔒</div>
        <h1 class="text-xl font-bold text-slate-900 mb-1">
          <?= $lang === 'hi' ? 'ISAA रिपोर्ट देखने के लिए कोड दर्ज करें' : 'Enter PIN to view ISAA report' ?>
        </h1>
        <p class="text-sm text-slate-600 mb-5">
          <?= $lang === 'hi'
              ? 'जिसने आपको यह लिंक भेजा है उनसे 4-अंकीय सुरक्षा कोड माँगें।'
              : 'Ask the person who sent you this link for the 4-digit security PIN.' ?>
        </p>

        <?php if ($pin_error): ?>
          <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4">
            <?= e($pin_error) ?>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                 required autocomplete="off" autofocus
                 placeholder="••••"
                 class="w-full border border-slate-300 rounded-xl px-4 py-3 text-3xl font-bold text-center tracking-[0.5em] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">

          <button class="brand-grad text-white font-semibold px-6 py-3 rounded-xl hover:opacity-90 w-full">
            <?= $lang === 'hi' ? 'रिपोर्ट खोलें →' : 'Open report →' ?>
          </button>
        </form>

        <div class="mt-5 pt-4 border-t border-slate-100 text-xs text-slate-500">
          <a href="?t=<?= urlencode($token) ?>&lang=<?= $lang === 'hi' ? 'en' : 'hi' ?>" class="hover:text-indigo-600">
            <?= $lang === 'hi' ? 'View in English' : 'हिंदी में देखें' ?>
          </a>
        </div>
      </div>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ─────────────── PIN OK — RENDER REPORT ───────────────
// Pull child + responses (same as partner-isaa-view but no partner info)
$st = db()->prepare("SELECT c.name AS child_name, c.dob AS child_dob, c.gender AS child_gender,
                            p.name AS parent_name,
                            pn.name AS partner_name, pn.qualification AS partner_qual,
                            pn.institution AS partner_inst
                     FROM isaa_assessments a
                     JOIN children c ON c.id = a.child_id
                     LEFT JOIN parents p ON p.id = a.parent_id
                     LEFT JOIN partners pn ON pn.id = a.partner_id
                     WHERE a.id = ?");
$st->execute([(int)$assess['id']]);
$meta = $st->fetch() ?: [];
$assess = array_merge($assess, $meta);

$resp_rows = db()->prepare("SELECT r.item_no, r.score, r.notes, q.item_label, q.domain_no, q.domain_label
                            FROM isaa_responses r
                            JOIN isaa_questions q ON q.item_no = r.item_no
                            WHERE r.assessment_id = ?
                            ORDER BY r.item_no");
$resp_rows->execute([(int)$assess['id']]);
$responses = $resp_rows->fetchAll();

$domain_scores = json_decode((string)$assess['domain_scores_json'], true) ?: [];
$age = round((float)calc_age_years($assess['child_dob']), 1);
$total = (int)$assess['total_score'];
$category = (string)$assess['category'];
$disability = (int)$assess['disability_pct'];

$summary_md = $lang === 'hi' ? (string)$assess['summary_md_hi'] : (string)$assess['summary_md'];
$advice_md  = $lang === 'hi' ? (string)$assess['advice_md_hi']  : (string)$assess['advice_md'];

$cat_label = isaa_category_label($category);
$cat_label_hi = ['normal'=>'सामान्य सीमा में', 'mild'=>'हल्का ऑटिज़्म', 'moderate'=>'मध्यम ऑटिज़्म', 'severe'=>'गंभीर ऑटिज़्म'][$category] ?? $cat_label;
$cat_display = $lang === 'hi' ? $cat_label_hi : $cat_label;

$cat_card = [
    'normal'   => ['from'=>'from-emerald-50',  'to'=>'to-emerald-100',  'border'=>'border-emerald-200', 'text'=>'text-emerald-900', 'accent'=>'text-emerald-700'],
    'mild'     => ['from'=>'from-amber-50',    'to'=>'to-amber-100',    'border'=>'border-amber-200',   'text'=>'text-amber-900',   'accent'=>'text-amber-700'],
    'moderate' => ['from'=>'from-orange-50',   'to'=>'to-orange-100',   'border'=>'border-orange-200',  'text'=>'text-orange-900',  'accent'=>'text-orange-700'],
    'severe'   => ['from'=>'from-rose-50',     'to'=>'to-rose-100',     'border'=>'border-rose-300',    'text'=>'text-rose-900',    'accent'=>'text-rose-700'],
][$category] ?? ['from'=>'from-slate-50','to'=>'to-slate-100','border'=>'border-slate-200','text'=>'text-slate-900','accent'=>'text-slate-700'];

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
    'print' => 'प्रिंट / PDF',
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
    'print' => 'Print / PDF',
    'disclaimer' => 'Important: ISAA is a standardised screening tool developed by NIMH (Govt. of India). It supplements but does not replace a formal medical or developmental diagnosis. For any concerning result, please consult a paediatric neurologist or developmental specialist.',
];

$page_title = $T['report_title'] . ' — ' . $assess['child_name'];
require __DIR__ . '/includes/header.php';
if (function_exists('md_css')) echo md_css();
?>

<main class="max-w-3xl mx-auto px-4 py-6">

  <div class="flex items-baseline justify-between gap-3 mb-4 flex-wrap no-print">
    <h1 class="text-xl md:text-2xl font-bold text-slate-900"><?= e($T['report_title']) ?></h1>
    <div class="flex flex-wrap gap-2 text-sm">
      <?php if ($has_hi): ?>
        <div class="bg-slate-100 rounded-full flex p-0.5 text-xs">
          <a href="?t=<?= urlencode($token) ?>&lang=en"
             class="px-3 py-1 rounded-full <?= $lang === 'en' ? 'bg-white shadow text-indigo-700 font-semibold' : 'text-slate-500 hover:text-slate-700' ?>">EN</a>
          <a href="?t=<?= urlencode($token) ?>&lang=hi"
             class="px-3 py-1 rounded-full <?= $lang === 'hi' ? 'bg-white shadow text-indigo-700 font-semibold' : 'text-slate-500 hover:text-slate-700' ?>">हिं</a>
        </div>
      <?php endif; ?>
      <button onclick="window.print()" class="bg-slate-100 text-slate-700 hover:bg-slate-200 px-3 py-1 rounded-lg text-xs font-semibold">
        🖨 <?= e($T['print']) ?>
      </button>
    </div>
  </div>

  <!-- HEADER -->
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

  <!-- HERO SCORE -->
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

  <!-- RESPONSES (collapsed) -->
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
              <h4 class="text-xs uppercase tracking-wider font-bold text-indigo-700 mt-3 mb-1">D<?= $cur_d ?> · <?= e($dlabel) ?></h4>
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

  <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-900 print-clean">
    ⚠️ <?= e($T['disclaimer']) ?>
  </div>
</main>

<style>
  @media print {
    .no-print, .no-print * { display: none !important; }
    details { display: block !important; }
    details > * { display: block !important; }
    summary { display: none !important; }
    body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    main { max-width: 100% !important; padding: 0 !important; }
    .print-clean { box-shadow: none !important; page-break-inside: avoid; }
  }
  .lang-hi { font-family: 'Inter', 'Noto Sans Devanagari', system-ui, sans-serif; line-height: 1.7; }
  .md-content h2 { font-size: 1rem; font-weight: 700; color: #312e81; margin-top: 1.25rem; margin-bottom: 0.5rem; }
  .md-content h3 { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-top: 1rem; margin-bottom: 0.35rem; }
  .md-content p  { margin-bottom: 0.75rem; }
  .md-content ul, .md-content ol { padding-left: 1.5rem; margin-bottom: 0.75rem; }
  .md-content ul li, .md-content ol li { margin-bottom: 0.4rem; }
  .md-content strong { color: #0f172a; }
</style>

<script>
let _ttsActive = null;

function ttsToggle(section, btn) {
  if (!('speechSynthesis' in window)) {
    alert("<?= $lang === 'hi' ? 'इस ब्राउज़र में आवाज़ सुविधा उपलब्ध नहीं है।' : 'Listen feature not available in this browser.' ?>");
    return;
  }
  if (_ttsActive && _ttsActive.section === section) {
    window.speechSynthesis.cancel();
    _ttsResetButton(_ttsActive.btn);
    _ttsActive = null;
    return;
  }
  if (_ttsActive) {
    window.speechSynthesis.cancel();
    _ttsResetButton(_ttsActive.btn);
  }

  const el = document.getElementById('ttsContent_' + section);
  if (!el) return;
  const text = el.innerText || el.textContent;
  if (!text.trim()) return;

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

window.addEventListener('beforeunload', function() {
  if ('speechSynthesis' in window) window.speechSynthesis.cancel();
});

if ('speechSynthesis' in window && window.speechSynthesis.getVoices().length === 0) {
  window.speechSynthesis.onvoiceschanged = function() {};
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
