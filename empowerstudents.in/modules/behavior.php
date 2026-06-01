<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'behavior', $band);

/**
 * Age-banded behaviour question banks. All answered by the parent.
 * Each question: q (English) + q_hi (Hindi) + concern_if (yes/no) + optional critical flag.
 * Scoring: positive answers about typical-behaviours => +1; concerning => 0 and recorded as flag.
 */
$banks = [
    'infant' => [    // 0 - <2 yrs : subtle ASD / learning red-flags (M-CHAT-R inspired, paraphrased)
        ['q' => 'Does the child smile back when you smile at them?',
         'q_hi' => 'क्या बच्चा आपके मुस्कुराने पर वापस मुस्कुराता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child look at you when you call their name?',
         'q_hi' => 'क्या बच्चा अपने नाम पर आपकी ओर देखता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child point with one finger to ask for or show interest in things?',
         'q_hi' => 'क्या बच्चा एक उंगली से चीज़ों की ओर इशारा करता है (माँगने या रुचि दिखाने के लिए)?',
         'concern_if' => 'no'],
        ['q' => 'Does the child make eye contact during feeding or play?',
         'q_hi' => 'क्या बच्चा खिलाते या खेलते समय आँखें मिलाता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child babble (mama, baba, da-da) by 12 months?',
         'q_hi' => 'क्या बच्चा 12 महीने तक बबलाने लगा है (मामा, बाबा, दा-दा)?',
         'concern_if' => 'no'],
        ['q' => 'Does the child show interest in other children?',
         'q_hi' => 'क्या बच्चा दूसरे बच्चों में रुचि दिखाता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child enjoy being cuddled or held?',
         'q_hi' => 'क्या बच्चा गोद में लेने या पुचकारने पर खुश होता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child respond differently to familiar vs unfamiliar voices?',
         'q_hi' => 'क्या बच्चा जानी-पहचानी और अनजान आवाज़ों पर अलग-अलग प्रतिक्रिया देता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child make repetitive movements (hand-flapping, rocking) for long periods?',
         'q_hi' => 'क्या बच्चा लंबे समय तक एक जैसी हरकतें करता है (हाथ हिलाना, झूलना)?',
         'concern_if' => 'yes'],
        ['q' => 'Is the child unusually upset by ordinary sounds, lights, or textures?',
         'q_hi' => 'क्या बच्चा सामान्य आवाज़ों, रोशनी या स्पर्श से असामान्य रूप से परेशान हो जाता है?',
         'concern_if' => 'yes'],
        ['q' => 'Has the child lost any words or skills they had earlier?',
         'q_hi' => 'क्या बच्चे ने पहले सीखे शब्द या कौशल खो दिए हैं?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child stare at moving fans or spinning objects unusually long?',
         'q_hi' => 'क्या बच्चा घूमते पंखों या घूमती चीज़ों को असामान्य रूप से लंबे समय तक घूरता है?',
         'concern_if' => 'yes'],
    ],
    'toddler' => [   // 2-5 years
        ['q' => 'Does the child play simple pretend games (feeding a doll, talking on phone)?',
         'q_hi' => 'क्या बच्चा सरल नाटक खेलता है (गुड़िया को खिलाना, फ़ोन पर बात करना)?',
         'concern_if' => 'no'],
        ['q' => 'Does the child use 2-3 word phrases to ask for things?',
         'q_hi' => 'क्या बच्चा माँगने के लिए 2-3 शब्दों के वाक्य बोलता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child follow simple instructions like "give me the ball"?',
         'q_hi' => 'क्या बच्चा सरल निर्देशों का पालन करता है, जैसे "गेंद दो"?',
         'concern_if' => 'no'],
        ['q' => 'Does the child enjoy playing with other children of similar age?',
         'q_hi' => 'क्या बच्चा अपनी ही उम्र के अन्य बच्चों के साथ खेलना पसंद करता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child point to body parts when asked?',
         'q_hi' => 'क्या बच्चा पूछने पर शरीर के अंगों की ओर इशारा करता है?',
         'concern_if' => 'no'],
        ['q' => 'Are tantrums extreme or last more than 15 minutes?',
         'q_hi' => 'क्या ग़ुस्सा बहुत तेज़ होता है या 15 मिनट से ज़्यादा चलता है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child line up toys repeatedly instead of playing with them?',
         'q_hi' => 'क्या बच्चा खिलौनों से खेलने के बजाय बार-बार उन्हें कतार में लगाता है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child avoid eye contact when spoken to?',
         'q_hi' => 'क्या बच्चा बात करते समय आँखें मिलाने से बचता है?',
         'concern_if' => 'yes'],
        ['q' => 'Is the child a very fussy eater (less than 5 foods accepted)?',
         'q_hi' => 'क्या बच्चा बहुत नख़रे करता है खाने में (5 से कम चीज़ें खाता है)?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child have very poor sleep (<8 hrs at night)?',
         'q_hi' => 'क्या बच्चा बहुत कम सोता है (रात में 8 घंटे से कम)?',
         'concern_if' => 'yes'],
    ],
    'child' => [     // 5-10
        ['q' => 'Does the child sit through a 20-minute task without leaving the chair?',
         'q_hi' => 'क्या बच्चा कुर्सी से उठे बिना 20 मिनट तक काम कर सकता है?',
         'concern_if' => 'no'],
        ['q' => 'Can the child make and keep a friend?',
         'q_hi' => 'क्या बच्चा दोस्त बना और निभा सकता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child follow class rules at school?',
         'q_hi' => 'क्या बच्चा स्कूल में कक्षा के नियमों का पालन करता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child express feelings in words instead of only behaviours?',
         'q_hi' => 'क्या बच्चा भावनाओं को सिर्फ़ व्यवहार से नहीं, शब्दों में भी बताता है?',
         'concern_if' => 'no'],
        ['q' => 'Is the child often described as restless or "always on the go"?',
         'q_hi' => 'क्या बच्चे को अक्सर बेचैन या "हमेशा दौड़ता रहने वाला" कहा जाता है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child interrupt or have trouble waiting their turn?',
         'q_hi' => 'क्या बच्चा बीच में टोकता है या अपनी बारी का इंतज़ार नहीं कर पाता?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child avoid reading or writing tasks more than peers?',
         'q_hi' => 'क्या बच्चा साथियों की तुलना में पढ़ने-लिखने के काम से ज़्यादा बचता है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child have frequent meltdowns over small changes in routine?',
         'q_hi' => 'क्या रोज़ की दिनचर्या में छोटे बदलावों पर बच्चे का तेज़ ग़ुस्सा फूटता है?',
         'concern_if' => 'yes'],
        ['q' => 'Has a teacher raised concerns about attention or behaviour?',
         'q_hi' => 'क्या किसी शिक्षक ने ध्यान या व्यवहार को लेकर चिंता जताई है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child complain of stomach-aches or headaches before school?',
         'q_hi' => 'क्या बच्चा स्कूल से पहले पेट दर्द या सिर दर्द की शिकायत करता है?',
         'concern_if' => 'yes'],
    ],
    'preteen' => [   // 10-13
        ['q' => 'Does the child manage homework with limited reminders?',
         'q_hi' => 'क्या बच्चा बहुत कम याद दिलाने पर ही गृहकार्य कर लेता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child have at least one good friend they meet regularly?',
         'q_hi' => 'क्या बच्चे का कम से कम एक अच्छा दोस्त है जिससे वह नियमित मिलता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the child speak openly about things bothering them?',
         'q_hi' => 'क्या बच्चा परेशानियों के बारे में खुलकर बात करता है?',
         'concern_if' => 'no'],
        ['q' => 'Has the child shown sudden mood swings or withdrawal in the past month?',
         'q_hi' => 'क्या पिछले महीने में बच्चे का मूड अचानक बदला है या वह अकेला रहने लगा है?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child use screens >3 hours a day for non-school activities?',
         'q_hi' => 'क्या बच्चा स्कूल के अलावा रोज़ 3 घंटे से ज़्यादा स्क्रीन देखता है?',
         'concern_if' => 'yes'],
        ['q' => 'Have school grades dropped recently without obvious cause?',
         'q_hi' => 'क्या हाल ही में बिना किसी स्पष्ट कारण के स्कूल के अंक गिरे हैं?',
         'concern_if' => 'yes'],
        ['q' => 'Does the child often appear sad, tired or uninterested?',
         'q_hi' => 'क्या बच्चा अक्सर उदास, थका या उदासीन दिखता है?',
         'concern_if' => 'yes'],
        ['q' => 'Is there bullying (giving or receiving) reported?',
         'q_hi' => 'क्या किसी बदमाशी (करने या सहने) की कोई जानकारी है?',
         'concern_if' => 'yes'],
    ],
    'teen' => [      // 13-18
        ['q' => 'Does the teen have at least one trusted adult they confide in?',
         'q_hi' => 'क्या किशोर के पास कम से कम एक भरोसेमंद बड़ा है जिससे वह अपनी बात कह सकता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the teen sleep 7-9 hours most nights?',
         'q_hi' => 'क्या किशोर ज़्यादातर रातों में 7-9 घंटे सोता है?',
         'concern_if' => 'no'],
        ['q' => 'Does the teen have hobbies / interests outside academics?',
         'q_hi' => 'क्या किशोर के पास पढ़ाई के अलावा कोई शौक़ या रुचि है?',
         'concern_if' => 'no'],
        ['q' => 'Has the teen expressed hopelessness or self-harm thoughts in the past month?',
         'q_hi' => 'क्या किशोर ने पिछले महीने में निराशा या ख़ुद को नुक़सान पहुँचाने के विचार जताए हैं?',
         'concern_if' => 'yes', 'critical' => true],
        ['q' => 'Has the teen withdrawn from previously enjoyed activities?',
         'q_hi' => 'क्या किशोर ने पहले की पसंदीदा गतिविधियों से दूरी बना ली है?',
         'concern_if' => 'yes'],
        ['q' => 'Are screen / social-media hours interfering with sleep or studies?',
         'q_hi' => 'क्या स्क्रीन या सोशल मीडिया का समय नींद या पढ़ाई में बाधा डाल रहा है?',
         'concern_if' => 'yes'],
        ['q' => 'Are eating patterns very erratic or restricted?',
         'q_hi' => 'क्या खाने का तरीक़ा बहुत अनियमित या सीमित है?',
         'concern_if' => 'yes'],
        ['q' => 'Has substance use been suspected?',
         'q_hi' => 'क्या किसी नशे/मादक पदार्थ के सेवन का संदेह है?',
         'concern_if' => 'yes', 'critical' => true],
    ],
];
$bank = $banks[$band] ?? $banks['child'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $answers = $_POST['ans'] ?? [];
    $items = []; $flags = []; $score = 0;
    foreach ($bank as $i => $q) {
        $a = strtolower(trim($answers[$i] ?? ''));
        if ($a !== 'yes' && $a !== 'no') $a = 'unsure';
        $is_concern = ($a === $q['concern_if']);
        $items[] = ['q' => $q['q'], 'a' => $a, 'concern' => $is_concern];
        if (!$is_concern && $a !== 'unsure') $score++;
        if ($is_concern) {
            $flags[] = ['q' => $q['q'], 'critical' => !empty($q['critical'])];
        }
    }
    $pct = count($bank) > 0 ? round($score * 100 / count($bank), 1) : 0;

    $sys = "You are a paediatric behaviour clinician. Be warm, plain-language, non-alarming. "
         . "Never diagnose. Suggest specialist consultation when red-flags warrant it.";
    $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs, age-band " . $band . "."
          . " Behaviour questionnaire results (parent-reported):\n"
          . json_encode($items, JSON_UNESCAPED_UNICODE)
          . "\n\nWrite 5-7 sentences: 1) overall picture, 2) 1-2 strengths to build on, 3) 1-3 specific concerns if any, "
          . "4) clear next-step suggestion (e.g. paediatrician / OT / speech therapist / psychologist). "
          . "Avoid jargon. If any red-flag is marked critical, urge a same-week professional consultation.";
    $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 600, 0.4);
    if ($summary === '') {
        $summary = 'Saved. Detailed AI summary will appear in your report.';
    }
    finalize_assessment($assessment['id'], $pct, $band, $summary, $flags, $items);
    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Behaviour assessment');

$intro_en = 'Please answer as honestly as you can about <strong>' . e($child['name']) . '</strong> over the last '
          . ($band === 'infant' ? '4 weeks' : '3 months') . '. There are no right or wrong answers.';
$intro_hi = 'पिछले ' . ($band === 'infant' ? '4 हफ़्तों' : '3 महीनों')
          . ' के बारे में <strong>' . e($child['name']) . '</strong> के लिए जितना ईमानदारी से हो सके उत्तर दें। कोई सही या ग़लत उत्तर नहीं हैं।';
?>
<p class="text-slate-600 mb-6 max-w-3xl es-bi"
   data-en="<?= e($intro_en) ?>" data-hi="<?= e($intro_hi) ?>">
  <?= $intro_en ?>
</p>

<?php if ($band === 'infant'): ?>
<p class="block mt-2 mb-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 p-2 rounded es-bi"
   data-en="Under 2 years &mdash; we look for very subtle markers so early signs are not missed. A &quot;concern&quot; answer is just a flag for review, not a diagnosis."
   data-hi="2 वर्ष से कम — हम बहुत सूक्ष्म संकेत देखते हैं ताकि शुरुआती लक्षण छूट न जाएँ। &quot;चिंता&quot; का उत्तर सिर्फ़ समीक्षा के लिए है, निदान नहीं।">
  Under 2 years — we look for very subtle markers so early signs are not missed. A "concern" answer is just a flag for review, not a diagnosis.
</p>
<?php endif; ?>

<form method="post" class="space-y-4">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid" value="<?= (int)$child['id'] ?>">
  <?php foreach ($bank as $i => $q): ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <p class="font-medium mb-3">
        <?= ($i + 1) ?>.
        <span class="es-bi" data-en="<?= e($q['q']) ?>" data-hi="<?= e($q['q_hi']) ?>"><?= e($q['q']) ?></span>
      </p>
      <div class="flex gap-2">
        <label class="flex-1 cursor-pointer">
          <input type="radio" name="ans[<?= $i ?>]" value="yes" class="peer sr-only" required>
          <span class="block text-center py-2 rounded-lg bg-slate-100 peer-checked:bg-emerald-500 peer-checked:text-white es-bi"
                data-en="Yes" data-hi="हाँ">Yes</span>
        </label>
        <label class="flex-1 cursor-pointer">
          <input type="radio" name="ans[<?= $i ?>]" value="no" class="peer sr-only">
          <span class="block text-center py-2 rounded-lg bg-slate-100 peer-checked:bg-rose-500 peer-checked:text-white es-bi"
                data-en="No" data-hi="नहीं">No</span>
        </label>
        <label class="flex-1 cursor-pointer">
          <input type="radio" name="ans[<?= $i ?>]" value="unsure" class="peer sr-only">
          <span class="block text-center py-2 rounded-lg bg-slate-100 peer-checked:bg-slate-500 peer-checked:text-white es-bi"
                data-en="Not sure" data-hi="पता नहीं">Not sure</span>
        </label>
      </div>
    </div>
  <?php endforeach; ?>
  <button class="w-full brand-grad text-white font-semibold py-3 rounded-xl hover:opacity-90 mt-4 es-bi"
          data-en="Submit &amp; analyse" data-hi="जमा करें और विश्लेषण करें">
    Submit &amp; analyse
  </button>
</form>

<script>
(function () {
  function getLang() { try { return localStorage.getItem('es_lang') || 'en'; } catch(_){ return 'en'; } }
  function applyBi() {
    const lang = getLang();
    document.querySelectorAll('.es-bi').forEach(el => {
      const en = el.dataset.en, hi = el.dataset.hi;
      const target = (lang === 'hi' && hi) ? hi : (en || el.innerHTML);
      // Decode HTML entities so things like &mdash; render as —
      const ta = document.createElement('textarea');
      ta.innerHTML = target;
      el.innerHTML = ta.value;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>

<?php module_layout_close(); ?>
