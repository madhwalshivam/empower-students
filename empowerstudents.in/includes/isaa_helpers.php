<?php
/**
 * includes/isaa_helpers.php
 *
 * Pure logic for the ISAA tool — scoring, classification, disability percentage,
 * domain breakdown, and AI advice generation.
 *
 * Source: ISAA Test Manual (NIMH, Govt. of India). See test manual for the
 * definitive cut-offs. Numbers below are taken verbatim from the manual.
 */

require_once __DIR__ . '/claude.php';

// ─────────────────────────────────────────────────────────────
// Domain map: 40 items grouped into 6 domains.
// ─────────────────────────────────────────────────────────────
function isaa_domain_definitions(): array {
    return [
        1 => ['label' => 'Social Relationship and Reciprocity',     'items' => range(1, 9),   'max' => 9 * 5],
        2 => ['label' => 'Emotional Responsiveness',                 'items' => range(10, 14), 'max' => 5 * 5],
        3 => ['label' => 'Speech-Language and Communication',        'items' => range(15, 23), 'max' => 9 * 5],
        4 => ['label' => 'Behaviour Patterns',                       'items' => range(24, 30), 'max' => 7 * 5],
        5 => ['label' => 'Sensory Aspects',                          'items' => range(31, 36), 'max' => 6 * 5],
        6 => ['label' => 'Cognitive Component',                      'items' => range(37, 40), 'max' => 4 * 5],
    ];
}

/**
 * Classify a total ISAA score into a category.
 *   < 70    → 'normal'
 *   70-106  → 'mild'
 *   107-153 → 'moderate'
 *   > 153   → 'severe'
 */
function isaa_classify(int $total): string {
    if ($total < 70)   return 'normal';
    if ($total <= 106) return 'mild';
    if ($total <= 153) return 'moderate';
    return 'severe';
}

/** Human label for a category. */
function isaa_category_label(string $category): string {
    switch ($category) {
        case 'normal':   return 'Non-autistic / Within normal range';
        case 'mild':     return 'Mild Autism';
        case 'moderate': return 'Moderate Autism';
        case 'severe':   return 'Severe Autism';
        default:         return 'Unclassified';
    }
}

/**
 * Disability percentage as per the ISAA manual scoring table.
 * Ranges:
 *   ≤ 70           → 40%
 *   71-88          → 50%
 *   89-105         → 60%
 *   106-123        → 70%
 *   124-140        → 80%
 *   141-158        → 90%
 *   > 158          → 100%
 */
function isaa_disability_pct(int $total): int {
    if ($total <= 70)  return 40;
    if ($total <= 88)  return 50;
    if ($total <= 105) return 60;
    if ($total <= 123) return 70;
    if ($total <= 140) return 80;
    if ($total <= 158) return 90;
    return 100;
}

/**
 * Compute domain scores from a full set of responses.
 * $responses is array indexed by item_no (1..40) with score values 1..5.
 * Returns ['total' => int, 'domains' => [domain_no => ['raw' => int, 'max' => int, 'pct' => int, 'label' => str]]].
 */
function isaa_compute_scores(array $responses): array {
    $domains = isaa_domain_definitions();
    $out_domains = [];
    $total = 0;

    foreach ($domains as $dno => $d) {
        $raw = 0;
        foreach ($d['items'] as $item_no) {
            $score = isset($responses[$item_no]) ? (int)$responses[$item_no] : 0;
            $score = max(1, min(5, $score));
            $raw  += $score;
        }
        $pct = $d['max'] > 0 ? (int) round($raw * 100 / $d['max']) : 0;
        $out_domains[$dno] = [
            'raw'   => $raw,
            'max'   => $d['max'],
            'pct'   => $pct,
            'label' => $d['label'],
        ];
        $total += $raw;
    }

    return ['total' => $total, 'domains' => $out_domains];
}

/**
 * Identify items where the partner scored 4 or 5 ("mostly" / "always") —
 * these are the high-concern behaviours that the AI advice should target.
 *
 * Returns array of [item_no, domain_no, item_label, score].
 */
function isaa_high_concern_items(array $responses): array {
    $st = db()->prepare("SELECT item_no, domain_no, item_label FROM isaa_questions ORDER BY item_no ASC");
    $st->execute();
    $questions = $st->fetchAll();

    $out = [];
    foreach ($questions as $q) {
        $score = isset($responses[(int)$q['item_no']]) ? (int)$responses[(int)$q['item_no']] : 0;
        if ($score >= 4) {
            $out[] = [
                'item_no'    => (int)$q['item_no'],
                'domain_no'  => (int)$q['domain_no'],
                'item_label' => (string)$q['item_label'],
                'score'      => $score,
            ];
        }
    }
    return $out;
}

/**
 * Generate AI advice for the parents based on the assessment results.
 *
 * Returns array ['summary_md' => str, 'advice_md' => str] — both Markdown.
 * Returns nulls if Claude call fails.
 */
function isaa_generate_report(array $child, int $total_score, string $category, int $disability_pct, array $domain_scores, array $high_concern_items): array {
    $age_yrs = round((float)calc_age_years($child['dob']), 1);

    // Build the high-concern listing for the prompt
    $hc_lines = [];
    foreach ($high_concern_items as $hc) {
        $score_label = $hc['score'] === 4 ? 'Mostly (61-80%)' : 'Always (81-100%)';
        $hc_lines[] = "  - Item {$hc['item_no']} ({$hc['item_label']}) — {$score_label}";
    }
    $hc_text = !empty($hc_lines) ? implode("\n", $hc_lines) : "  (No items rated 4 or 5.)";

    // Domain breakdown for the prompt
    $domain_lines = [];
    foreach ($domain_scores as $dno => $d) {
        $domain_lines[] = "  - Domain {$dno} ({$d['label']}): {$d['raw']}/{$d['max']} ({$d['pct']}%)";
    }
    $domain_text = implode("\n", $domain_lines);

    $category_label = isaa_category_label($category);

    $sys = "You are a senior child development specialist at EmpowerStudents — a clinical service "
         . "that provides ISAA assessments AND professional therapy programs (Occupational Therapy, "
         . "Speech Therapy, behaviour intervention, learning support) to children with developmental needs.\n\n"
         . "You're writing the report a parent reads after their child's ISAA assessment. The parent is "
         . "anxious; reassure them that they're NOT alone — our team will lead the work. Position therapy "
         . "as something we do for the child (in person at our centre, or via video sessions), with parents "
         . "playing a small daily supportive role at home (5-10 minutes), not the primary teaching role.\n\n"
         . "Tone: empathetic, expert, reassuring, action-oriented. Avoid alarmist language. ISAA is an "
         . "assessment aid that supplements (not replaces) formal medical diagnosis.\n\n"
         . "Produce TWO outputs in JSON:\n\n"
         . "1. summary_md — 2-3 short paragraphs:\n"
         . "   - First paragraph: result honestly stated + reassurance that early structured intervention helps significantly\n"
         . "   - Second paragraph: areas of concern + areas of strength (if any)\n"
         . "   - Final paragraph: clearly invite the parent to start therapy with us. Mention that our team will "
         . "design a personalised therapy plan after a one-time consultation. Mention follow-up with a paediatric "
         . "neurologist or developmental specialist for formal diagnosis.\n\n"
         . "2. advice_md — Therapy plan recommendations. Cover ONLY domains where the child scored 50%+ of max, "
         . "OR where individual items scored 4 or 5 (high concern). For each such domain, structure as:\n\n"
         . "   ## [Domain N: Domain Name]\n"
         . "   **What we'll work on:** [1 sentence describing the therapy goal in clear Indian-parent-friendly language]\n"
         . "   **Recommended therapy:** [Be SPECIFIC: which therapy type (OT / ST / behaviour therapy / social skills group / sensory integration), "
         . "frequency e.g. '2 sessions/week of speech therapy', mode 'in-person at our centre OR via video', "
         . "duration e.g. 'over 12-16 weeks initially']\n"
         . "   **What you can do at home (5 mins/day):** [ONE simple supportive activity for the parent — must be "
         . "tiny, not a teaching role, just supportive. E.g. 'When your child is calm, name 3 emotions you see in their "
         . "favourite cartoon character together'. Indian context.]\n"
         . "   **What to expect:** [Realistic timeline — e.g. '6-8 weeks for first visible changes; meaningful "
         . "improvement over 4-6 months']\n\n"
         . "End advice_md with a closing paragraph: 'Our team is ready to begin. Book a one-time consultation "
         . "with EmpowerStudents to design your child's personalised therapy plan — we will handle the rest.'\n\n"
         . "CRITICAL FORMATTING RULES:\n"
         . "- Output JSON only, no preamble, no markdown code fences.\n"
         . "- No reasoning trace, no 'wait let me reconsider', etc. Final clean text only.\n"
         . "- Do NOT label the child definitively as 'autistic'. Use phrasing like 'shows characteristics consistent with...'.\n"
         . "- Keep advice_md under 700 words total. summary_md under 220 words.\n"
         . "- Use Indian English. Refer to therapy modes as 'in-person sessions at our centre' or 'video sessions'.\n"
         . "- Do NOT mention specific prices in this report — pricing handled separately.\n";

    $user = "Child: {$child['name']}, age {$age_yrs} years"
          . (!empty($child['gender']) ? ", " . $child['gender'] : '')
          . (!empty($child['mother_tongue']) ? ", mother tongue: " . $child['mother_tongue'] : '')
          . "\n\n"
          . "Assessment results:\n"
          . "  Total ISAA score: {$total_score}/200\n"
          . "  Classification: {$category_label}\n"
          . "  Disability percentage (per ISAA scoring table): {$disability_pct}%\n\n"
          . "Domain breakdown:\n{$domain_text}\n\n"
          . "Items rated 4 (Mostly) or 5 (Always) — high concern behaviours:\n{$hc_text}\n\n"
          . "Output JSON now: { \"summary_md\": \"...\", \"advice_md\": \"...\" }";

    $j = claude_json($sys, $user, 2200, 0.5);
    if (!$j || !isset($j['summary_md']) || !isset($j['advice_md'])) {
        return ['summary_md' => null, 'advice_md' => null];
    }

    // Sanitize — strip any reasoning-trace markers if leaked
    $strip = function($s) {
        $s = preg_replace('/\b(wait|let me reconsider|on second thought|actually,)\b[^.]*\./i', '', (string)$s);
        return trim(preg_replace('/\s+\n/', "\n", $s));
    };

    return [
        'summary_md' => mb_substr($strip($j['summary_md']), 0, 4000),
        'advice_md'  => mb_substr($strip($j['advice_md']),  0, 8000),
    ];
}

/**
 * Generate Hindi versions of summary + advice. Same prompt structure but
 * outputs in Devanagari Hindi suitable for Indian parents.
 *
 * Returns ['summary_md_hi' => str|null, 'advice_md_hi' => str|null].
 */
function isaa_generate_report_hindi(array $child, int $total_score, string $category, int $disability_pct, array $domain_scores, array $high_concern_items): array {
    $age_yrs = round((float)calc_age_years($child['dob']), 1);

    $hc_lines = [];
    foreach ($high_concern_items as $hc) {
        $score_label = $hc['score'] === 4 ? 'अधिकतर (61-80%)' : 'हमेशा (81-100%)';
        $hc_lines[] = "  - आइटम {$hc['item_no']} ({$hc['item_label']}) — {$score_label}";
    }
    $hc_text = !empty($hc_lines) ? implode("\n", $hc_lines) : "  (कोई आइटम 4 या 5 पर रेट नहीं हुआ।)";

    $domain_lines = [];
    foreach ($domain_scores as $dno => $d) {
        $domain_lines[] = "  - डोमेन {$dno} ({$d['label']}): {$d['raw']}/{$d['max']} ({$d['pct']}%)";
    }
    $domain_text = implode("\n", $domain_lines);

    $cat_hi = ['normal' => 'सामान्य सीमा में', 'mild' => 'हल्का ऑटिज़्म', 'moderate' => 'मध्यम ऑटिज़्म', 'severe' => 'गंभीर ऑटिज़्म'];
    $category_label_hi = $cat_hi[$category] ?? $category;

    $sys = "आप EmpowerStudents के एक वरिष्ठ बाल विकास विशेषज्ञ हैं — एक क्लिनिकल सेवा जो ISAA मूल्यांकन के साथ-साथ "
         . "बच्चों के लिए पेशेवर थेरेपी कार्यक्रम प्रदान करती है — Occupational Therapy (OT), Speech Therapy (ST), "
         . "व्यवहार थेरेपी (behaviour therapy), और लर्निंग सपोर्ट।\n\n"
         . "आप वह रिपोर्ट लिख रहे हैं जो माता-पिता अपने बच्चे के ISAA मूल्यांकन के बाद पढ़ेंगे। माता-पिता चिंतित हैं; "
         . "उन्हें आश्वस्त करें कि वे अकेले नहीं हैं — हमारी टीम सब काम संभालेगी। थेरेपी को इस तरह प्रस्तुत करें कि "
         . "हम यह बच्चे के लिए करते हैं (हमारे केंद्र पर in-person, या वीडियो सेशन से), और माता-पिता घर पर सिर्फ़ "
         . "5-10 मिनट का छोटा सहायक काम करते हैं — मुख्य शिक्षक की भूमिका नहीं।\n\n"
         . "लहजा: सहानुभूतिपूर्ण, विशेषज्ञ, आश्वस्त करने वाला, कार्य-केंद्रित। डराने वाली भाषा से बचें। ISAA एक "
         . "मूल्यांकन सहायक है जो औपचारिक चिकित्सा निदान को पूरक करता है, उसकी जगह नहीं लेता।\n\n"
         . "JSON में दो आउटपुट दें:\n\n"
         . "1. summary_md_hi — 2-3 छोटे पैराग्राफ:\n"
         . "   - पहला पैराग्राफ: परिणाम ईमानदारी से बताएँ + आश्वासन कि शुरुआती संरचित हस्तक्षेप से बहुत मदद होती है\n"
         . "   - दूसरा पैराग्राफ: चिंता के क्षेत्र + शक्तियाँ (यदि हों)\n"
         . "   - अंतिम पैराग्राफ: माता-पिता को हमारे साथ थेरेपी शुरू करने का स्पष्ट निमंत्रण। बताएँ कि एक बार के "
         . "consultation के बाद हमारी टीम बच्चे के लिए व्यक्तिगत थेरेपी योजना बनाएगी। औपचारिक निदान के लिए बाल "
         . "न्यूरोलॉजिस्ट या विकास विशेषज्ञ से फॉलो-अप का उल्लेख करें।\n\n"
         . "2. advice_md_hi — थेरेपी योजना की सिफ़ारिशें। केवल उन डोमेन को कवर करें जहाँ बच्चे ने अधिकतम के 50%+ अंक "
         . "प्राप्त किए हों, या जहाँ व्यक्तिगत आइटम 4 या 5 पर रेट हुए हों। प्रत्येक ऐसे डोमेन के लिए संरचना:\n\n"
         . "   ## [डोमेन N: डोमेन नाम]\n"
         . "   **हम क्या करेंगे:** [1 वाक्य में थेरेपी का लक्ष्य भारतीय माता-पिता की भाषा में]\n"
         . "   **सुझाई गई थेरेपी:** [विशिष्ट रहें: कौन सी थेरेपी (OT / ST / व्यवहार थेरेपी / सामाजिक कौशल समूह / "
         . "sensory integration), आवृत्ति जैसे 'प्रति सप्ताह 2 speech therapy सेशन', मोड 'हमारे केंद्र पर in-person "
         . "या वीडियो', अवधि जैसे 'शुरुआत में 12-16 सप्ताह']\n"
         . "   **आप घर पर क्या करें (5 मिनट/दिन):** [माता-पिता के लिए एक सरल सहायक गतिविधि — छोटी हो, शिक्षक की "
         . "भूमिका नहीं, सिर्फ़ सहायक। उदाहरण: 'जब बच्चा शांत हो, उसके पसंदीदा cartoon चरित्र में 3 भावनाएँ साथ "
         . "पहचानें'। भारतीय संदर्भ।]\n"
         . "   **अपेक्षित प्रगति:** [यथार्थवादी समय-सीमा — जैसे '6-8 सप्ताह में पहले दिखाई देने वाले बदलाव; 4-6 "
         . "महीनों में सार्थक सुधार']\n\n"
         . "advice_md_hi के अंत में एक समापन पैराग्राफ: 'हमारी टीम शुरू करने के लिए तैयार है। EmpowerStudents के "
         . "साथ एक बार के consultation के लिए संपर्क करें — हम आपके बच्चे के लिए व्यक्तिगत थेरेपी योजना बनाएँगे, "
         . "बाक़ी सब हम संभालेंगे।'\n\n"
         . "महत्वपूर्ण निर्देश:\n"
         . "- केवल JSON आउटपुट दें, कोई प्रस्तावना नहीं, कोई markdown code fences नहीं।\n"
         . "- सोचने का ट्रेस शामिल न करें। केवल अंतिम साफ़ टेक्स्ट।\n"
         . "- बच्चे को निश्चित रूप से 'ऑटिस्टिक' लेबल न करें। 'ऑटिज़्म स्पेक्ट्रम के अनुरूप विशेषताएँ दिखाता है' जैसी भाषा।\n"
         . "- advice_md_hi को 700 शब्दों के अंदर रखें। summary_md_hi 220 शब्दों के अंदर।\n"
         . "- शुद्ध हिंदी (देवनागरी) में लिखें। तकनीकी शब्दों के लिए English शब्द कोष्ठक में दे सकते हैं — OT, ST, therapy, etc.\n"
         . "- इस रिपोर्ट में कोई विशिष्ट कीमत न बताएँ — मूल्य निर्धारण अलग से।\n";

    $user = "बच्चा: {$child['name']}, उम्र {$age_yrs} वर्ष"
          . (!empty($child['gender']) ? ", " . $child['gender'] : '')
          . (!empty($child['mother_tongue']) ? ", मातृभाषा: " . $child['mother_tongue'] : '')
          . "\n\n"
          . "मूल्यांकन परिणाम:\n"
          . "  कुल ISAA स्कोर: {$total_score}/200\n"
          . "  वर्गीकरण: {$category_label_hi}\n"
          . "  विकलांगता प्रतिशत (ISAA स्कोरिंग टेबल अनुसार): {$disability_pct}%\n\n"
          . "डोमेन विश्लेषण:\n{$domain_text}\n\n"
          . "आइटम 4 (अधिकतर) या 5 (हमेशा) पर रेट हुए — उच्च चिंता वाले व्यवहार:\n{$hc_text}\n\n"
          . "अब JSON आउटपुट दें: { \"summary_md_hi\": \"...\", \"advice_md_hi\": \"...\" }";

    $j = claude_json($sys, $user, 2400, 0.5);
    if (!$j || !isset($j['summary_md_hi']) || !isset($j['advice_md_hi'])) {
        return ['summary_md_hi' => null, 'advice_md_hi' => null];
    }

    $strip = function($s) {
        // strip English reasoning-trace markers if leaked
        $s = preg_replace('/\b(wait|let me reconsider|on second thought|actually,)\b[^.]*\./i', '', (string)$s);
        return trim(preg_replace('/\s+\n/', "\n", $s));
    };

    return [
        'summary_md_hi' => mb_substr($strip($j['summary_md_hi']), 0, 4000),
        'advice_md_hi'  => mb_substr($strip($j['advice_md_hi']),  0, 8000),
    ];
}

/**
 * Generate a unique short share token + 4-digit PIN for an assessment.
 * Returns ['token' => str, 'pin' => str].
 */
function isaa_generate_share_credentials(): array {
    // 8-char base32-ish token (A-Z, 2-9 — easy to read aloud, no 0/O/1/I confusion)
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    for ($i = 0; $i < 8; $i++) {
        $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    // 4-digit PIN
    $pin = sprintf('%04d', random_int(0, 9999));
    return ['token' => $token, 'pin' => $pin];
}
