<?php
/**
 * parent-reflect.php — Phase 2 (voice flow)
 *
 * Voice-driven adaptive reflection for parents of special-needs children.
 * ₹1000 — charged only when the final report is ready. Includes a psychologist follow-up call.
 *
 * Flow:
 *   Landing → Consent + child picker → Mic permission check → Voice interview → Closing
 *
 * Voice architecture mirrors the proven eval-speech.php r7:
 *   - TTS speaks AI question
 *   - Web Speech Recognition captures parent's spoken answer
 *   - Transcript-based pause detection (3.5s of no new transcript = done)
 *   - DOM-salvage in onStopRecording
 *   - 60s hard cap per turn, 30s client abort timeout, build stamp in console
 *
 * Differences from eval-speech.php:
 *   - No expected/right-or-wrong scoring
 *   - Status messages are warm/empathic
 *   - AI's reflection (1-2 sentences mirroring the parent) is shown + spoken before next question
 *   - Phase indicator instead of "Q5 of 14"
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
db_init();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/parent_reflect_schema.php';
require_once __DIR__ . '/includes/parent_reflect_engine.php';

require_parent();
$parent  = current_parent();
$parent_id = (int)$parent['id'];

/**
 * Lightweight markdown → HTML renderer for parent_summary_md.
 * Handles: ## headings, **bold**, *italic*, paragraphs, lists.
 * Intentionally minimal — Sonnet output is constrained to a known structure.
 */
if (!function_exists('prose_render_md')) {
    function prose_render_md(string $md): string {
        $md = trim($md);
        if ($md === '') return '';
        // Escape HTML first
        $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
        // Headings: ## Title  →  <h3>Title</h3>
        $md = preg_replace('/^##\s*(.+?)\s*$/m', '<h3 class="text-base font-bold text-indigo-900 mt-5 mb-2">$1</h3>', $md);
        $md = preg_replace('/^#\s*(.+?)\s*$/m',  '<h2 class="text-lg font-bold text-indigo-900 mt-5 mb-2">$1</h2>',  $md);
        // Bold
        $md = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $md);
        // Italic (single * around words)
        $md = preg_replace('/(?<![\*\w])\*([^\*\n]+?)\*(?![\*\w])/s', '<em>$1</em>', $md);
        // Bullet lists: lines starting with "- "
        $md = preg_replace_callback('/(^- .+(\n- .+)*)/m', function($m) {
            $items = array_map(function($l){
                return '<li>' . preg_replace('/^- /', '', $l) . '</li>';
            }, explode("\n", $m[0]));
            return '<ul class="list-disc list-inside space-y-1 my-2">' . implode('', $items) . '</ul>';
        }, $md);
        // Wrap remaining double-newline-separated chunks as <p>
        $blocks = preg_split('/\n\s*\n/', $md);
        $out = '';
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') continue;
            // Already a block-level tag? leave alone
            if (preg_match('/^\s*<(h[1-6]|ul|ol|p|div|blockquote)/i', $b)) {
                $out .= $b . "\n";
            } else {
                // Convert internal single newlines to <br> for soft wraps
                $b = str_replace("\n", '<br>', $b);
                $out .= '<p class="mb-3">' . $b . '</p>' . "\n";
            }
        }
        return $out;
    }
}

$cs = db()->prepare("SELECT * FROM children WHERE parent_id = ? ORDER BY created_at DESC");
$cs->execute([$parent_id]);
$kids = $cs->fetchAll();

$bal = wallet_balance($parent_id);
$recent_complete = pr_recent_complete_for($parent_id, 7);
$in_progress = pr_in_progress_for($parent_id);

// "fresh=1" — parent explicitly wants to start a new reflection (override
// the auto-routing to recent/in-progress). Only honored for clean state.
$force_fresh = isset($_GET['fresh']) && $_GET['fresh'] === '1';

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Parent Reflection — EmpowerStudents';
require __DIR__ . '/includes/header.php';
?>

<style>
  .pr-card { background: #fff; border-radius: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
  .pr-mic-icon { font-size: 4rem; line-height: 1; transition: transform .3s ease; }
  .pr-mic-icon.pulsing { animation: prPulse 1.2s ease-in-out infinite; }
  @keyframes prPulse { 0%,100%{transform:scale(1);opacity:1;} 50%{transform:scale(1.1);opacity:.85;} }
  .pr-mic-bars { display: inline-flex; gap: 3px; align-items: flex-end; height: 24px; vertical-align: middle; }
  .pr-mic-bars > div { width: 4px; background: linear-gradient(180deg,#a855f7,#6366f1); border-radius: 2px; transition: height .08s; height: 10%; }
  .pr-fade-in { animation: prFade .4s ease-out; }
  @keyframes prFade { from { opacity: 0; transform: translateY(6px);} to { opacity:1; transform: translateY(0);} }
</style>

<main class="max-w-2xl mx-auto px-4 py-6" id="prRoot">

  <!-- ────────── Browser unsupported ────────── -->
  <div id="screenUnsupported" class="hidden bg-amber-50 border-2 border-amber-300 rounded-2xl p-6 text-center">
    <div class="text-5xl mb-3">🎙️</div>
    <h1 class="text-xl font-bold text-amber-900 mb-2">Voice reflection needs a different browser</h1>
    <p class="text-sm text-amber-800 mb-4">
      This is a private voice conversation — your browser must support live speech recognition.
    </p>
    <div class="bg-white border border-amber-200 rounded-lg p-4 text-left text-sm text-slate-700 space-y-2 mb-4">
      <p><strong>Please open this page in:</strong></p>
      <ul class="list-disc list-inside space-y-1 text-xs">
        <li><strong>Google Chrome</strong> (Android, Windows, Mac) — best</li>
        <li><strong>Microsoft Edge</strong></li>
        <li><strong>Brave</strong></li>
      </ul>
    </div>
    <a href="/dashboard.php" class="text-indigo-600 hover:underline text-sm">← Back to dashboard</a>
  </div>

  <!-- ────────── Landing ────────── -->
  <div id="screenLanding" class="hidden">
    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 text-black rounded-2xl p-6 md:p-8 mb-5 shadow-lg">
      <h1 class="text-2xl md:text-3xl text-black font-bold mb-2">A private space for you</h1>
      <p class="text-indigo-100 text-sm md:text-base leading-relaxed">
        Parenting a child with developmental needs is hard — and most of the weight you carry, no one sees.
        This is a 15-minute private reflection that helps you put words to what you're carrying,
        and sends you a warm reflection plus a follow-up call from our psychologist.
      </p>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl p-5 md:p-6 mb-5">
      <h2 class="text-lg font-bold text-slate-900 mb-4">What's included for ₹1000</h2>
      <div class="space-y-3 text-sm text-slate-700">
        <div class="flex gap-3">
          <span class="text-2xl shrink-0">💬</span>
          <div>
            <strong class="text-slate-900">A 10-15 minute private conversation.</strong>
            Tap options or type freely — your pace. We'll go gently through home, family, and your own state.
          </div>
        </div>
        <div class="flex gap-3">
          <span class="text-2xl shrink-0">📋</span>
          <div>
            <strong class="text-slate-900">A warm written reflection.</strong>
            Not a diagnosis. A thoughtful summary of what came up, plus one small thing
            you might try this week.
          </div>
        </div>
        <div class="flex gap-3">
          <span class="text-2xl shrink-0">📞</span>
          <div>
            <strong class="text-slate-900">A personal call from our psychologist within 48 hours.</strong>
            One of our therapists reviews your reflection and calls you for a 15-minute conversation.
          </div>
        </div>
      </div>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-5 text-sm">
      <h3 class="font-semibold text-amber-900 mb-2">A few honest things to know</h3>
      <ul class="list-disc list-inside space-y-1 text-amber-900">
        <li>This is <strong>not therapy</strong> and not a clinical diagnosis. It's reflection.</li>
        <li>Our AI listens, but <strong>it is not a licensed therapist</strong>.</li>
        <li>If you're in crisis, please reach out:
          <a href="tel:9152987821" class="underline font-semibold">iCall 9152987821</a> ·
          <a href="tel:18602662345" class="underline font-semibold">Vandrevala 1860-2662-345</a> ·
          <a href="tel:18005990019" class="underline font-semibold">KIRAN 1800-599-0019</a>.
        </li>
        <li>Your conversation is private. Only you and our therapy team can see it.</li>
      </ul>
    </div>

    <div class="text-center">
      <button id="goConsentBtn" type="button"
              class="bg-gradient-to-r from-indigo-600 to-purple-700 text-white font-bold px-8 py-3.5 rounded-xl text-base shadow-lg hover:opacity-95 inline-flex items-center gap-2">
        I'm ready — let's begin →
      </button>
      <p class="text-xs text-slate-500 mt-3">You'll choose your child and confirm consent before we begin.</p>
    </div>
  </div>

  <!-- ────────── Consent ────────── -->
  <div id="screenConsent" class="hidden bg-white border border-slate-200 rounded-2xl p-6 md:p-8">
    <h1 class="text-xl md:text-2xl font-bold text-slate-900 mb-4">Before we begin</h1>
    <div id="flashConsent" class="hidden bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-3 text-sm mb-4"></div>

    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-5 text-sm">
      <div class="flex items-baseline justify-between gap-3">
        <p class="font-semibold text-indigo-900">₹1000 — charged only when your report is ready</p>
        <p class="text-xs text-indigo-700">Wallet: <strong>₹<?= (int)$bal ?></strong>
          <?php if ((int)$bal < 1000): ?>
            · <a href="/wallet.php?need=1000" class="underline">Top up</a>
          <?php endif; ?>
        </p>
      </div>
      <p class="text-xs text-indigo-800 mt-1">Includes the AI reflection + a call from our psychologist within 48 hours.</p>
    </div>

    <?php
        // Compute language preview from parent's children
        $hi_n = 0; $en_n = 0;
        foreach ($kids as $k) {
            $mt = strtolower(trim((string)($k['mother_tongue'] ?: 'English')));
            if (strpos($mt, 'hindi') !== false || $mt === 'hi') $hi_n++; else $en_n++;
        }
        $detected_lang_label = ($hi_n >= $en_n && $hi_n > 0) ? 'Hindi (हिंदी)' : 'English';
    ?>

    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-5 text-sm">
      <p class="text-purple-900 mb-2">
        <strong>This reflection is about you</strong> — your home, your relationships, your own state.
        It is not centred on any one specific child.
      </p>
      <?php if (count($kids) > 1): ?>
        <p class="text-xs text-purple-800 mb-2">
          You have <?= count($kids) ?> children on file. We'll have the AI keep all of them in mind as gentle context, without singling any one out.
        </p>
      <?php elseif (count($kids) === 1): ?>
        <p class="text-xs text-purple-800 mb-2">
          We'll have the AI keep <?= e($kids[0]['name']) ?> in mind as gentle context.
        </p>
      <?php endif; ?>
      <p class="text-xs text-purple-700">
        <strong>Language:</strong> the AI will speak with you in <strong><?= $detected_lang_label ?></strong>.
      </p>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-5">
      <h3 class="text-sm font-semibold text-slate-900 mb-3">Please tick these to begin</h3>
      <div class="space-y-3 text-sm text-slate-700">
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" id="c1" class="mt-1 consent-check">
          <span>I understand this is a <strong>private reflection</strong>, not therapy or medical diagnosis.</span>
        </label>
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" id="c2" class="mt-1 consent-check">
          <span>I understand the AI is <strong>not a licensed therapist</strong>. If I'm in crisis I will call iCall (9152987821) or Vandrevala (1860-2662-345).</span>
        </label>
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" id="c3" class="mt-1 consent-check">
          <span>I'm OK with the EmpowerStudents psychologist team reviewing my reflection and calling me back within 48 hours.</span>
        </label>
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" id="c4" class="mt-1 consent-check">
          <span>I will be in a quiet space where I can speak freely for ~15 minutes.</span>
        </label>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button id="backBtn" type="button" class="text-sm text-slate-500 hover:text-slate-800">← Back</button>
      <button id="beginBtn" type="button"
              class="bg-gradient-to-r from-indigo-600 to-purple-700 text-white font-bold px-6 py-3 rounded-xl hover:opacity-95 opacity-40 cursor-not-allowed">
        Begin reflection (₹1000 at finish) →
      </button>
    </div>
  </div>

  <!-- ────────── Voice interview ────────── -->

  <!-- ────────── Interview (chips + text, no voice) ────────── -->
  <div id="screenInterview" class="hidden">
    <div class="pr-card border border-indigo-100 p-4 mb-3 flex items-center justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs uppercase tracking-wider text-indigo-600 font-semibold" id="iPhaseLabel">Reflection</p>
        <p class="text-xs text-slate-500"><span id="iTurnNo">Turn 1</span> · <span id="iElapsed">0:00</span></p>
      </div>
      <div class="flex items-center gap-2">
        <button id="iFinishBtn" type="button" class="text-xs sm:text-sm bg-emerald-100 hover:bg-emerald-200 text-emerald-900 font-semibold px-3 py-1.5 rounded-lg border border-emerald-300 shadow-sm">✓ Finish now</button>
        <button id="iEndBtn" type="button" class="text-xs sm:text-sm bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold px-3 py-1.5 rounded-lg border border-amber-300 shadow-sm">⏸ Pause</button>
      </div>
    </div>

    <div class="pr-card border-2 border-indigo-200 p-6 md:p-7 mb-3">

      <!-- Reflection of what they just said (hidden until 2nd+ turn) -->
      <div id="iReflection" class="hidden mb-4 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
        <p class="text-xs uppercase tracking-wide font-semibold text-indigo-700 mb-1" id="iReflectionLabel">A moment of reflection</p>
        <p class="text-base text-slate-800" id="iReflectionText"></p>
      </div>

      <!-- The question itself -->
      <p class="text-lg md:text-xl text-slate-900 text-center font-semibold leading-relaxed mb-5" id="iQuestionText" lang="hi">—</p>

      <!-- Tappable option chips -->
      <div id="iOptions" class="flex flex-wrap gap-2 justify-center mb-5"></div>

      <!-- Text input -->
      <div id="iTextRow" class="max-w-xl mx-auto">
        <label class="block text-xs uppercase tracking-wide font-semibold text-slate-500 mb-1" id="iTextLabel">या यहाँ लिखिए</label>
        <textarea id="iTextArea" rows="3" placeholder="अपने शब्दों में बताइए…"
                  class="w-full px-3 py-2 border-2 border-slate-200 focus:border-indigo-400 focus:outline-none rounded-xl text-base resize-none"
                  lang="hi"></textarea>
        <div class="flex items-center justify-between mt-2 gap-2">
          <p id="iCharCount" class="text-xs text-slate-400">0 characters</p>
          <div class="flex items-center gap-2">
            <button id="iMicBtn" type="button"
                    class="bg-rose-100 hover:bg-rose-200 text-rose-900 font-semibold px-4 py-2 rounded-lg text-sm shadow-sm border border-rose-300">
              <span id="iMicBtnLabel">🎙️ बोलें</span>
            </button>
            <button id="iSendBtn" type="button" disabled
                    class="bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-semibold px-5 py-2 rounded-lg text-sm shadow-sm">
              <span id="iSendBtnLabel">✓ भेजें</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Pause/thinking panel (shown after submit while AI thinks) -->
      <div id="iThinking" class="hidden text-center py-8">
        <img src="/assets/images/logo-small.png" alt="EmpowerStudents"
             class="w-16 h-16 mx-auto rounded-2xl shadow-lg mb-3"
             style="animation: pr-pulse 1.4s ease-in-out infinite;">
        <p class="text-base text-slate-700 font-medium" id="iThinkingText">सोच रहा हूँ…</p>
      </div>

    </div>

    <!-- History panel (scrollable, collapsed by default) -->
    <div id="iHistoryWrap" class="hidden mt-4">
      <details class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <summary class="cursor-pointer p-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 flex items-center justify-between">
          <span>📜 <span id="iHistoryLabel">पिछली बातचीत</span> · <span id="iHistoryCount">0</span></span>
          <span class="text-xs text-slate-400">▾</span>
        </summary>
        <div id="iHistoryBox" class="max-h-96 overflow-y-auto p-3 space-y-3 border-t border-slate-100"></div>
      </details>
    </div>

    <!-- Crisis banner (only shown when AI detects safety red flag) -->
    <div id="iCrisis" class="hidden mt-4 bg-rose-50 border-2 border-rose-300 rounded-xl p-4 text-sm">
      <p class="font-bold text-rose-900 mb-2">🤲 You can call right now if it feels heavy:</p>
      <ul class="text-rose-900 font-semibold space-y-1">
        <li>• <a href="tel:9152987821" class="underline">iCall · 9152987821</a></li>
        <li>• <a href="tel:18602662345" class="underline">Vandrevala · 1860-2662-345</a></li>
      </ul>
    </div>
  </div>

  <style>
    @keyframes pr-pulse {
      0%, 100% { transform: scale(1);     opacity: 0.95; box-shadow: 0 4px 14px rgba(124,58,237,0.18); }
      50%      { transform: scale(1.08);  opacity: 1;    box-shadow: 0 10px 32px rgba(124,58,237,0.32); }
    }
    .pr-chip {
      background: white;
      border: 2px solid #c7d2fe;
      color: #1e293b;
      font-weight: 500;
      font-size: 14px;
      padding: 10px 16px;
      border-radius: 999px;
      cursor: pointer;
      transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .pr-chip:hover { border-color: #6366f1; background: #eef2ff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.18); }
    .pr-chip:active { transform: translateY(0); }
  </style>

  <div id="screenClosing" class="hidden">
    <div class="pr-card border border-indigo-200 p-6 md:p-8 mb-4">
      <div class="text-5xl text-center mb-3">🌿</div>
      <h2 class="text-xl font-bold text-slate-900 text-center mb-4">Thank you for sharing</h2>

      <div id="cReflection" class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-4 hidden">
        <p class="text-xs uppercase tracking-wide font-semibold text-indigo-700 mb-2">What I heard</p>
        <p class="text-base text-slate-800" id="cReflectionText"></p>
      </div>

      <!-- fresh-v1: full structured report rendered inline -->
      <div id="cSummaryWrap" class="hidden bg-white border border-indigo-200 rounded-xl p-5 md:p-6 mb-4 prose prose-slate max-w-none"
           style="font-size: 15px; line-height: 1.7;"></div>

      <!-- fresh-v2: comprehensive v3 report (9-area listing + scoring) appears here when ready -->
      <div id="cV3Section" class="mb-4">
        <div id="cV3Loading" class="hidden bg-indigo-50 border border-indigo-200 rounded-xl p-5 text-center">
          <div class="inline-flex items-center gap-3 text-indigo-900">
            <span class="inline-block w-5 h-5 rounded-full border-2 border-indigo-600 border-t-transparent animate-spin"></span>
            <span id="cV3LoadingText" class="font-medium">Preparing detailed report (~1 minute)…</span>
          </div>
          <p class="text-xs text-indigo-700 mt-2" id="cV3LoadingHint">Leave this page open — the comprehensive 9-area scoring will appear here automatically.</p>
        </div>
        <div id="cV3Ready" class="hidden">
          <div class="flex items-center justify-between mb-3 mt-2 flex-wrap gap-2">
            <h3 class="font-bold text-slate-900 text-lg">📊 <span id="cV3Title">Detailed Scoring &amp; 7-Day Plan</span></h3>
            <div class="flex items-center gap-2 flex-wrap">
              <button type="button" id="cTranslateBtn"
                      class="bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold px-3 py-2 rounded-lg text-sm border border-amber-300 shadow-sm">
                🌐 <span id="cTranslateLabel">View in English</span>
              </button>
              <button type="button" id="cPrintBtn"
                      class="bg-emerald-100 hover:bg-emerald-200 text-emerald-900 font-semibold px-3 py-2 rounded-lg text-sm border border-emerald-300 shadow-sm">
                📄 <span id="cPrintLabel">Save as PDF</span>
              </button>
            </div>
          </div>
          <div id="cTranslateStatus" class="hidden mb-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-amber-600 border-t-transparent animate-spin align-middle mr-2"></span>
            <span id="cTranslateStatusText">Translating… first time takes 15-20 seconds.</span>
          </div>
          <div id="cV3ListingBody" class="bg-white border border-slate-200 rounded-xl p-4 md:p-5"></div>
        </div>

        <!-- fresh-v7: 7-day course preview for closing screen -->
        <div id="cCoursePreviewSection" class="hidden mb-3">
          <div id="cCoursePreviewLoading" class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-center">
            <div class="inline-flex items-center gap-3 text-amber-900">
              <span class="inline-block w-5 h-5 rounded-full border-2 border-amber-600 border-t-transparent animate-spin"></span>
              <span class="font-medium">Generating your personalised 7-day course preview…</span>
            </div>
            <p class="text-xs text-amber-700 mt-2">~15 seconds. Tailored to your reflection.</p>
          </div>
          <div id="cCoursePreviewBody" class="hidden"></div>
        </div>
      </div>

      <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4">
        <p class="text-base text-slate-800 leading-relaxed" id="cClosingText">—</p>
      </div>

      <div id="cSafetyBlock" class="hidden bg-rose-50 border-2 border-rose-300 rounded-xl p-4 mb-4 text-sm">
        <p class="font-bold text-rose-900 mb-2">🤲 Please reach out — you're not alone</p>
        <p class="text-rose-800 mb-2">Our psychologist will call you within 24 hours. In the meantime:</p>
        <ul class="text-rose-900 font-semibold space-y-1">
          <li>• <a href="tel:9152987821" class="underline">iCall · 9152987821</a></li>
          <li>• <a href="tel:18602662345" class="underline">Vandrevala · 1860-2662-345</a></li>
        </ul>
      </div>

      <!-- fresh-v3: removed "psychologist will call" callout — comprehensive report covers it -->

      <div class="text-center">
        <a href="/dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 underline">← Back to dashboard</a>
      </div>
    </div>
  </div>

  <!-- ────────── Resume an in-progress reflection ────────── -->
  <?php if ($in_progress && empty($recent_complete)):
      $age_h = (time() - strtotime((string)($in_progress['last_activity_at'] ?? $in_progress['started_at']) . ' UTC')) / 3600;
      $age_label = $age_h < 1
          ? 'a few minutes ago'
          : ($age_h < 24
              ? round($age_h) . ' hour' . (round($age_h) === 1.0 ? '' : 's') . ' ago'
              : 'yesterday');
  ?>
    <div id="screenResume" class="hidden bg-white border-2 border-amber-200 rounded-2xl p-6 md:p-7">
      <div class="text-center mb-4">
        <div class="text-5xl mb-2">⏸️</div>
        <h1 class="text-xl md:text-2xl font-bold text-slate-900 mb-2">A reflection is paused</h1>
        <p class="text-sm text-slate-600">
          You started a reflection <?= e($age_label) ?> and didn't finish it. You can pick up where you left off.
        </p>
      </div>

      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 text-sm text-amber-900">
        <p class="font-semibold mb-1">No new charge.</p>
        <p>Continuing finishes the same conversation. You will be charged ₹1000 only when your report is ready.</p>
      </div>

      <div class="flex flex-col gap-3">
        <button id="resumeBtn" type="button"
                class="bg-gradient-to-r from-indigo-600 to-purple-700 text-white font-bold px-6 py-3 rounded-xl hover:opacity-95">
          ▶ Continue the reflection
        </button>
        <button id="abandonBtn" type="button"
                class="text-sm text-slate-500 hover:text-rose-600 underline">
          Discard this and start a new reflection instead
        </button>
      </div>
    </div>
  <?php endif; ?>

  <!-- ────────── Recent completed report ────────── -->
  <?php if ($recent_complete):
      $rc_done   = htmlspecialchars(date('F j, Y · g:i a', strtotime((string)$recent_complete['completed_at'] . ' UTC')));
      $rc_md     = (string)($recent_complete['parent_summary_md'] ?? '');
      $rc_fcnt   = (int)($recent_complete['followup_count'] ?? 0);
      $rc_fmax   = 3;
      $rc_remain = max(0, $rc_fmax - $rc_fcnt);
      $rc_risk   = (string)($recent_complete['admin_risk_level'] ?? 'green');
      $rc_safety = (int)($recent_complete['sig_safety_red_flag'] ?? 0);
  ?>
    <div id="screenRecent" class="hidden">
      <div class="pr-card border border-indigo-200 p-6 md:p-8 mb-3">
        <div class="text-4xl text-center mb-2">🌿</div>
        <h1 class="text-2xl font-bold text-slate-900 text-center mb-1">Your reflection</h1>
        <p class="text-xs text-slate-500 text-center mb-5">
          Completed <?= $rc_done ?>
        </p>

        <?php if ($rc_safety): ?>
          <div class="bg-rose-50 border-2 border-rose-300 rounded-xl p-4 mb-4 text-sm">
            <p class="font-bold text-rose-900 mb-2">🤲 Please reach out — you're not alone</p>
            <p class="text-rose-800 mb-2">Our psychologist will call you within 24 hours. In the meantime:</p>
            <ul class="text-rose-900 font-semibold space-y-1">
              <li>• <a href="tel:9152987821" class="underline">iCall · 9152987821</a></li>
              <li>• <a href="tel:18602662345" class="underline">Vandrevala · 1860-2662-345</a></li>
              <li>• <a href="tel:18005990019" class="underline">KIRAN · 1800-599-0019</a></li>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($rc_md !== ''): ?>
          <div class="prose prose-slate max-w-none text-slate-800 leading-relaxed" id="rcReportBody">
            <!-- Server renders markdown to HTML below; lightweight md→html below -->
            <?= prose_render_md($rc_md) ?>
          </div>
        <?php else: ?>
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
            Your reflection was recorded but the summary is still being generated. Please check back in a few minutes.
          </div>
        <?php endif; ?>

        <!-- fresh-v3: removed "psychologist will call" callout — comprehensive report covers it -->
      </div>

      <!-- fresh-v3: comprehensive v3 report (server-render if ready) -->
      <?php
        $rc_v3_json = (string)($recent_complete['v3_listing_json'] ?? '');
        $rc_v3_pdf  = (string)($recent_complete['report_pdf_path'] ?? '');
        $rc_v3_html = '';
        if ($rc_v3_json !== '') {
            require_once __DIR__ . '/includes/parent_eval_v3.php';
            if (function_exists('pr_v3_render_listing_html')) {
                $rc_v3_html = pr_v3_render_listing_html($rc_v3_json);
            }
        }
      ?>
      <?php if ($rc_v3_html !== ''): ?>
        <div class="pr-card border border-slate-200 p-5 md:p-6 mb-3">
          <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h3 class="font-bold text-slate-900 text-lg">📊 <span id="rcV3Title">Detailed Scoring &amp; 7-Day Plan</span></h3>
            <div class="flex items-center gap-2 flex-wrap">
              <?php
                /* Detect current language of report from listing JSON */
                $rc_v3_lang = 'hi';
                $_tmp = json_decode($rc_v3_json, true);
                if (is_array($_tmp) && !empty($_tmp['language'])) $rc_v3_lang = $_tmp['language'];
              ?>
              <button type="button" id="rcTranslateBtn"
                      data-sid="<?= (int)$recent_complete['id'] ?>"
                      data-current="<?= e($rc_v3_lang) ?>"
                      class="bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold px-3 py-2 rounded-lg text-sm border border-amber-300 shadow-sm">
                🌐 <span id="rcTranslateLabel"><?= $rc_v3_lang === 'hi' ? 'View in English' : 'हिंदी में देखें' ?></span>
              </button>
              <button type="button" id="rcPrintBtn"
                      class="bg-emerald-100 hover:bg-emerald-200 text-emerald-900 font-semibold px-3 py-2 rounded-lg text-sm border border-emerald-300 shadow-sm">
                📄 <span id="rcPrintLabel">Save as PDF</span>
              </button>
            </div>
          </div>
          <div id="rcTranslateStatus" class="hidden mb-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-amber-600 border-t-transparent animate-spin align-middle mr-2"></span>
            <span id="rcTranslateStatusText">Translating… first time takes 15-20 seconds.</span>
          </div>
          <div id="rcV3ListingBody">
            <?= $rc_v3_html ?>
          </div>
          <!-- Also store the parent_summary_md so we can swap it on translation -->
          <div id="rcSummaryBody" class="hidden">
            <?= prose_render_md((string)$rc_md) ?>
          </div>
        </div>

        <!-- fresh-v7: 7-day course preview block (lazy-loaded via JS once v3 is ready) -->
        <div id="rcCoursePreviewSection" class="mb-3"
             data-sid="<?= (int)$recent_complete['id'] ?>"
             data-lang="<?= htmlspecialchars($rc_v3_lang) ?>">
          <div id="rcCoursePreviewLoading" class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-center">
            <div class="inline-flex items-center gap-3 text-amber-900">
              <span class="inline-block w-5 h-5 rounded-full border-2 border-amber-600 border-t-transparent animate-spin"></span>
              <span class="font-medium">Generating your personalised 7-day course preview…</span>
            </div>
            <p class="text-xs text-amber-700 mt-2">~15 seconds. We tailor each day to YOUR reflection.</p>
          </div>
          <div id="rcCoursePreviewBody" class="hidden"></div>
        </div>
      <?php else: ?>
        <div id="rcV3Pending" class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 mb-3 text-center">
          <div class="inline-flex items-center gap-3 text-indigo-900">
            <span class="inline-block w-5 h-5 rounded-full border-2 border-indigo-600 border-t-transparent animate-spin"></span>
            <span class="font-medium">Detailed report being prepared (~1 minute)…</span>
          </div>
          <p class="text-xs text-indigo-700 mt-2">9-area scoring + 7-day plan will appear here. Refresh in a moment if it doesn't load automatically.</p>
        </div>
        <script>
          (function() {
            // Poll v3_status on screenRecent until ready, then refresh page
            const sid = <?= (int)$recent_complete['id'] ?>;
            let tries = 0;
            const max = 36; // 3 min
            const csrf = <?= json_encode(csrf_token()) ?>;
            const tick = setInterval(async function() {
              tries++;
              if (tries > max) { clearInterval(tick); return; }
              try {
                const fd = new FormData();
                fd.append('action', 'v3_status');
                fd.append('csrf', csrf);
                fd.append('session_id', sid);
                const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
                const data = await r.json();
                if (data && data.ready) {
                  clearInterval(tick);
                  // Reload so PHP picks up the v3_listing_json and renders inline
                  window.location.reload();
                }
              } catch (e) { /* ignore, keep polling */ }
            }, 5000);
          })();
        </script>
      <?php endif; ?>

      <!-- Follow-up box -->
      <?php if ($rc_remain > 0): ?>
        <div id="rcFollowupBox" class="bg-purple-50 border border-purple-200 rounded-2xl p-5 mb-3">
          <h3 class="font-bold text-purple-900 mb-2">Anything else you wanted to add?</h3>
          <p class="text-sm text-purple-800 mb-3">
            You have <strong><?= $rc_remain ?> more follow-up question<?= $rc_remain === 1 ? '' : 's' ?></strong> available
            on this reflection — no extra charge.
            Use it if there's something you wish you'd shared, or if you want to check in again.
          </p>
          <button id="askMoreBtn" type="button"
                  data-sid="<?= (int)$recent_complete['id'] ?>"
                  class="bg-gradient-to-r from-purple-600 to-indigo-700 text-white font-semibold px-5 py-2.5 rounded-xl hover:opacity-95">
            🎙️ Ask one more thing
          </button>
        </div>
      <?php else: ?>
        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 mb-3 text-sm text-slate-600">
          You've used all your follow-up turns on this reflection. To talk again, you can
          <a href="?fresh=1" class="text-indigo-600 hover:underline">start a new reflection</a>.
        </div>
      <?php endif; ?>

      <div class="text-center">
        <a href="/dashboard.php" class="text-sm text-slate-500 hover:text-indigo-600 underline">← Back to dashboard</a>
      </div>
    </div>
  <?php endif; ?>
</main>


<script>
'use strict';

console.log('parent-reflect.php build: 2026-05-16-fresh-v8c — purchase overlay');

// ═══════════════════════════════════════════════════════════════════
//  CONSTANTS (PHP-rendered)
// ═══════════════════════════════════════════════════════════════════
const CSRF = <?= json_encode(csrf_token()) ?>;
const PR_BAL = <?= (int)$bal ?>;
const PR_HAS_RECENT     = <?= $recent_complete ? 'true' : 'false' ?>;
const PR_HAS_INPROGRESS = <?= ($in_progress && empty($recent_complete)) ? 'true' : 'false' ?>;
const PR_FORCE_FRESH    = <?= $force_fresh ? 'true' : 'false' ?>;

// ═══════════════════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════════════════
let sessionId = 0;
let sessionLanguage = 'hi';
let sessionStartedAt = 0;
let elapsedInterval = null;
let endedSafely = false;
let currentTurn = null;
let lastAskedQuestion = '';
let lastAnswerSource = 'text'; // 'chip', 'text', or 'voice' (set by chip/text/mic handlers)
let chatHistory = []; // [{ question, answer, turn_no }]
let submitInFlight = false;

// ═══════════════════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════════════════
function $(id) { return document.getElementById(id); }

function show(id) {
  ['screenUnsupported','screenLanding','screenConsent','screenInterview','screenClosing','screenResume','screenRecent'].forEach(function(s) {
    var el = $(s);
    if (el) el.classList.toggle('hidden', s !== id);
  });
}

// No-op for compatibility with shared init code
function browserSupports() { return true; }

function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, function(c) {
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });
}

function setLangAttr(el, lang) { if (el) el.setAttribute('lang', lang || 'hi'); }

// ═══════════════════════════════════════════════════════════════════
//  i18n LABELS
// ═══════════════════════════════════════════════════════════════════
const I18N = {
  hi: {
    placeholder:    'अपने शब्दों में बताइए…',
    textLabel:      'या यहाँ लिखिए',
    sendBtn:        '✓ भेजें',
    historyLabel:   'पिछली बातचीत',
    thinking:       'अगला सवाल तैयार कर रहा हूँ…',
    fallbackChips:  ['ठीक चल रहा है', 'थोड़ा मुश्किल है', 'काफ़ी थका हुआ हूँ', 'विस्तार से बताती हूँ…'],
    openingChips:   ['ठीक-ठाक है', 'थोड़ा थका हुआ हूँ', 'काफ़ी मुश्किल है', 'मैं बताती हूँ…'],
    chars:          'characters',
    reflectionLbl:  'A moment of reflection',
    micStart:       '🎙️ बोलें',
    micStop:        '⏹ रोकें',
    micListening:   '🎙️ सुन रहा हूँ…',
    micUnsupported: 'इस ब्राउज़र में voice नहीं चलता। Chrome इस्तेमाल करें।',
  },
  en: {
    placeholder:    'Tell me in your own words…',
    textLabel:      'Or type here',
    sendBtn:        '✓ Send',
    historyLabel:   'Past conversation',
    thinking:       'Preparing next question…',
    fallbackChips:  ["Going okay", 'A bit hard', "Pretty tired", 'Let me explain…'],
    openingChips:   ["It's okay", 'A bit tired', "It's pretty hard", 'Let me explain…'],
    chars:          'characters',
    reflectionLbl:  'A moment of reflection',
    micStart:       '🎙️ Speak',
    micStop:        '⏹ Stop',
    micListening:   '🎙️ Listening…',
    micUnsupported: 'Voice not supported in this browser. Try Chrome.',
  }
};

function applyI18n() {
  var t = I18N[sessionLanguage] || I18N.hi;
  var ta = $('iTextArea');
  if (ta) { ta.placeholder = t.placeholder; setLangAttr(ta, sessionLanguage); }
  if ($('iTextLabel'))      $('iTextLabel').textContent = t.textLabel;
  if ($('iSendBtnLabel'))   $('iSendBtnLabel').textContent = t.sendBtn;
  if ($('iHistoryLabel'))   $('iHistoryLabel').textContent = t.historyLabel;
  if ($('iThinkingText'))   $('iThinkingText').textContent = t.thinking;
  if ($('iReflectionLabel'))$('iReflectionLabel').textContent = t.reflectionLbl;
  if ($('iMicBtnLabel'))    $('iMicBtnLabel').textContent = t.micStart;
}

// ═══════════════════════════════════════════════════════════════════
//  CHIPS
// ═══════════════════════════════════════════════════════════════════
function renderChips(options) {
  var box = $('iOptions');
  if (!box) return;
  box.innerHTML = '';
  // Only show chips if AI actually returned them. No generic fallbacks —
  // mismatched chips confuse parents more than no chips at all.
  if (!options || !options.length) {
    box.classList.add('hidden');
    console.log('[pr] no chips for this turn (AI returned none) — text/mic only');
    return;
  }
  box.classList.remove('hidden');
  console.log('[pr] chips:', options);
  options.forEach(function(text) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pr-chip';
    btn.textContent = text;
    btn.addEventListener('click', function() { handleChipTap(text); });
    box.appendChild(btn);
  });
}

function handleChipTap(text) {
  if (submitInFlight) return;
  console.log('[pr] chip tapped:', text);
  if (micActive) { try { stopMic(); } catch(e){} }
  lastAnswerSource = 'chip';
  submitAnswer(text);
}

// ═══════════════════════════════════════════════════════════════════
//  TEXTAREA
// ═══════════════════════════════════════════════════════════════════
function wireTextarea() {
  var ta = $('iTextArea');
  var btn = $('iSendBtn');
  var cc = $('iCharCount');
  if (!ta || !btn) return;
  function refresh() {
    var len = ta.value.trim().length;
    btn.disabled = (len === 0);
    if (cc) cc.textContent = len + ' ' + I18N[sessionLanguage].chars;
  }
  ta.addEventListener('input', refresh);
  ta.addEventListener('keydown', function(e) {
    // Enter (without shift) sends
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!btn.disabled) handleSendTap();
    }
  });
  btn.addEventListener('click', handleSendTap);
  refresh();
}

function handleSendTap() {
  if (submitInFlight) return;
  var ta = $('iTextArea');
  if (!ta) return;
  var text = ta.value.trim();
  if (!text) return;
  // Stop mic if still running
  if (micActive) { try { stopMic(); } catch(e){} }
  // If mic populated the textarea, source stays 'voice'; if user typed only, mark 'text'
  if (lastAnswerSource !== 'voice') lastAnswerSource = 'text';
  console.log('[pr] send tap (' + text.length + ' chars, source=' + lastAnswerSource + ')');
  submitAnswer(text);
}

// ═══════════════════════════════════════════════════════════════════
//  MIC — optional voice input via Web Speech API
// ═══════════════════════════════════════════════════════════════════
let recognition = null;
let micActive = false;
let micInterimText = '';
let micFinalText = '';

function micSupported() {
  return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
}

function wireMic() {
  var btn = $('iMicBtn');
  if (!btn) return;
  if (!micSupported()) {
    btn.disabled = true;
    btn.classList.add('opacity-50','cursor-not-allowed');
    btn.title = I18N[sessionLanguage].micUnsupported;
    return;
  }
  btn.addEventListener('click', toggleMic);
}

function toggleMic() {
  if (micActive) {
    stopMic();
  } else {
    startMic();
  }
}

function startMic() {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) {
    alert(I18N[sessionLanguage].micUnsupported);
    return;
  }
  try {
    recognition = new SR();
  } catch (e) {
    alert(I18N[sessionLanguage].micUnsupported);
    return;
  }
  recognition.continuous = true;
  recognition.interimResults = true;
  recognition.lang = (sessionLanguage === 'hi') ? 'hi-IN' : 'en-IN';

  // Preserve any text already typed; new transcribed text appends
  var ta = $('iTextArea');
  micFinalText = ta ? ta.value : '';
  micInterimText = '';

  recognition.onstart = function() {
    console.log('[pr] mic started, lang=' + recognition.lang);
    micActive = true;
    lastAnswerSource = 'voice';
    var btn = $('iMicBtn');
    var lbl = $('iMicBtnLabel');
    if (btn) {
      btn.classList.remove('bg-rose-100','hover:bg-rose-200','text-rose-900','border-rose-300');
      btn.classList.add('bg-red-600','hover:bg-red-700','text-white','border-red-700','animate-pulse');
    }
    if (lbl) lbl.textContent = I18N[sessionLanguage].micStop;
  };

  recognition.onresult = function(event) {
    var finalAdd = '';
    var interim = '';
    for (var i = event.resultIndex; i < event.results.length; i++) {
      var transcript = event.results[i][0].transcript;
      if (event.results[i].isFinal) finalAdd += transcript + ' ';
      else interim += transcript;
    }
    if (finalAdd) micFinalText = (micFinalText + ' ' + finalAdd).trim();
    micInterimText = interim;
    // Update textarea live
    var ta = $('iTextArea');
    if (ta) {
      ta.value = (micFinalText + ' ' + micInterimText).trim();
      // Manually trigger input refresh (char counter + send button enable)
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  };

  recognition.onerror = function(event) {
    console.warn('[pr] mic error:', event.error);
    if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
      alert(sessionLanguage === 'hi'
        ? 'माइक की permission देनी होगी।'
        : 'Please allow microphone permission.');
    }
    stopMic();
  };

  recognition.onend = function() {
    console.log('[pr] mic ended');
    micActive = false;
    var btn = $('iMicBtn');
    var lbl = $('iMicBtnLabel');
    if (btn) {
      btn.classList.remove('bg-red-600','hover:bg-red-700','text-white','border-red-700','animate-pulse');
      btn.classList.add('bg-rose-100','hover:bg-rose-200','text-rose-900','border-rose-300');
    }
    if (lbl) lbl.textContent = I18N[sessionLanguage].micStart;
  };

  try {
    recognition.start();
  } catch (e) {
    console.error('[pr] mic start failed:', e);
    alert(I18N[sessionLanguage].micUnsupported);
  }
}

function stopMic() {
  if (recognition) {
    try { recognition.stop(); } catch(e) {}
  }
  // The onend handler resets UI; nothing more to do
}

// ═══════════════════════════════════════════════════════════════════
//  SUBMIT — the single submit path (chips + textarea both call this)
// ═══════════════════════════════════════════════════════════════════
function showThinking() {
  // Hide all interactive elements; show pulsing logo
  ['iQuestionText','iOptions','iTextRow','iReflection'].forEach(function(id) {
    var el = $(id);
    if (el) el.classList.add('hidden');
  });
  if ($('iThinking')) $('iThinking').classList.remove('hidden');
}

function showInteractive() {
  ['iQuestionText','iOptions','iTextRow'].forEach(function(id) {
    var el = $(id);
    if (el) el.classList.remove('hidden');
  });
  if ($('iThinking')) $('iThinking').classList.add('hidden');
}

async function submitAnswer(answerText) {
  if (submitInFlight) { console.warn('[pr] submit already in flight'); return; }
  submitInFlight = true;

  // Capture this turn into history BEFORE we hide everything
  if (lastAskedQuestion && answerText) {
    chatHistory.push({
      question: lastAskedQuestion,
      answer: answerText,
      turn_no: (currentTurn && currentTurn.turn_no) || chatHistory.length + 1,
      source: lastAnswerSource
    });
    renderHistoryBox();
  }

  showThinking();

  var fd = new FormData();
  fd.append('action', 'turn');
  fd.append('csrf', CSRF);
  fd.append('session_id', sessionId);
  fd.append('transcript', answerText);
  fd.append('answer_source', lastAnswerSource);
  fd.append('time_seconds', '0');
  fd.append('acoustic', '{}');

  let data = null;
  const abortCtrl = new AbortController();
  const abortTimer = setTimeout(function() { try { abortCtrl.abort(); } catch(e){} }, 35000);

  try {
    const t0 = Date.now();
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd, signal: abortCtrl.signal });
    clearTimeout(abortTimer);
    console.log('[pr] turn responded in ' + ((Date.now()-t0)/1000).toFixed(1) + 's');
    const text = await r.text();
    try { data = JSON.parse(text); }
    catch (e) {
      console.error('[pr] non-JSON response:', text.substring(0, 300));
      handleSubmitError(I18N[sessionLanguage] === I18N.hi
        ? 'सर्वर से अजीब जवाब आया। फिर से कोशिश करें।'
        : 'Server returned a strange response. Please try again.');
      return;
    }
  } catch (e) {
    clearTimeout(abortTimer);
    console.error('[pr] network error:', e);
    var isAbort = e && e.name === 'AbortError';
    handleSubmitError(isAbort
      ? (sessionLanguage === 'hi' ? 'सर्वर देर लगा रहा है। फिर से भेजें?' : 'Server is taking too long. Try again?')
      : (sessionLanguage === 'hi' ? 'नेटवर्क की दिक्कत। फिर से भेजें?' : 'Network error. Try again?'));
    return;
  }

  if (data && data.error) {
    handleSubmitError(data.error);
    return;
  }

  if (data && data.safety_red) {
    if ($('iCrisis')) $('iCrisis').classList.remove('hidden');
  }

  if (data && data.done) {
    showClosing(data);
    return;
  }

  if (data && data.turn) {
    // Small visible delay so the pulse is felt
    setTimeout(function() {
      presentTurn(data.reflection || '', data.tone_insight || '', data.turn);
      submitInFlight = false;
    }, 350);
  } else {
    handleSubmitError(sessionLanguage === 'hi' ? 'कुछ गड़बड़ है। फिर से भेजें?' : 'Something went wrong. Try again?');
  }
}

function handleSubmitError(msg) {
  submitInFlight = false;
  // Restore the previous turn's UI so the parent can retry
  if (currentTurn) {
    presentTurn('', '', currentTurn);
  } else {
    showInteractive();
  }
  if ($('iThinkingText')) $('iThinkingText').textContent = msg;
  if ($('iThinking')) $('iThinking').classList.remove('hidden');
  // After 3s, hide thinking error so user can retry chips
  setTimeout(function() {
    if ($('iThinking')) $('iThinking').classList.add('hidden');
    if ($('iThinkingText')) $('iThinkingText').textContent = I18N[sessionLanguage].thinking;
  }, 3000);
}

// ═══════════════════════════════════════════════════════════════════
//  PRESENT NEXT TURN
// ═══════════════════════════════════════════════════════════════════
const PHASE_LABELS = {
  1: 'Opening', 2: 'Home', 3: 'Couple', 4: 'In-laws',
  5: 'Self', 6: 'Child', 7: 'Coping', 8: 'Closing', 9: 'Wrap', 10: 'Wrap'
};

function presentTurn(reflection, toneInsight, turn) {
  currentTurn = turn;
  lastAskedQuestion = turn.question || '';

  if ($('iPhaseLabel')) $('iPhaseLabel').textContent = PHASE_LABELS[turn.phase] || 'Reflection';
  if ($('iTurnNo'))     $('iTurnNo').textContent = 'Turn ' + (turn.turn_no || '?');

  // Reflection block — only show if there's content AND it's not voice-tone for non-voice answers
  if (reflection && reflection.trim()) {
    if ($('iReflectionText')) $('iReflectionText').textContent = reflection;
    if ($('iReflection'))     $('iReflection').classList.remove('hidden');
  } else {
    if ($('iReflection')) $('iReflection').classList.add('hidden');
  }

  if ($('iQuestionText')) {
    $('iQuestionText').textContent = turn.question || '';
    setLangAttr($('iQuestionText'), sessionLanguage);
  }

  // Clear and refocus textarea
  var ta = $('iTextArea');
  if (ta) { ta.value = ''; }
  var sb = $('iSendBtn');
  if (sb) sb.disabled = true;
  var cc = $('iCharCount');
  if (cc) cc.textContent = '0 ' + I18N[sessionLanguage].chars;

  // Reset answer source
  lastAnswerSource = 'text';

  // If mic was somehow still active, stop it (defensive)
  if (micActive) { try { stopMic(); } catch(e){} }
  micFinalText = '';
  micInterimText = '';

  showInteractive();

  // Render chips AFTER showInteractive so empty chips can stay hidden
  renderChips(turn.options || []);
}

// ═══════════════════════════════════════════════════════════════════
//  HISTORY
// ═══════════════════════════════════════════════════════════════════
function renderHistoryBox() {
  var wrap = $('iHistoryWrap');
  var box  = $('iHistoryBox');
  var cnt  = $('iHistoryCount');
  if (!wrap || !box) return;
  if (!chatHistory.length) { wrap.classList.add('hidden'); return; }
  wrap.classList.remove('hidden');
  if (cnt) cnt.textContent = chatHistory.length;
  box.innerHTML = '';
  chatHistory.forEach(function(item, idx) {
    var srcBadge = item.source === 'chip' ? '🔘'
                 : item.source === 'text' ? '⌨️'
                 : '💬';
    var row = document.createElement('div');
    row.className = 'border-b border-slate-100 last:border-0 pb-3';
    row.innerHTML = ''
      + '<div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Q' + (item.turn_no || (idx+1)) + '</div>'
      + '<div class="text-sm text-slate-700 mb-2 leading-snug">' + escapeHtml(item.question) + '</div>'
      + '<div class="bg-indigo-50 border border-indigo-200 rounded-lg p-2 text-sm text-slate-800 italic">'
      +   '<span class="opacity-60 mr-1">' + srcBadge + '</span>' + escapeHtml(item.answer)
      + '</div>';
    box.appendChild(row);
  });
}

// ═══════════════════════════════════════════════════════════════════
//  START / FOLLOWUP / END
// ═══════════════════════════════════════════════════════════════════
async function startSession() {
  console.log('[pr] startSession');
  const fd = new FormData();
  fd.append('action', 'start');
  fd.append('csrf', CSRF);

  let data, raw, r;
  try {
    r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    raw = await r.text();
  } catch (e) {
    alert(sessionLanguage === 'hi'
      ? 'सत्र शुरू नहीं हो सका। नेटवर्क जाँचें।'
      : 'Could not start. Please check your connection.');
    return;
  }

  try { data = JSON.parse(raw); }
  catch (e) { alert('Bad response from server. Try again.\n\n' + raw.substring(0, 200)); return; }

  if (data.error) {
    if (data.redirect) window.location.href = data.redirect;
    else alert(data.error);
    return;
  }

  sessionId = data.session_id;
  sessionLanguage = data.language || 'hi';
  applyI18n();

  show('screenInterview');

  // pr-resume-history-v1: populate chat history panel from prior turns
  if (data.prior_turns && data.prior_turns.length) {
    chatHistory = data.prior_turns.map(function(t) {
      return {
        question: t.question || '',
        answer: t.transcript || '',
        turn_no: t.turn_no || 0,
        source: 'unknown'
      };
    });
    renderHistoryBox();
    console.log('[pr] resumed with ' + chatHistory.length + ' prior turn(s) loaded into history');
  }

  // Start elapsed timer
  sessionStartedAt = Date.now();
  if (elapsedInterval) clearInterval(elapsedInterval);
  elapsedInterval = setInterval(function() {
    const sec = Math.floor((Date.now() - sessionStartedAt) / 1000);
    const m = Math.floor(sec / 60), s = sec % 60;
    if ($('iElapsed')) $('iElapsed').textContent = m + ':' + (s < 10 ? '0' + s : s);
  }, 1000);

  presentTurn('', '', data.turn);
}

async function startFollowup(sid) {
  console.log('[pr] startFollowup', sid);
  const fd = new FormData();
  fd.append('action', 'start_followup');
  fd.append('csrf', CSRF);
  fd.append('session_id', sid);
  let r, raw, data;
  try {
    r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    raw = await r.text();
    data = JSON.parse(raw);
  } catch (e) {
    alert('Could not open follow-up. Please refresh and try again.');
    return;
  }
  if (data.error) { alert(data.error); return; }

  sessionId = data.session_id;
  sessionLanguage = data.language || 'hi';
  applyI18n();
  show('screenInterview');
  sessionStartedAt = Date.now();
  if (elapsedInterval) clearInterval(elapsedInterval);
  elapsedInterval = setInterval(function() {
    const sec = Math.floor((Date.now() - sessionStartedAt) / 1000);
    const m = Math.floor(sec / 60), s = sec % 60;
    if ($('iElapsed')) $('iElapsed').textContent = m + ':' + (s < 10 ? '0' + s : s);
  }, 1000);
  presentTurn('', '', data.turn);
}

function pauseAndExit() {
  if (!confirm(sessionLanguage === 'hi'
      ? 'रुकें? अब तक की बात सहेज ली जाएगी। आप दोबारा आ कर continue कर सकती हैं।'
      : 'Pause? Your conversation so far is saved. You can come back and continue later.')) return;
  endedSafely = true;
  if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }

  // POST cancel (now means PAUSE — session stays in_progress)
  const fd = new FormData();
  fd.append('action', 'cancel');
  fd.append('csrf', CSRF);
  fd.append('session_id', sessionId);
  fetch('/parent-reflect-api.php', { method: 'POST', body: fd })
    .finally(function() { window.location.href = '/dashboard.php?paused=1'; });
}

async function finishNow() {
  if (!confirm(sessionLanguage === 'hi'
      ? 'अभी अंत करें? आपका report तैयार हो जाएगा अब तक की बातचीत से। बाद में और जोड़ नहीं सकेंगी।'
      : 'Finish now? Your report will be generated from what you have said so far. You will not be able to add more later.')) return;

  endedSafely = true;
  if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }
  submitInFlight = true;
  showThinking();
  if ($('iThinkingText')) {
    $('iThinkingText').textContent = (sessionLanguage === 'hi'
      ? 'आपका report तैयार हो रहा है… थोड़ा रुकिए।'
      : 'Generating your report… please wait.');
  }

  const fd = new FormData();
  fd.append('action', 'finish_early');
  fd.append('csrf', CSRF);
  fd.append('session_id', sessionId);

  try {
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    const text = await r.text();
    let data;
    try { data = JSON.parse(text); }
    catch (e) {
      console.error('[pr] finish_early bad JSON:', text.substring(0, 300));
      alert(sessionLanguage === 'hi' ? 'Report तैयार करने में दिक्कत।' : 'Could not generate report. Try again.');
      submitInFlight = false;
      showInteractive();
      return;
    }
    if (data.error) {
      alert(data.error);
      submitInFlight = false;
      showInteractive();
      return;
    }
    // Show closing screen
    showClosing(data);
  } catch (e) {
    console.error('[pr] finish_early error', e);
    alert(sessionLanguage === 'hi' ? 'Network दिक्कत। फिर कोशिश करें।' : 'Network error. Try again.');
    submitInFlight = false;
    showInteractive();
  }
}

function showClosing(data) {
  endedSafely = true;
  if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }

  if (data.reflection && $('cReflection')) {
    $('cReflection').classList.remove('hidden');
    $('cReflectionText').textContent = data.reflection;
  }

  // fresh-v1: render short structured close inline
  var summaryMd = (data._summary_md || data.summary_md || '').trim();
  if (summaryMd && $('cSummaryWrap')) {
    $('cSummaryWrap').innerHTML = renderSummaryMarkdown(summaryMd);
    $('cSummaryWrap').classList.remove('hidden');
    if ($('cClosingText') && $('cClosingText').parentElement) {
      $('cClosingText').parentElement.classList.add('hidden');
    }
  } else if ($('cClosingText')) {
    $('cClosingText').textContent = data.closing || '';
  }

  if (data.safety_red && $('cSafetyBlock')) $('cSafetyBlock').classList.remove('hidden');

  // fresh-v2: kick off v3 comprehensive report polling
  if (sessionId) {
    startV3Polling(sessionId);
  }

  show('screenClosing');
  setTimeout(function() { window.scrollTo({ top: 0, behavior: 'smooth' }); }, 100);
}

/* fresh-v2: poll for comprehensive v3 report readiness.
 * It runs in background after pr_finalise; takes ~30-60s.
 * Polls every 5s for up to 3 minutes. Renders listing inline when ready.
 */
let _v3PollTimer = null;
let _v3PollAttempts = 0;
const _v3MaxAttempts = 36;  // 36 × 5s = 3 min

function startV3Polling(sid) {
  if (_v3PollTimer) clearInterval(_v3PollTimer);
  _v3PollAttempts = 0;
  if ($('cV3Loading')) $('cV3Loading').classList.remove('hidden');

  // Set localised loading text
  var hi = (sessionLanguage === 'hi');
  if ($('cV3LoadingText')) $('cV3LoadingText').textContent = hi
    ? 'विस्तृत report तैयार हो रहा है (~1 मिनट)…'
    : 'Preparing detailed report (~1 minute)…';
  if ($('cV3LoadingHint')) $('cV3LoadingHint').textContent = hi
    ? 'पेज खुला रखें — 9-areas का scoring यहाँ अपने आप आ जाएगा।'
    : 'Leave this page open — the comprehensive 9-area scoring will appear here automatically.';
  if ($('cV3Title')) $('cV3Title').textContent = hi
    ? 'विस्तृत Scoring और 7-दिन Plan'
    : 'Detailed Scoring & 7-Day Plan';
  if ($('cV3DownloadLabel')) $('cV3DownloadLabel').textContent = hi
    ? 'पूरा report खोलें'
    : 'Open full report';

  _v3PollTimer = setInterval(function() { pollV3Once(sid); }, 5000);
  // Also fire one immediate poll after a 3s grace
  setTimeout(function() { pollV3Once(sid); }, 3000);
}

async function pollV3Once(sid) {
  _v3PollAttempts++;
  if (_v3PollAttempts > _v3MaxAttempts) {
    if (_v3PollTimer) clearInterval(_v3PollTimer);
    if ($('cV3LoadingText')) $('cV3LoadingText').textContent = (sessionLanguage === 'hi')
      ? 'विस्तृत report अभी तैयार नहीं हुआ। थोड़ी देर में page reload करें।'
      : 'Detailed report not ready yet. Please reload the page in a minute.';
    return;
  }

  try {
    const fd = new FormData();
    fd.append('action', 'v3_status');
    fd.append('csrf', CSRF);
    fd.append('session_id', sid);
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data && data.ready && data.listing_html) {
      if (_v3PollTimer) { clearInterval(_v3PollTimer); _v3PollTimer = null; }
      if ($('cV3Loading')) $('cV3Loading').classList.add('hidden');
      if ($('cV3ListingBody')) $('cV3ListingBody').innerHTML = data.listing_html;
      if ($('cV3Ready')) $('cV3Ready').classList.remove('hidden');
      console.log('[pr] v3 report ready after ' + _v3PollAttempts + ' polls');

      // fresh-v7: also lazy-load the course preview
      var cPrev = $('cCoursePreviewSection');
      if (cPrev && !cPrev._loaded) {
        cPrev._loaded = true;
        cPrev.classList.remove('hidden');
        _loadCoursePreview({
          sid: sid,
          lang: sessionLanguage || 'hi',
          loadingEl: $('cCoursePreviewLoading'),
          bodyEl: $('cCoursePreviewBody'),
          sectionEl: cPrev,
        });
      }

      // Wire the translate button now that listing is rendered
      var cTBtn = $('cTranslateBtn');
      if (cTBtn && !cTBtn._wired) {
        cTBtn._wired = true;
        // Detect current language by reading the v3 listing JSON — simplest: assume sessionLanguage
        var curLang = sessionLanguage || 'hi';
        cTBtn.setAttribute('data-current', curLang);
        if ($('cTranslateLabel')) {
          $('cTranslateLabel').textContent = (curLang === 'hi') ? 'View in English' : 'हिंदी में देखें';
        }
        cTBtn.addEventListener('click', function() {
          _v3Translate({
            sid: sid,
            currentLang: cTBtn.getAttribute('data-current') || curLang,
            button: cTBtn,
            btnLabel: $('cTranslateLabel'),
            statusBox: $('cTranslateStatus'),
            statusText: $('cTranslateStatusText'),
            listingTarget: $('cV3ListingBody'),
            summaryTarget: $('cSummaryWrap'),
          });
        });
      }
    }
  } catch (e) {
    console.warn('[pr] v3 poll error:', e);
  }
}

/* fresh-v1: lightweight markdown → HTML renderer.
 * Handles the 3-paragraph closing structure: ## Heading
 paragraph
 * Recognises **bold** and *italic*. No code blocks needed.
 */
function renderSummaryMarkdown(md) {
  if (!md) return '';
  // Normalise line endings
  md = md.replace(/\r\n/g, '\n');
  // Split into blocks separated by blank lines
  var blocks = md.split(/\n\s*\n/);
  var html = '';
  blocks.forEach(function(block) {
    block = block.trim();
    if (!block) return;
    // Heading? (## Heading or ### Heading)
    var hm = block.match(/^(#{1,4})\s+(.+)$/);
    if (hm) {
      var level = hm[1].length;
      var headingText = hm[2].trim();
      // Use h2/h3 with custom styling for indigo theme
      var tag = level <= 2 ? 'h2' : 'h3';
      var cls = level <= 2
        ? 'text-indigo-700 font-bold text-lg mt-5 mb-2'
        : 'text-indigo-600 font-semibold text-base mt-4 mb-2';
      html += '<' + tag + ' class="' + cls + '">' + escapeHtml(headingText) + '</' + tag + '>';
      return;
    }
    // Regular paragraph — handle inline bold/italic
    var para = escapeHtml(block);
    // bold **text**
    para = para.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // italic *text*
    para = para.replace(/(^|\s)\*([^*]+)\*(\s|$|[.,?!])/g, '$1<em>$2</em>$3');
    // line breaks within paragraph (single newlines → <br>)
    para = para.replace(/\n/g, '<br>');
    html += '<p class="text-slate-800 leading-relaxed mb-3">' + para + '</p>';
  });
  return html;
}

// ═══════════════════════════════════════════════════════════════════
//  DOM READY
// ═══════════════════════════════════════════════════════════════════
/* fresh-v7: 7-day course preview loader */
async function _loadCoursePreview(opts) {
  // opts: { sid, lang, loadingEl, bodyEl, sectionEl }
  if (!opts.sid || !opts.bodyEl) return;
  try {
    const fd = new FormData();
    fd.append('action', 'course_preview');
    fd.append('csrf', CSRF);
    fd.append('session_id', opts.sid);
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data && data.ok && data.preview) {
      data.session_id = opts.sid;
      opts.bodyEl.innerHTML = _renderCoursePreview(data, opts.lang);
      if (opts.loadingEl) opts.loadingEl.classList.add('hidden');
      opts.bodyEl.classList.remove('hidden');
      if (opts.sectionEl) opts.sectionEl.classList.remove('hidden');
      _wireCoursePurchaseButton(opts.bodyEl, opts.sid, opts.lang);
    } else {
      if (opts.loadingEl) {
        opts.loadingEl.innerHTML = '<p class="text-amber-800 text-sm">' +
          escapeHtml((data && data.error) || 'Preview could not be generated. The comprehensive report must be ready first.') +
          '</p>';
      }
    }
  } catch (e) {
    console.warn('[pr] course preview error:', e);
    if (opts.loadingEl) {
      opts.loadingEl.innerHTML = '<p class="text-amber-800 text-sm">Preview failed to load. Refresh the page to retry.</p>';
    }
  }
}

function _renderCoursePreview(data, lang) {
  const isHi = lang === 'hi';
  const labelStartFree = isHi ? '🔓 7-दिन का full course शुरू करें — ₹999' : '🔓 Unlock full 7-day course — ₹999';
  const labelOpenDay = isHi ? '📖 आज का दिन खोलें (Day ' : '📖 Open today (Day ';
  const labelContinue = isHi ? ' जारी रखें)' : ')';
  const labelHeader = isHi ? '📚 7-दिन का personalised course preview' : '📚 Personalised 7-Day Course Preview';
  const labelTagline = isHi
    ? 'हर दिन का content आपकी पिछले दिन की check-in के आधार पर adapt होता है।'
    : 'Each day adapts to your previous day\'s check-in. Designed just for you.';

  let html = '<div class="bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-2xl p-5 md:p-6">';
  html += '<div class="mb-4">';
  html += '<h3 class="font-bold text-slate-900 text-lg mb-1">' + labelHeader + '</h3>';
  html += '<p class="text-sm text-slate-600">' + labelTagline + '</p>';
  html += '</div>';

  html += '<div class="space-y-2 mb-5">';
  data.preview.forEach(function(d) {
    const dayNum = d.day || 0;
    const theme = escapeHtml(d.theme || '');
    const outline = escapeHtml(d.outline || '');
    html += '<details class="bg-white border border-slate-200 rounded-xl">';
    html += '<summary class="cursor-pointer px-4 py-3 flex items-center justify-between hover:bg-slate-50 rounded-xl">';
    html += '<span class="flex items-center gap-3">';
    html += '<span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-600 text-white text-sm font-bold">' + dayNum + '</span>';
    html += '<span class="font-semibold text-slate-900">' + theme + '</span>';
    html += '</span>';
    html += '<span class="text-indigo-600 text-xs">▼</span>';
    html += '</summary>';
    html += '<div class="px-4 pb-4 pt-1 text-sm text-slate-700 leading-relaxed border-t border-slate-100">';
    html += outline;
    html += '</div>';
    html += '</details>';
  });
  html += '</div>';

  // CTA — depends on course_status
  if (data.course_status === 'active' && data.course_info) {
    const day = data.course_info.day_no || 1;
    const cid = data.course_info.course_id;
    html += '<a href="/home-course.php?id=' + cid + '" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white text-center font-bold py-3 px-4 rounded-xl shadow-sm">';
    html += labelOpenDay + day + labelContinue;
    html += '</a>';
  } else {
    html += '<button type="button" id="ctaStartCourse" data-sid="' + (data.session_id || '') + '"';
    html += ' class="block w-full bg-gradient-to-r from-indigo-600 to-purple-700 hover:opacity-95 text-white text-center font-bold py-3 px-4 rounded-xl shadow-md">';
    html += labelStartFree;
    html += '</button>';
    html += '<p class="text-xs text-center text-slate-500 mt-2">' + (isHi ? '₹999 एक बार — सारे 7 दिन शामिल' : '₹999 one-time — all 7 days included') + '</p>';
  }

  html += '</div>';
  return html;
}

/* fresh-v5: translate + print handlers (both screenClosing and screenRecent) */
async function _purchaseCourse(sid, lang) {
  const isHi = lang === 'hi';

  function _showOverlay() {
    const el = document.getElementById('purchaseOverlay');
    if (!el) return;
    const t = document.getElementById('purchaseOverlayTitle');
    const s = document.getElementById('purchaseOverlaySub');
    if (t) t.textContent = isHi ? 'आपका course तैयार हो रहा है…' : 'Setting up your course…';
    if (s) s.textContent = isHi
      ? 'Wallet charge हो रहा है, आपका 7-दिन का plan बन रहा है, और Day 1 का content तैयार हो रहा है। 10-15 seconds लगेंगे।'
      : 'Charging wallet, creating your personalised 7-day plan, and generating Day 1 content. Takes about 10-15 seconds.';
    el.classList.remove('hidden');
  }
  function _hideOverlay() {
    const el = document.getElementById('purchaseOverlay');
    if (el) el.classList.add('hidden');
  }

  const confirmMsg = isHi
    ? 'Course \u0936\u0941\u0930\u0942 \u0915\u0930\u0928\u0947 \u0915\u0947 \u0932\u093f\u090f \u20b9999 wallet \u0938\u0947 \u0915\u091f\u0947\u0902\u0917\u0947\u0964 \u0906\u0917\u0947 \u092c\u0922\u093c\u0947\u0902?'
    : '\u20b9999 will be deducted from your wallet to start the course. Continue?';
  if (!confirm(confirmMsg)) return;

  _showOverlay();

  const btn = document.getElementById('ctaStartCourse');
  if (btn) {
    btn.disabled = true;
    btn.textContent = isHi ? 'Loading...' : 'Processing...';
    btn.style.opacity = '0.7';
  }

  try {
    const fd = new FormData();
    fd.append('action', 'course_purchase');
    fd.append('csrf', CSRF);
    fd.append('session_id', sid);
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data && data.ok && data.redirect) {
      window.location.href = data.redirect;
      return;
    }
    _hideOverlay();
    const msg = (data && data.error) || (isHi ? 'Kuch gadbad hui.' : 'Something went wrong.');
    alert(msg);
    if (data && data.top_up_url) {
      if (confirm(isHi ? 'Wallet top up karen?' : 'Top up wallet now?')) {
        window.location.href = data.top_up_url;
        return;
      }
    }
  } catch (e) {
    _hideOverlay();
    console.error('[pr] purchase error', e);
    alert(isHi ? 'Network error. Kripya dobara koshish karen.' : 'Network error. Please try again.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.style.opacity = '';
      const label = isHi
        ? '\ud83d\udd13 7-\u0926\u093f\u0928 \u0915\u093e full course \u0936\u0941\u0930\u0942 \u0915\u0930\u0947\u0902 \u2014 \u20b9999'
        : '\ud83d\udd13 Unlock full 7-day course \u2014 \u20b9999';
      btn.textContent = label;
    }
  }
}

function _wireCoursePurchaseButton(container, sid, lang) {
  if (!container) return;
  const btn = container.querySelector('#ctaStartCourse');
  if (!btn) return;
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    _purchaseCourse(sid, lang);
  });
}

async function _v3Translate(opts) {
  // opts: { sid, currentLang, btnLabel, statusBox, statusText, listingTarget, summaryTarget }
  const targetLang = opts.currentLang === 'hi' ? 'en' : 'hi';
  if (opts.statusBox) opts.statusBox.classList.remove('hidden');
  if (opts.statusText) {
    opts.statusText.textContent = (opts.currentLang === 'hi')
      ? 'Translating to English… first time takes 15-20 seconds.'
      : 'हिंदी में translate हो रहा है… पहली बार 15-20 seconds लगते हैं।';
  }
  try {
    const fd = new FormData();
    fd.append('action', 'v3_translate');
    fd.append('csrf', CSRF);
    fd.append('session_id', opts.sid);
    fd.append('target_lang', targetLang);
    const r = await fetch('/parent-reflect-api.php', { method: 'POST', body: fd });
    const data = await r.json();
    if (data && data.ok) {
      if (data.listing_html && opts.listingTarget) {
        opts.listingTarget.innerHTML = data.listing_html;
      }
      if (data.summary_md && opts.summaryTarget) {
        opts.summaryTarget.innerHTML = renderSummaryMarkdown(data.summary_md);
        opts.summaryTarget.classList.remove('hidden');
      }
      // Flip button label
      if (opts.btnLabel) {
        opts.btnLabel.textContent = (targetLang === 'hi') ? 'View in English' : 'हिंदी में देखें';
      }
      opts.currentLang = targetLang;
      if (opts.button) opts.button.setAttribute('data-current', targetLang);
    } else {
      alert((data && data.error) || 'Translation failed.');
    }
  } catch (e) {
    console.error('[pr] translate error', e);
    alert('Translation failed. Please try again.');
  } finally {
    if (opts.statusBox) opts.statusBox.classList.add('hidden');
  }
}

function _setupPrintButton(btn) {
  if (!btn) return;
  btn.addEventListener('click', function() {
    // Add print-only CSS class to body, then print
    document.body.classList.add('pr-printing');
    setTimeout(function() {
      window.print();
      setTimeout(function() {
        document.body.classList.remove('pr-printing');
      }, 500);
    }, 100);
  });
}

window.addEventListener('DOMContentLoaded', function() {

  // screenRecent buttons (server-rendered on page load)
  // fresh-v7: trigger course preview load if section present
  var rcPrev = $('rcCoursePreviewSection');
  if (rcPrev) {
    _loadCoursePreview({
      sid: parseInt(rcPrev.getAttribute('data-sid'), 10),
      lang: rcPrev.getAttribute('data-lang') || 'hi',
      loadingEl: $('rcCoursePreviewLoading'),
      bodyEl: $('rcCoursePreviewBody'),
      sectionEl: rcPrev,
    });
  }

  var rcTBtn = $('rcTranslateBtn');
  if (rcTBtn) {
    rcTBtn.addEventListener('click', function() {
      _v3Translate({
        sid: parseInt(rcTBtn.getAttribute('data-sid'), 10),
        currentLang: rcTBtn.getAttribute('data-current') || 'hi',
        button: rcTBtn,
        btnLabel: $('rcTranslateLabel'),
        statusBox: $('rcTranslateStatus'),
        statusText: $('rcTranslateStatusText'),
        listingTarget: $('rcV3ListingBody'),
        summaryTarget: $('rcReportBody'),
      });
    });
  }
  _setupPrintButton($('rcPrintBtn'));

  // screenClosing buttons (set up after closing screen shown — wired in startV3Polling)
  _setupPrintButton($('cPrintBtn'));
  // cTranslateBtn wired in startV3Polling once we have sessionId


  // Routing
  if (PR_FORCE_FRESH)             show('screenLanding');
  else if (PR_HAS_RECENT)         show('screenRecent');
  else if (PR_HAS_INPROGRESS)     show('screenResume');
  else                            show('screenLanding');

  // Landing → Consent
  if ($('goConsentBtn')) $('goConsentBtn').onclick = function() { show('screenConsent'); };
  if ($('backBtn'))       $('backBtn').onclick = function() { show('screenLanding'); };

  // Consent gating
  const checks = document.querySelectorAll('.consent-check');
  const beginBtn = $('beginBtn');
  function refreshGate() {
    const allChecked = Array.from(checks).every(function(c) { return c.checked; });
    if (beginBtn) {
      beginBtn.classList.toggle('opacity-40', !allChecked);
      beginBtn.classList.toggle('cursor-not-allowed', !allChecked);
    }
  }
  checks.forEach(function(c) { c.addEventListener('change', refreshGate); });

  if (beginBtn) beginBtn.onclick = function() {
    const flash = $('flashConsent');
    function flashErr(msg) {
      if (flash) { flash.textContent = msg; flash.classList.remove('hidden'); flash.scrollIntoView({behavior:'smooth', block:'center'}); }
    }
    const allChecked = Array.from(checks).every(function(c) { return c.checked; });
    if (!allChecked) { flashErr('Please tick all four boxes above to continue.'); return; }
    if (PR_BAL < 1000) { flashErr('Wallet balance is below ₹1000. Please top up to continue. (You will only be charged when your report is ready.)'); return; }
    startSession();
  };

  // Resume
  if ($('resumeBtn'))   $('resumeBtn').onclick   = function() { startSession(); };
  if ($('abandonBtn'))  $('abandonBtn').onclick  = function() {
    if (!confirm('Discard the paused reflection and start a fresh one?')) return;
    const fd = new FormData();
    fd.append('action', 'discard');  /* fresh-v1: explicit abandon */
    fd.append('csrf', CSRF);
    fetch('/parent-reflect-api.php', { method: 'POST', body: fd })
      .finally(function() { window.location.href = '/parent-reflect.php?fresh=1'; });
  };

  // Followup
  if ($('askMoreBtn')) $('askMoreBtn').onclick = function() {
    const sid = parseInt($('askMoreBtn').getAttribute('data-sid') || '0', 10);
    if (!sid) return;
    $('askMoreBtn').disabled = true;
    $('askMoreBtn').textContent = 'Opening…';
    startFollowup(sid);
  };

  // End early in interview
  if ($('iEndBtn')) $('iEndBtn').onclick = pauseAndExit;
  if ($('iFinishBtn')) $('iFinishBtn').onclick = finishNow;

  // Wire textarea once
  wireTextarea();

  // Wire mic (voice → textarea transcription)
  wireMic();

  // pr-no-autoabandon-v1: refresh/close NO LONGER cancels the session.
  // Only the explicit "⏸ Pause & resume later" button calls cancel.
  // Sessions left open stay 'in_progress' and resume seamlessly next visit.
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
