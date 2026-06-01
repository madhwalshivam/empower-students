<?php
require_once __DIR__ . '/_common.php';

/**
 * Render a parent/teacher questionnaire — bilingual (English + Hindi).
 *
 * @param array $config = [
 *   'module_key'    => 'health',
 *   'title'         => 'Health screening',
 *   'title_hi'      => 'स्वास्थ्य जाँच',                        // optional
 *   'intro'         => 'short paragraph',
 *   'intro_hi'      => 'हिन्दी में परिचय',                        // optional
 *   'questions'     => [
 *       ['q' => '…', 'q_hi' => '…', 'type' => 'likert|yesno|number|text', ...]
 *   ],
 *   'ai_system'     => 'system prompt',
 *   'ai_user_tail'  => 'extra instruction tail to AI',
 * ]
 */
function run_questionnaire($child, $config) {
    $age   = calc_age_years($child['dob']);
    $band  = age_band($age);
    $a     = start_or_resume_assessment($child['id'], $config['module_key'], $band);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
        $items = []; $flags = []; $score = 0; $considered = 0;
        foreach ($config['questions'] as $i => $q) {
            $val = $_POST['q'][$i] ?? '';
            $is_concern = false;
            if (isset($q['concern_if'])) {
                $c = $q['concern_if'];
                if ($c === 'yes' && $val === 'yes') $is_concern = true;
                elseif ($c === 'no'  && $val === 'no')  $is_concern = true;
                elseif (is_string($c) && preg_match('/^>=(\d+(?:\.\d+)?)$/', $c, $m) && is_numeric($val) && $val >= (float)$m[1]) $is_concern = true;
                elseif (is_string($c) && preg_match('/^<=(\d+(?:\.\d+)?)$/', $c, $m) && is_numeric($val) && $val <= (float)$m[1]) $is_concern = true;
            }
            $items[] = ['q' => $q['q'], 'type' => $q['type'], 'a' => $val, 'concern' => $is_concern];
            if ($is_concern) {
                $flags[] = ['q' => $q['q'], 'a' => $val, 'critical' => !empty($q['critical'])];
            }
            if (in_array($q['type'], ['likert','number'], true) && is_numeric($val)) {
                $score += (float)$val; $considered++;
            } elseif ($q['type'] === 'yesno') {
                if ($val === 'yes' && (($q['concern_if'] ?? null) !== 'yes')) $score++;
                if ($val === 'no'  && (($q['concern_if'] ?? null) !== 'no'))  $score++;
                $considered++;
            }
        }
        $pct = $considered ? round($score * 100 / max(1, $considered * (max(1, $config['max_per'] ?? 1))), 1) : null;

        $sys  = $config['ai_system'];
        $user = "Child: " . $child['name'] . ", age " . round((float)$age, 1) . " yrs, band " . $band . ".\n"
              . "Module: " . $config['title'] . "\n"
              . "Responses:\n" . json_encode($items, JSON_UNESCAPED_UNICODE)
              . "\n\n" . ($config['ai_user_tail'] ?? '');
        $summary = claude_chat($sys, [['role' => 'user', 'content' => $user]], 600, 0.4);
        if ($summary === '') $summary = 'Saved.';

        finalize_assessment($a['id'], $pct, $band, $summary, $flags, $items);
        header('Location: /child.php?id=' . (int)$child['id']);
        exit;
    }

    module_layout_open($child, $config['title']);

    // ─── Intro paragraph (bilingual if intro_hi provided) ───
    $intro_en = $config['intro'] ?? '';
    $intro_hi = $config['intro_hi'] ?? '';
    if ($intro_hi !== '') {
        echo '<p class="text-slate-600 mb-6 max-w-3xl es-bi" '
           . 'data-en="' . e($intro_en) . '" '
           . 'data-hi="' . e($intro_hi) . '">'
           . $intro_en . '</p>';
    } else {
        echo '<p class="text-slate-600 mb-6 max-w-3xl">' . $intro_en . '</p>';
    }

    echo '<form method="post" class="space-y-4">';
    echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="cid" value="' . (int)$child['id'] . '">';

    foreach ($config['questions'] as $i => $q) {
        $q_en  = $q['q'];
        $q_hi  = $q['q_hi'] ?? '';
        $sc_en = $q['scale']    ?? '';
        $sc_hi = $q['scale_hi'] ?? '';

        echo '<div class="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">';

        // Question prompt
        echo '<p class="font-medium mb-3">' . ($i + 1) . '. ';
        if ($q_hi !== '') {
            echo '<span class="es-bi" data-en="' . e($q_en) . '" data-hi="' . e($q_hi) . '">' . $q_en . '</span>';
        } else {
            echo $q_en;
        }
        echo '</p>';

        if ($q['type'] === 'likert') {
            $min = $q['min'] ?? 0; $max = $q['max'] ?? 10;
            echo '<div class="flex flex-wrap gap-2">';
            for ($v = $min; $v <= $max; $v++) {
                echo '<label class="cursor-pointer">'
                   . '<input type="radio" name="q[' . $i . ']" value="' . $v . '" class="peer sr-only" required>'
                   . '<span class="block w-10 h-10 leading-10 text-center rounded-lg bg-slate-100 peer-checked:brand-grad peer-checked:text-white">' . $v . '</span>'
                   . '</label>';
            }
            echo '</div>';
            if ($sc_en !== '') {
                if ($sc_hi !== '') {
                    echo '<p class="text-xs text-slate-500 mt-2 es-bi" '
                       . 'data-en="' . e($sc_en) . '" data-hi="' . e($sc_hi) . '">' . e($sc_en) . '</p>';
                } else {
                    echo '<p class="text-xs text-slate-500 mt-2">' . e($sc_en) . '</p>';
                }
            }
        } elseif ($q['type'] === 'yesno') {
            $opts = [
                ['v' => 'yes',    'en' => 'Yes',      'hi' => 'हाँ',     'c' => 'emerald-500'],
                ['v' => 'no',     'en' => 'No',       'hi' => 'नहीं',     'c' => 'rose-500'],
                ['v' => 'unsure', 'en' => 'Not sure', 'hi' => 'पता नहीं', 'c' => 'slate-500'],
            ];
            echo '<div class="flex gap-2">';
            foreach ($opts as $o) {
                echo '<label class="flex-1 cursor-pointer">'
                   . '<input type="radio" name="q[' . $i . ']" value="' . $o['v'] . '" class="peer sr-only" required>'
                   . '<span class="block text-center py-2 rounded-lg bg-slate-100 peer-checked:bg-' . $o['c'] . ' peer-checked:text-white es-bi" '
                   . 'data-en="' . e($o['en']) . '" data-hi="' . e($o['hi']) . '">' . e($o['en']) . '</span>'
                   . '</label>';
            }
            echo '</div>';
        } elseif ($q['type'] === 'number') {
            echo '<div class="flex items-center gap-2">';
            echo '<input type="number" name="q[' . $i . ']" required'
                . (isset($q['min']) ? ' min="' . (float)$q['min'] . '"' : '')
                . (isset($q['max']) ? ' max="' . (float)$q['max'] . '"' : '')
                . (isset($q['step']) ? ' step="' . e($q['step']) . '"' : ' step="0.01"')
                . ' class="w-32 border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">';
            $unit_en = $q['unit']    ?? '';
            $unit_hi = $q['unit_hi'] ?? '';
            if ($unit_en !== '') {
                if ($unit_hi !== '') {
                    echo '<span class="text-sm text-slate-500 es-bi" data-en="' . e($unit_en) . '" data-hi="' . e($unit_hi) . '">' . e($unit_en) . '</span>';
                } else {
                    echo '<span class="text-sm text-slate-500">' . e($unit_en) . '</span>';
                }
            }
            echo '</div>';
        } else { // text
            $ph_en = $q['placeholder']    ?? '';
            $ph_hi = $q['placeholder_hi'] ?? '';
            $ph_attrs = '';
            if ($ph_en !== '' && $ph_hi !== '') {
                $ph_attrs = ' placeholder="' . e($ph_en) . '" data-i18n-placeholder-en="' . e($ph_en) . '" data-i18n-placeholder-hi="' . e($ph_hi) . '"';
            } elseif ($ph_en !== '') {
                $ph_attrs = ' placeholder="' . e($ph_en) . '"';
            }
            echo '<textarea name="q[' . $i . ']" rows="2"' . $ph_attrs . ' class="w-full border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none"></textarea>';
        }
        echo '</div>';
    }

    echo '<button class="w-full brand-grad text-white font-semibold py-3 rounded-xl hover:opacity-90 mt-4 es-bi" '
       . 'data-en="Submit &amp; analyse" data-hi="जमा करें और विश्लेषण करें">Submit &amp; analyse</button>';
    echo '</form>';

    // ─── Bilingual swap script (idempotent — safe even if header already has its own) ───
    echo <<<'JS'
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
JS;

    module_layout_close();
}
