<?php
require_once __DIR__ . '/_common.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);
$assessment = start_or_resume_assessment($child['id'], 'diet', $band);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $diet_type = trim($_POST['diet_type'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $meals     = trim($_POST['meals'] ?? '');
    $picky     = $_POST['picky'] ?? '';
    $milk_ml   = (int)($_POST['milk_ml'] ?? 0);
    $junk_freq = $_POST['junk_freq'] ?? '';
    $water_l   = (float)($_POST['water_l'] ?? 0);
    $morbidity = trim($_POST['morbidity'] ?? '');
    $height_cm = (float)($_POST['height_cm'] ?? 0);
    $weight_kg = (float)($_POST['weight_kg'] ?? 0);
    $goal      = $_POST['goal'] ?? '';
    $budget    = $_POST['budget'] ?? '';

    $bmi = ($height_cm > 0 && $weight_kg > 0) ? round($weight_kg / pow($height_cm / 100, 2), 1) : null;

    $sys = "You are a paediatric nutritionist with experience in Indian families. "
         . "Tone: warm, practical, parent-friendly. Use familiar Indian foods (dal, roti, idli, poha, curd, dahi, paneer, eggs, fruit, dry-fruits). "
         . "Respect vegetarian / Jain / non-veg as stated. Address allergies and any morbidity. "
         . "If the child is underweight or overweight for age (rough WHO guides), call it out gently and adjust the plan.";

    $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs ($band).\n"
          . "Height: " . ($height_cm ?: 'n/a') . " cm. Weight: " . ($weight_kg ?: 'n/a') . " kg. BMI: " . ($bmi ?? 'n/a') . ".\n"
          . "Diet type: $diet_type. Allergies/intolerances: " . ($allergies ?: 'none reported') . ".\n"
          . "Typical day's meals: " . ($meals ?: 'not specified') . ".\n"
          . "Picky eater: $picky. Milk: $milk_ml ml/day. Water: $water_l L/day. Junk food: $junk_freq.\n"
          . "Existing health issues / morbidity: " . ($morbidity ?: 'none reported') . ".\n"
          . "Family goal: $goal. Budget: $budget.\n\n"
          . "Produce a 7-day Indian meal plan as a TABLE with columns Day | Breakfast | Mid-morning | Lunch | Evening | Dinner. "
          . "Then below the table give: 5 things to cut down, 5 things to add more of, signs to consult a paediatrician, "
          . "and one line for parents on portion size for this age. Keep total response under 700 words. Use plain text with simple table layout.";

    $plan = claude_chat($sys, [['role' => 'user', 'content' => $user]], 1800, 0.5);
    if ($plan === '') $plan = 'AI plan generation failed — please retry. Your inputs were saved.';

    $flags = [];
    if ($bmi !== null) {
        if (($band === 'child' || $band === 'preteen') && $bmi < 13) $flags[] = ['q' => 'Possibly underweight', 'a' => $bmi];
        if ($band === 'teen' && $bmi < 17) $flags[] = ['q' => 'Possibly underweight', 'a' => $bmi];
        if ($bmi > 25) $flags[] = ['q' => 'Possibly overweight', 'a' => $bmi];
    }

    finalize_assessment($assessment['id'], null, $band, $plan, $flags, ['inputs' => $_POST, 'bmi' => $bmi]);
    db()->prepare("INSERT INTO reports (child_id, ai_text, diet_text) VALUES (?, ?, ?)")
       ->execute([$child['id'], '', $plan]);

    header('Location: /child.php?id=' . (int)$child['id']);
    exit;
}

module_layout_open($child, 'Diet & nutrition advice');
?>
<p class="text-slate-600 mb-6 max-w-3xl es-bi"
   data-en="Tell us how your child eats today. We will generate a 7-day, age-tuned Indian meal plan that takes any allergies and existing health issues into account."
   data-hi="हमें बताएँ कि आपका बच्चा आज क्या खाता है। हम उम्र के अनुसार 7-दिन का भारतीय भोजन प्लान तैयार करेंगे, जिसमें एलर्जी और मौजूदा स्वास्थ्य समस्याओं का ध्यान रखा जाएगा।">
  Tell us how your child eats today. We will generate a 7-day, age-tuned Indian meal plan that takes any allergies and existing health issues into account.
</p>

<form method="post" class="space-y-4 max-w-3xl">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="cid"  value="<?= (int)$child['id'] ?>">

  <div class="grid sm:grid-cols-2 gap-4">
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Diet type" data-hi="आहार का प्रकार">Diet type</span>
      <select name="diet_type" required class="mt-1 w-full border-slate-200 rounded-lg">
        <option value="">— pick —</option>
        <option value="Vegetarian">Vegetarian / शाकाहारी</option>
        <option value="Vegetarian + egg">Vegetarian + egg / शाकाहारी + अंडा</option>
        <option value="Non-vegetarian">Non-vegetarian / मांसाहारी</option>
        <option value="Jain">Jain / जैन</option>
        <option value="Vegan">Vegan / पूर्ण शाकाहारी</option>
      </select>
    </label>
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Picky eater?" data-hi="नख़रे करता है?">Picky eater?</span>
      <select name="picky" class="mt-1 w-full border-slate-200 rounded-lg">
        <option value="No">No / नहीं</option>
        <option value="A little">A little / थोड़ा</option>
        <option value="Yes, very">Yes, very / हाँ, बहुत</option>
      </select>
    </label>
  </div>

  <div class="grid sm:grid-cols-3 gap-4">
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Height (cm)" data-hi="ऊँचाई (सेमी)">Height (cm)</span>
      <input type="number" name="height_cm" min="30" max="220" step="0.01" class="mt-1 w-full border-slate-200 rounded-lg">
    </label>
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Weight (kg)" data-hi="वज़न (किग्रा)">Weight (kg)</span>
      <input type="number" name="weight_kg" min="1" max="200" step="0.01" class="mt-1 w-full border-slate-200 rounded-lg">
    </label>
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Milk (ml/day)" data-hi="दूध (मिली/दिन)">Milk (ml/day)</span>
      <input type="number" name="milk_ml" min="0" max="3000" class="mt-1 w-full border-slate-200 rounded-lg">
    </label>
  </div>

  <div class="grid sm:grid-cols-2 gap-4">
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Water (litres/day)" data-hi="पानी (लीटर/दिन)">Water (litres/day)</span>
      <input type="number" name="water_l" min="0" max="6" step="0.1" class="mt-1 w-full border-slate-200 rounded-lg">
    </label>
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Junk food / packaged snacks" data-hi="जंक फ़ूड / पैकेट के स्नैक्स">Junk food / packaged snacks</span>
      <select name="junk_freq" class="mt-1 w-full border-slate-200 rounded-lg">
        <option value="Rarely">Rarely / बहुत कम</option>
        <option value="1-2 times/week">1-2 times/week / 1-2 बार हफ़्ते में</option>
        <option value="3-5 times/week">3-5 times/week / 3-5 बार हफ़्ते में</option>
        <option value="Daily">Daily / रोज़</option>
      </select>
    </label>
  </div>

  <label class="block bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
    <span class="text-sm font-medium text-slate-700 es-bi"
          data-en="Allergies / intolerances (e.g. milk, peanut, gluten)"
          data-hi="एलर्जी / असहिष्णुता (जैसे दूध, मूँगफली, ग्लूटेन)">Allergies / intolerances</span>
    <input type="text" name="allergies" class="mt-1 w-full border-slate-200 rounded-lg" placeholder="None / list them"
           data-i18n-placeholder-en="None / list them" data-i18n-placeholder-hi="कोई नहीं / सूची लिखें">
  </label>

  <label class="block bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
    <span class="text-sm font-medium text-slate-700 es-bi" data-en="Typical day's meals (free text)" data-hi="आम दिन का खाना (अपने शब्दों में)">Typical day's meals</span>
    <textarea name="meals" rows="3" class="mt-1 w-full border-slate-200 rounded-lg"
              placeholder="e.g. Breakfast: poha + milk. Lunch: dal-rice. Snack: biscuits. Dinner: roti-sabzi."
              data-i18n-placeholder-en="e.g. Breakfast: poha + milk. Lunch: dal-rice. Snack: biscuits. Dinner: roti-sabzi."
              data-i18n-placeholder-hi="जैसे नाश्ता: पोहा + दूध। दोपहर: दाल-चावल। शाम: बिस्किट। रात: रोटी-सब्ज़ी।"></textarea>
  </label>

  <label class="block bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
    <span class="text-sm font-medium text-slate-700 es-bi" data-en="Existing health issues / morbidity" data-hi="मौजूदा स्वास्थ्य समस्याएँ">Existing health issues / morbidity</span>
    <input type="text" name="morbidity" class="mt-1 w-full border-slate-200 rounded-lg"
           placeholder="e.g. asthma, anaemia, diabetes, ADHD on meds, none"
           data-i18n-placeholder-en="e.g. asthma, anaemia, diabetes, ADHD on meds, none"
           data-i18n-placeholder-hi="जैसे दमा, ख़ून की कमी, डायबिटीज़, ADHD दवा पर, कोई नहीं">
  </label>

  <div class="grid sm:grid-cols-2 gap-4">
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Family goal" data-hi="परिवार का लक्ष्य">Family goal</span>
      <select name="goal" class="mt-1 w-full border-slate-200 rounded-lg">
        <option value="Healthy growth (general)">Healthy growth / स्वस्थ विकास</option>
        <option value="Gain weight / build appetite">Gain weight / वज़न बढ़ाना</option>
        <option value="Lose weight">Lose weight / वज़न घटाना</option>
        <option value="Boost immunity">Boost immunity / प्रतिरक्षा बढ़ाना</option>
        <option value="Improve focus / energy">Improve focus / ध्यान-ऊर्जा बढ़ाना</option>
      </select>
    </label>
    <label class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
      <span class="text-sm font-medium text-slate-700 es-bi" data-en="Budget" data-hi="बजट">Budget</span>
      <select name="budget" class="mt-1 w-full border-slate-200 rounded-lg">
        <option value="Tight">Tight / सीमित</option>
        <option value="Moderate">Moderate / मध्यम</option>
        <option value="Comfortable">Comfortable / आरामदायक</option>
      </select>
    </label>
  </div>

  <button class="brand-grad text-white px-6 py-3 rounded-lg font-medium es-bi"
          data-en="Generate diet plan" data-hi="आहार योजना बनाएँ">
    Generate diet plan
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
      const ta = document.createElement('textarea');
      ta.innerHTML = target;
      el.textContent = ta.value;
    });
    document.querySelectorAll('[data-i18n-placeholder-en]').forEach(el => {
      el.placeholder = (lang === 'hi') ? (el.dataset.i18nPlaceholderHi || el.dataset.i18nPlaceholderEn) : el.dataset.i18nPlaceholderEn;
    });
  }
  applyBi();
  document.querySelectorAll('[data-set-lang]').forEach(b => {
    b.addEventListener('click', () => setTimeout(applyBi, 50));
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
