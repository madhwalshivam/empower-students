<?php
/**
 * includes/eval_engine.php
 *
 * The adaptive evaluation engine. Pure logic, no UI.
 *
 * Flow per evaluation:
 *   1. eval_start_session()  — create row, assign first level (start at L3 mid)
 *   2. eval_next_question()  — call AI to generate one question at the current level
 *   3. (User answers in browser)
 *   4. eval_score_and_decide() — call AI to score + decide next level + decide stop
 *   5. (Repeat 2-4 until stop)
 *   6. eval_finalise() — call AI for final report + sample exercise
 *
 * Cost: ~12 calls × ~400 tokens = ~₹4-5 per evaluation.
 */

require_once __DIR__ . '/claude.php';

/**
 * Fast JSON call using Claude Haiku (3-5x faster than Sonnet, ~1/5 the cost).
 * Use for short structured calls where speed > nuance: question generation, scoring.
 * Keep claude_json() (Sonnet) for the final report which needs higher quality.
 *
 * Falls back to claude_json() if the Haiku call fails or returns garbage.
 */
function eval_haiku_json(string $system, string $user_prompt, int $max_tokens = 600, float $temperature = 0.4): ?array {
    $payload = [
        'model'       => 'claude-haiku-4-5-20251001',
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'system'      => $system . "\n\nReturn ONLY valid minified JSON. No prose, no code fences.",
        'messages'    => [['role' => 'user', 'content' => $user_prompt]],
    ];

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: ' . ANTHROPIC_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code >= 400) {
        error_log('[eval_haiku] HTTP ' . $code . ' ' . $err . ' :: ' . substr((string)$resp, 0, 400));
        // Fallback to Sonnet
        return claude_json($system, $user_prompt, $max_tokens, $temperature);
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['content'])) {
        return claude_json($system, $user_prompt, $max_tokens, $temperature);
    }
    $txt = '';
    foreach ($data['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text') {
            $txt = (string)$block['text']; break;
        }
    }
    $txt = trim($txt);
    if (strpos($txt, '```') === 0) {
        $txt = preg_replace('/^```(?:json)?/i', '', $txt);
        $txt = preg_replace('/```\s*$/', '', $txt);
        $txt = trim($txt);
    }
    $j = json_decode($txt, true);
    if (is_array($j)) return $j;
    if (preg_match('/(\{.*\}|\[.*\])/s', $txt, $m)) {
        $j = json_decode($m[1], true);
        if (is_array($j)) return $j;
    }
    // Final fallback to Sonnet
    return claude_json($system, $user_prompt, $max_tokens, $temperature);
}


// ─────────────────────────────────────────────────────────────
// Level descriptions for the speech eval (drives prompt context)
// ─────────────────────────────────────────────────────────────
/**
 * Last-resort canned question bank — used only when BOTH the combined Haiku call
 * AND the legacy eval_next_question call fail. Ensures the eval never dead-ends.
 *
 * Returns ['type' => 'naming'|'fill_in', 'prompt' => string, 'expected' => string]
 *
 * Each level has 5 EN + 5 HI candidates. The function rotates through them
 * pseudo-randomly using session_id microtime so we don't always show the same one.
 */
function eval_canned_question(int $level, bool $is_hindi): array {
    $bank = [
        // L1 — single words / very basic
        1 => [
            'en' => [
                ['type'=>'naming',  'prompt'=>'Tell me, what animal goes "meow meow"?',                          'expected'=>'cat|kitty|billi'],
                ['type'=>'naming',  'prompt'=>'What do we drink that is white and we get from a cow?',           'expected'=>'milk|doodh'],
                ['type'=>'naming',  'prompt'=>'What red round fruit do we eat?',                                 'expected'=>'apple|seb'],
                ['type'=>'naming',  'prompt'=>'What animal says "woof woof"?',                                   'expected'=>'dog|kutta|puppy'],
                ['type'=>'naming',  'prompt'=>'Tell me — what do we wear on our feet?',                          'expected'=>'shoes|chappal|slippers|socks|joote'],
            ],
            'hi' => [
                ['type'=>'naming',  'prompt'=>'बच्चे, कौन-सा जानवर "म्याऊँ-म्याऊँ" करता है?',                       'expected'=>'बिल्ली|cat|billi'],
                ['type'=>'naming',  'prompt'=>'सफ़ेद रंग का जो हम गिलास में पीते हैं, वो क्या है?',                  'expected'=>'दूध|milk|doodh'],
                ['type'=>'naming',  'prompt'=>'लाल गोल मीठा फल कौन-सा होता है?',                                  'expected'=>'सेब|apple|seb'],
                ['type'=>'naming',  'prompt'=>'कौन-सा जानवर "भौं-भौं" करता है?',                                   'expected'=>'कुत्ता|dog|kutta'],
                ['type'=>'naming',  'prompt'=>'पैरों में हम क्या पहनते हैं?',                                       'expected'=>'जूते|चप्पल|shoes|joote|chappal'],
            ],
        ],
        // L2 — common nouns, simple phrases
        2 => [
            'en' => [
                ['type'=>'naming',  'prompt'=>'Tell me — what do we eat in the morning for breakfast?',           'expected'=>'roti|bread|paratha|cereal|toast|breakfast|milk|doodh'],
                ['type'=>'naming',  'prompt'=>'Where does a bird fly? Tell me one place.',                        'expected'=>'sky|aasman|aasmaan|air|tree'],
                ['type'=>'naming',  'prompt'=>'What do we use to write on paper?',                                'expected'=>'pen|pencil|kalam'],
                ['type'=>'naming',  'prompt'=>'When it rains, what do we hold over our head?',                    'expected'=>'umbrella|chhata|chhatri'],
                ['type'=>'naming',  'prompt'=>'What animal lives in water and swims?',                            'expected'=>'fish|machhli'],
            ],
            'hi' => [
                ['type'=>'naming',  'prompt'=>'सुबह नाश्ते में हम क्या खाते हैं?',                                   'expected'=>'रोटी|पराठा|दूध|breakfast|paratha|roti'],
                ['type'=>'naming',  'prompt'=>'चिड़िया कहाँ उड़ती है? बताओ।',                                       'expected'=>'आसमान|aasman|sky|पेड़'],
                ['type'=>'naming',  'prompt'=>'काग़ज़ पर लिखने के लिए हम क्या इस्तेमाल करते हैं?',                  'expected'=>'पेंसिल|पेन|कलम|pencil|pen'],
                ['type'=>'naming',  'prompt'=>'जब बारिश होती है, तो सिर के ऊपर हम क्या रखते हैं?',                  'expected'=>'छाता|छतरी|umbrella'],
                ['type'=>'naming',  'prompt'=>'पानी में रहने वाला जानवर जो तैरता है — वो कौन है?',                  'expected'=>'मछली|fish|machhli'],
            ],
        ],
        // L3 — short sentences, basic categories
        3 => [
            'en' => [
                ['type'=>'naming',  'prompt'=>'Where does mummy cook food in the house? Tell me.',                'expected'=>'kitchen|rasoi'],
                ['type'=>'naming',  'prompt'=>'Tell me about your favourite toy. What is it?',                    'expected'=>'car|doll|ball|teddy|train|bike|any toy'],
                ['type'=>'naming',  'prompt'=>'Where do cars and buses run? Tell me.',                            'expected'=>'road|street|sadak|highway'],
                ['type'=>'naming',  'prompt'=>'What do we do at night when we feel sleepy?',                      'expected'=>'sleep|sona|rest|go to bed'],
                ['type'=>'naming',  'prompt'=>'Name any one fruit that is yellow.',                               'expected'=>'banana|kela|mango|aam|lemon|nimbu'],
            ],
            'hi' => [
                ['type'=>'naming',  'prompt'=>'घर में मम्मी खाना कहाँ बनाती हैं? बताओ।',                            'expected'=>'रसोई|किचन|kitchen|rasoi'],
                ['type'=>'naming',  'prompt'=>'अपने पसंदीदा खिलौने के बारे में बताओ — वो क्या है?',                'expected'=>'गाड़ी|गुड़िया|बॉल|कोई भी खिलौना|toy|car|doll|ball'],
                ['type'=>'naming',  'prompt'=>'गाड़ियाँ और बस कहाँ चलती हैं? बताओ।',                                'expected'=>'सड़क|रोड|road|sadak'],
                ['type'=>'naming',  'prompt'=>'रात को जब नींद आती है, तब हम क्या करते हैं?',                       'expected'=>'सोते|सोना|sleep|sona'],
                ['type'=>'naming',  'prompt'=>'कोई एक पीला फल बताओ।',                                              'expected'=>'केला|आम|नींबू|banana|mango|kela'],
            ],
        ],
        // L4 — complex sentences, sequencing
        4 => [
            'en' => [
                ['type'=>'describe','prompt'=>'Tell me what happens in the morning when you wake up — what do you do first?', 'expected'=>'brush teeth|wash face|eat breakfast|any morning routine activity'],
                ['type'=>'describe','prompt'=>'Tell me about your school. What do you do there?',                 'expected'=>'study|read|write|play|learn|any school activity'],
                ['type'=>'describe','prompt'=>'If your friend is crying, what would you do to make them feel better?', 'expected'=>'hug|talk|share|help|comfort|any kindness'],
                ['type'=>'describe','prompt'=>'Why do we wear an umbrella? Tell me.',                             'expected'=>'rain|wet|barish|protection from rain|any reason involving rain'],
                ['type'=>'describe','prompt'=>'Tell me what you did yesterday — anything you remember.',          'expected'=>'any activity|played|ate|watched|any memory'],
            ],
            'hi' => [
                ['type'=>'describe','prompt'=>'सुबह उठकर तुम सबसे पहले क्या करते हो? बताओ।',                       'expected'=>'ब्रश|मुँह धोना|नाश्ता|कोई भी सुबह की दिनचर्या'],
                ['type'=>'describe','prompt'=>'अपने स्कूल के बारे में बताओ। वहाँ तुम क्या करते हो?',                'expected'=>'पढ़ाई|खेल|दोस्त|any school activity'],
                ['type'=>'describe','prompt'=>'अगर तुम्हारा दोस्त रो रहा हो, तो तुम उसे क्या करोगे?',               'expected'=>'गले लगाना|बात करना|शेयर|मदद|कोई भी अच्छाई|hug|help'],
                ['type'=>'describe','prompt'=>'हम छाता क्यों लेकर जाते हैं? बताओ।',                                 'expected'=>'बारिश|भीगना|barish|rain|कोई भी कारण'],
                ['type'=>'describe','prompt'=>'कल तुमने क्या किया? कुछ भी जो तुम्हें याद है, बताओ।',                'expected'=>'कोई भी काम|खेला|खाया|देखा|any memory'],
            ],
        ],
        // L5 — narration, abstract vocab
        5 => [
            'en' => [
                ['type'=>'describe','prompt'=>'Tell me a short story about a tiger who went into the forest.',    'expected'=>'any coherent story with beginning/middle/end about a tiger'],
                ['type'=>'describe','prompt'=>'What is honesty? Can you give me an example?',                     'expected'=>'truth|not lying|telling the truth|any example of honesty'],
                ['type'=>'describe','prompt'=>'If you could be any animal for one day, which would you be and why?', 'expected'=>'any animal with a reason'],
                ['type'=>'describe','prompt'=>'Tell me about a time you helped someone — what happened?',         'expected'=>'any helping story'],
                ['type'=>'describe','prompt'=>'What is the difference between a fruit and a vegetable?',          'expected'=>'fruit is sweet|vegetable for cooking|any reasonable distinction'],
            ],
            'hi' => [
                ['type'=>'describe','prompt'=>'एक छोटी-सी कहानी सुनाओ — एक शेर जंगल में गया।',                     'expected'=>'कोई भी सुसंगत कहानी शुरुआत-मध्य-अंत के साथ'],
                ['type'=>'describe','prompt'=>'सच्चाई क्या होती है? कोई उदाहरण दो।',                                'expected'=>'सच बोलना|झूठ नहीं बोलना|कोई भी उदाहरण'],
                ['type'=>'describe','prompt'=>'अगर तुम एक दिन के लिए कोई भी जानवर बन सकते, तो कौन-सा और क्यों?',   'expected'=>'कोई भी जानवर कारण के साथ'],
                ['type'=>'describe','prompt'=>'एक बार बताओ जब तुमने किसी की मदद की — क्या हुआ था?',                'expected'=>'कोई भी मदद की कहानी'],
                ['type'=>'describe','prompt'=>'फल और सब्ज़ी में क्या अंतर है?',                                     'expected'=>'फल मीठा होता है|सब्ज़ी पकाते हैं|कोई भी अंतर'],
            ],
        ],
    ];

    $level = max(1, min(5, $level));
    $lang = $is_hindi ? 'hi' : 'en';
    $set = $bank[$level][$lang] ?? $bank[3]['en'];
    // Pick pseudo-randomly via microtime — different question each session
    $idx = ((int)(microtime(true) * 1000)) % count($set);
    return $set[$idx];
}


function eval_speech_level_desc(int $level): array {
    $levels = [
        1 => [
            'name'   => 'Single sounds & basic words',
            'desc'   => 'Single phonemes (M, B, P, T, K, S sounds), basic vowels, very common single-syllable words like "cat", "ball", "milk".',
            'age_eq' => '~18 months to 3 years equivalent',
        ],
        2 => [
            'name'   => 'Common nouns & 2-word phrases',
            'desc'   => 'Common Indian household objects, body parts, family members, animals, food. Two-word phrases ("more milk", "go park").',
            'age_eq' => '~3 to 4 years equivalent',
        ],
        3 => [
            'name'   => 'Short sentences & basic grammar',
            'desc'   => 'Simple 4-6 word sentences with subject-verb-object. Common verbs (eat, run, sleep). Basic prepositions (in, on, under). Pronouns (I, you, mine).',
            'age_eq' => '~4 to 6 years equivalent',
        ],
        4 => [
            'name'   => 'Complex sentences, sequencing',
            'desc'   => 'Past/future tense, causal connectives (because, so, but). Vocabulary expansion. Story sequencing with 3-4 events. Comparative adjectives.',
            'age_eq' => '~6 to 8 years equivalent',
        ],
        5 => [
            'name'   => 'Advanced narration, abstract vocabulary',
            'desc'   => 'Compound and complex sentences. Abstract nouns (kindness, anger). Idioms in Indian context. Inferential questions ("Why might she feel sad?"). Definitions.',
            'age_eq' => '~8 to 12 years equivalent',
        ],
    ];
    return $levels[max(1, min(5, $level))] ?? $levels[3];
}

/**
 * Create a new evaluation session for a child.
 * Caller has already verified payment / free-eval logic.
 *
 * Returns the new session id.
 */
function eval_start_session(int $parent_id, int $child_id, string $module, bool $is_free = false, int $cost_paid = 0): int {
    db()->prepare("INSERT INTO eval_sessions (parent_id, child_id, module, is_free, cost_paid, current_level)
                   VALUES (?, ?, ?, ?, ?, 3)")
       ->execute([$parent_id, $child_id, $module, $is_free ? 1 : 0, $cost_paid]);
    return (int) db()->lastInsertId();
}

/**
 * Generate the next question for a session. Calls Claude with full context
 * (child name, age, mother tongue, current level, history of recent answers).
 *
 * @param bool $voice_mode  If true, restrict to question types the child can answer
 *                          by speaking — naming/fill_in/describe. No MCQ (MCQ would
 *                          require speaking option labels which is awkward for kids).
 *
 * Returns array ['question_id' => int, 'prompt' => str, 'type' => str,
 *                'options' => array|null, 'image_concept' => str|null].
 */
function eval_next_question(int $session_id, bool $voice_mode = false): ?array {
    $st = db()->prepare("SELECT s.*, c.name AS child_name, c.dob AS child_dob,
                                c.gender AS child_gender, c.mother_tongue AS child_mt
                         FROM eval_sessions s
                         JOIN children c ON c.id = s.child_id
                         WHERE s.id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return null;

    $age_yrs = round((float) calc_age_years($session['child_dob']), 1);
    $level   = (int) $session['current_level'];
    $level_info = eval_speech_level_desc($level);
    $seq_no  = (int) $session['questions_asked'] + 1;

    // Pull recent question history to avoid repeats and let AI see context
    $hist_st = db()->prepare("SELECT seq_no, level, question_type, prompt, user_answer, is_correct, time_seconds
                              FROM eval_questions
                              WHERE session_id = ?
                              ORDER BY seq_no DESC LIMIT 5");
    $hist_st->execute([$session_id]);
    $history_lines = [];
    foreach (array_reverse($hist_st->fetchAll()) as $h) {
        $verdict = $h['is_correct'] === null ? '?' : ($h['is_correct'] ? '✓' : '✗');
        $history_lines[] = "  Q{$h['seq_no']} (L{$h['level']}, {$h['question_type']}): \""
                         . mb_substr((string)$h['prompt'], 0, 80)
                         . "\" → answered \"" . mb_substr((string)$h['user_answer'], 0, 50) . "\""
                         . " {$verdict} ({$h['time_seconds']}s)";
    }
    $history_text = empty($history_lines)
        ? "  (this is the first question)"
        : implode("\n", $history_lines);

    if ($voice_mode) {
        // VOICE INTERVIEW MODE — child speaks the answer aloud.
        $sys = "You generate ONE adaptive speech & language evaluation question for a child to answer ALOUD. "
             . "This is a live voice interview — the child will hear the question read aloud (TTS) and speak their answer back.\n\n"
             . "STRICT RULES:\n"
             . "- NO multiple choice. NO 'pick from options'. The child will speak freely.\n"
             . "- Question must be ANSWERABLE BY SPEAKING in 1-2 short sentences.\n"
             . "- NO 'type your answer' / 'write' / 'pick' wording — say 'tell me' / 'say' / 'name'.\n"
             . "- Indian context: Indian names (Aarav, Priya, Rahul), foods (रोटी, दाल), family (दीदी, दादी), settings (स्कूल, मंदिर, बाज़ार).\n"
             . "- LANGUAGE — VERY IMPORTANT FOR TTS:\n"
             . "    - If mother tongue is Hindi: write in **Devanagari ONLY** (शुद्ध हिंदी). Romanized Hindi sounds wrong in TTS — the engine spells it letter-by-letter.\n"
             . "    - If mother tongue is English: write in **plain English ONLY** (no Hindi/Hinglish).\n"
             . "    - For other languages: default to plain English.\n"
             . "- Question must MATCH level L{$level} — not too easy, not too hard.\n"
             . "- Don't repeat any concept from recent history.\n"
             . "- Be warm and brief. Like a friendly therapist talking to a child, not a test.\n\n"
             . "QUESTION TYPES (pick the most natural for spoken answer):\n"
             . "  - 'naming': ask a direct question; child says one word or short phrase as answer.\n"
             . "      English example: 'Tell me, what do we eat at breakfast?'\n"
             . "      Hindi example:   'बच्चे, हम सुबह नाश्ते में क्या खाते हैं?'\n"
             . "  - 'describe': open question child answers in 1-2 sentences. Use for L4+.\n"
             . "      English example: 'Tell me about your favourite festival.'\n"
             . "      Hindi example:   'अपने पसंदीदा त्योहार के बारे में बताओ।'\n\n"
             . "DO NOT use fill-in-the-blank questions (sentences with ___). They confuse young children and disabled children — they have to track the sentence structure, hold the missing word in working memory, and produce the right form. Use plain questions only.\n\n"
             . "Output JSON only:\n"
             . "{\n"
             . "  \"type\": \"naming\" | \"describe\",\n"
             . "  \"prompt\": \"the question text — short, conversational, designed to be SPOKEN aloud\",\n"
             . "  \"expected\": \"acceptable answer(s), separated by | if multiple. Be generous — anything semantically correct counts. For Hindi questions, list both Devanagari AND Romanized forms (e.g. 'आसमान|aasmaan|sky') so speech-to-text matches.\",\n"
             . "  \"image_concept\": null\n"
             . "}\n\n"
             . "DO NOT include any other text, markdown fences, or commentary.";
    } else {
        // LEGACY TEXT MODE (kept for backwards compat)
        $sys = "You generate ONE adaptive speech & language evaluation question for a child. "
             . "The evaluation is administered via a screen — the parent reads the question to the child "
             . "(or the older child reads it themselves) and types/picks the answer.\n\n"
             . "STRICT RULES:\n"
             . "- NO audio. NO 'repeat after me' questions. Only text/MCQ/picture-concept questions.\n"
             . "- Indian context: use Indian names, foods, places, family terms (didi, dadi).\n"
             . "- Adapt to the child's mother tongue: if it's Hindi, the question should be in Hinglish "
             . "or use Indian English the child would hear at home.\n"
             . "- Question must MATCH the level — not too easy (boring), not too hard (failure).\n"
             . "- Don't repeat any concept from the recent history.\n\n"
             . "QUESTION TYPES (pick one that fits the level):\n"
             . "  - 'mcq': 4-option multiple choice. Use for vocabulary, grammar, comprehension.\n"
             . "  - 'naming': describe an image concept in 1-2 short sentences (no actual image — describe what would be shown). Child types what they see.\n"
             . "  - 'fill_in': sentence with a blank, child types the missing word.\n"
             . "  - 'describe': open question asking child to write 1-2 sentences. Use for higher levels only.\n\n"
             . "Output JSON only:\n"
             . "{\n"
             . "  \"type\": \"mcq\" | \"naming\" | \"fill_in\" | \"describe\",\n"
             . "  \"prompt\": \"the question text\",\n"
             . "  \"options\": [\"...\", \"...\", \"...\", \"...\"]   // ONLY for mcq\n"
             . "  \"expected\": \"the gold/correct answer (or list of acceptable answers separated by | for short-answer)\",\n"
             . "  \"image_concept\": \"short description of imagined visual (only for naming questions)\"\n"
             . "}\n\n"
             . "DO NOT include any other text, markdown fences, or commentary.";
    }

    $user = "Child profile:\n"
          . "  Name: {$session['child_name']}\n"
          . "  Age: {$age_yrs} years\n"
          . "  Gender: " . ($session['child_gender'] ?: 'unspecified') . "\n"
          . "  Mother tongue: " . ($session['child_mt'] ?: 'English') . "\n\n"
          . "Current level: L{$level} — {$level_info['name']}\n"
          . "Level description: {$level_info['desc']}\n"
          . "Age-equivalent: {$level_info['age_eq']}\n\n"
          . "Question number: Q{$seq_no}\n\n"
          . "Recent history (last 5 questions):\n{$history_text}\n\n"
          . "Now generate ONE question at level L{$level}. Return JSON only.";

    $j = eval_haiku_json($sys, $user, 600, 0.7);
    if (!$j || empty($j['type']) || empty($j['prompt'])) {
        error_log('[eval_next_question] Claude returned invalid JSON for session ' . $session_id);
        return null;
    }

    $type = $j['type'];
    $allowed = $voice_mode ? ['naming', 'describe'] : ['mcq', 'naming', 'fill_in', 'describe'];
    if (!in_array($type, $allowed, true)) {
        $type = $voice_mode ? 'naming' : 'mcq';
    }
    $options = ($type === 'mcq' && !empty($j['options']) && is_array($j['options']))
        ? array_values($j['options'])
        : null;

    db()->prepare("INSERT INTO eval_questions
                   (session_id, seq_no, level, question_type, prompt, options_json, expected, image_concept)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([
           $session_id,
           $seq_no,
           $level,
           $type,
           (string) $j['prompt'],
           $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
           (string) ($j['expected'] ?? ''),
           (string) ($j['image_concept'] ?? ''),
       ]);
    $qid = (int) db()->lastInsertId();

    db()->prepare("UPDATE eval_sessions SET questions_asked = ? WHERE id = ?")
       ->execute([$seq_no, $session_id]);

    return [
        'question_id'   => $qid,
        'seq_no'        => $seq_no,
        'level'         => $level,
        'type'          => $type,
        'prompt'        => $j['prompt'],
        'options'       => $options,
        'image_concept' => $j['image_concept'] ?? null,
    ];
}

/**
 * Score the user's answer to a question, decide next level, decide stop.
 *
 * @param int    $session_id
 * @param int    $question_id
 * @param string $user_answer    Text answer (or speech transcript if voice mode)
 * @param int    $time_seconds   Total time taken to answer
 * @param array  $acoustic       Optional acoustic features when answer came via voice:
 *                               ['transcript_confidence' => 0..1,
 *                                'duration_sec' => float,
 *                                'wpm' => int,
 *                                'volume_variance' => float (0..1),
 *                                'silence_ratio' => float (0..1),
 *                                'pause_count' => int,
 *                                'time_to_first_speech_sec' => float]
 *                               When empty, scoring uses transcript only (text mode).
 *
 * Returns ['is_correct' => bool, 'verdict' => str, 'next_level' => int, 'should_stop' => bool, 'reason' => str].
 */
function eval_score_and_decide(int $session_id, int $question_id, string $user_answer, int $time_seconds, array $acoustic = []): array {
    $qst = db()->prepare("SELECT * FROM eval_questions WHERE id = ?");
    $qst->execute([$question_id]);
    $q = $qst->fetch();
    if (!$q) return ['is_correct' => false, 'verdict' => 'wrong_slow', 'next_level' => 3, 'should_stop' => false, 'reason' => 'question not found'];

    $sst = db()->prepare("SELECT * FROM eval_sessions WHERE id = ?");
    $sst->execute([$session_id]);
    $session = $sst->fetch();

    // ── Build acoustic-signals block (only when voice answer) ──
    $acoustic_block = '';
    $is_voice = !empty($acoustic) && !empty($user_answer);
    if ($is_voice) {
        $sig_lines = [];
        if (isset($acoustic['transcript_confidence']))    $sig_lines[] = "  Transcript confidence: " . round((float)$acoustic['transcript_confidence'] * 100) . "%";
        if (isset($acoustic['duration_sec']))             $sig_lines[] = "  Speech duration: " . round((float)$acoustic['duration_sec'], 1) . "s";
        if (isset($acoustic['wpm']))                       $sig_lines[] = "  Words per minute: " . (int)$acoustic['wpm'];
        if (isset($acoustic['volume_variance']))          $sig_lines[] = "  Volume variance: " . round((float)$acoustic['volume_variance'], 2) . " (low=monotone, high=animated)";
        if (isset($acoustic['silence_ratio']))            $sig_lines[] = "  Silence ratio: " . round((float)$acoustic['silence_ratio'] * 100) . "% (high=lots of pauses)";
        if (isset($acoustic['pause_count']))              $sig_lines[] = "  Pause count: " . (int)$acoustic['pause_count'];
        if (isset($acoustic['time_to_first_speech_sec'])) $sig_lines[] = "  Time to start speaking: " . round((float)$acoustic['time_to_first_speech_sec'], 1) . "s (high=hesitation)";
        $acoustic_block = "\n\nThe child SPOKE this answer (speech-to-text). Acoustic signals:\n"
                        . implode("\n", $sig_lines)
                        . "\n\nUse these signals when relevant:\n"
                        . "- Long time-to-first-speech + lots of silence → child may have struggled to retrieve the word\n"
                        . "- Very low transcript confidence → speech-to-text may have garbled what they said; be more lenient\n"
                        . "- Very fast WPM with correct content → strong recall\n"
                        . "- High pause count for a young child → still developing fluency, normal\n"
                        . "Do NOT penalise for speech-to-text errors that look like phonetic-but-wrong spellings of the right word.\n";
    }

    // ── Score: AI compares user answer against expected ──
    $sys = "You are scoring one question in an adaptive speech & language evaluation. "
         . "Be lenient on Indian English spelling variations and minor typos — the goal is to "
         . "assess language ability, not spelling perfection. For young children, accept simpler "
         . "or shorter forms of the expected answer. If the answer arrived via speech-to-text, "
         . "be extra lenient on spelling/transcription artifacts (e.g. 'rotee' for 'roti', 'jus' for 'juice').\n\n"
         . "Output JSON only: { \"is_correct\": true|false, \"reason\": \"one short sentence\" }";

    $user = "Question type: {$q['question_type']}\n"
          . "Question prompt: {$q['prompt']}\n"
          . "Expected answer: {$q['expected']}\n"
          . "Child's answer: \"" . trim($user_answer) . "\""
          . $acoustic_block
          . "\n\nScore it. Return JSON only.";

    $j = eval_haiku_json($sys, $user, 250, 0.1);
    $is_correct = !empty($j['is_correct']);

    // Verdict based on speed + correctness
    $fast = $time_seconds <= 8;
    if ($is_correct &&  $fast)  $verdict = 'correct_fast';
    elseif ($is_correct && !$fast) $verdict = 'correct_slow';
    elseif (!$is_correct && $fast) $verdict = 'wrong_fast';
    else                            $verdict = 'wrong_slow';

    // ── Decide next level ──
    $cur_level = (int) $q['level'];
    $next_level = $cur_level;
    if ($verdict === 'correct_fast')      $next_level = min(5, $cur_level + 1);
    elseif ($verdict === 'correct_slow')  $next_level = $cur_level;       // stay, probe
    elseif ($verdict === 'wrong_fast')    $next_level = $cur_level;       // stay, retest
    elseif ($verdict === 'wrong_slow')    $next_level = max(1, $cur_level - 1);

    // ── Decide stop ──
    // Stop conditions:
    //   (a) hit 15 questions (hard cap)
    //   (b) >= 5 questions answered AND last 3 at same level with consistent correctness
    $seq_so_far = (int) $q['seq_no'];
    $should_stop = false;
    $stop_reason = '';

    if ($seq_so_far >= 15) {
        $should_stop = true;
        $stop_reason = 'hit hard cap of 15 questions';
    } elseif ($seq_so_far >= 8) {
        // Minimum 8 questions before adaptive stop can trigger.
        // Get the LAST 2 already-scored questions (i.e. excluding the current Q being scored).
        // Then append the just-scored result to make 3 total.
        $hst = db()->prepare("SELECT level, is_correct FROM eval_questions
                              WHERE session_id = ? AND seq_no < ? AND is_correct IS NOT NULL
                              ORDER BY seq_no DESC LIMIT 2");
        $hst->execute([$session_id, $seq_so_far]);
        $prev2 = array_reverse($hst->fetchAll());
        $recent = $prev2;
        $recent[] = ['level' => $cur_level, 'is_correct' => $is_correct ? 1 : 0];

        if (count($recent) === 3) {
            $levels    = array_column($recent, 'level');
            $corrects  = array_column($recent, 'is_correct');
            $same_level = (count(array_unique($levels)) === 1);
            $consistent = (count(array_unique($corrects)) === 1);
            if ($same_level && $consistent) {
                $should_stop = true;
                $stop_reason = "3 consecutive at L{$cur_level} with consistent " . ($corrects[0] ? 'correct' : 'incorrect');
            }
        }
    }

    // ── Persist scoring result on the question row ──
    db()->prepare("UPDATE eval_questions
                   SET answered_at = CURRENT_TIMESTAMP,
                       time_seconds = ?,
                       user_answer = ?,
                       answer_mode = ?,
                       acoustic_json = ?,
                       is_correct = ?,
                       ai_verdict = ?,
                       next_level = ?
                   WHERE id = ?")
       ->execute([
           $time_seconds,
           $user_answer,
           $is_voice ? 'voice' : 'text',
           $is_voice ? json_encode($acoustic, JSON_UNESCAPED_UNICODE) : null,
           $is_correct ? 1 : 0,
           $verdict,
           $next_level,
           $question_id,
       ]);

    // ── Bump session current_level (if not stopping) ──
    if (!$should_stop) {
        db()->prepare("UPDATE eval_sessions SET current_level = ? WHERE id = ?")
           ->execute([$next_level, $session_id]);
    }

    return [
        'is_correct'  => $is_correct,
        'verdict'     => $verdict,
        'next_level'  => $next_level,
        'should_stop' => $should_stop,
        'reason'      => $stop_reason,
    ];
}

/**
 * COMBINED: Score the answer AND generate the next question in ONE Haiku call.
 *
 * This cuts per-turn latency ~50% vs the legacy two-call flow
 * (eval_score_and_decide → eval_next_question).
 *
 * Server still does the deterministic math (verdict, next_level, stop logic).
 * The Claude call returns:
 *   - is_correct (boolean) for scoring the just-given answer
 *   - TWO candidate next questions: one for "go up a level", one for "stay/down".
 *     Server picks the right one based on its computed next_level.
 *
 * If the call fails or returns garbage, falls back to the legacy two-step path.
 *
 * Returns:
 *   ['scoring'   => ['is_correct' => bool, 'verdict' => str, 'next_level' => int,
 *                    'should_stop' => bool, 'reason' => str],
 *    'question'  => array|null    // null if should_stop is true
 *   ]
 */
function eval_score_and_next(int $session_id, int $question_id, string $user_answer, int $time_seconds, array $acoustic = []): array {
    $qst = db()->prepare("SELECT * FROM eval_questions WHERE id = ?");
    $qst->execute([$question_id]);
    $q = $qst->fetch();
    if (!$q) {
        return [
            'scoring'  => ['is_correct'=>false,'verdict'=>'wrong_slow','next_level'=>3,'should_stop'=>false,'reason'=>'question not found'],
            'question' => null,
        ];
    }

    // Pull session + child
    $sst = db()->prepare("SELECT s.*, c.name AS child_name, c.dob AS child_dob,
                                 c.gender AS child_gender, c.mother_tongue AS child_mt
                          FROM eval_sessions s
                          JOIN children c ON c.id = s.child_id
                          WHERE s.id = ?");
    $sst->execute([$session_id]);
    $session = $sst->fetch();
    if (!$session) {
        return [
            'scoring'  => ['is_correct'=>false,'verdict'=>'wrong_slow','next_level'=>3,'should_stop'=>false,'reason'=>'session not found'],
            'question' => null,
        ];
    }

    $cur_level = (int) $q['level'];
    $age_yrs = round((float) calc_age_years($session['child_dob']), 1);
    $seq_so_far = (int) $q['seq_no'];

    // Pull recent history (excluding current question)
    $hist_st = db()->prepare("SELECT seq_no, level, question_type, prompt, user_answer, is_correct, time_seconds
                              FROM eval_questions
                              WHERE session_id = ? AND seq_no < ? AND is_correct IS NOT NULL
                              ORDER BY seq_no DESC LIMIT 5");
    $hist_st->execute([$session_id, $seq_so_far]);
    $history_lines = [];
    foreach (array_reverse($hist_st->fetchAll()) as $h) {
        $verdict = $h['is_correct'] ? '✓' : '✗';
        $history_lines[] = "  Q{$h['seq_no']} (L{$h['level']}): \""
                         . mb_substr((string)$h['prompt'], 0, 70) . "\" → \""
                         . mb_substr((string)$h['user_answer'], 0, 40) . "\" {$verdict} ({$h['time_seconds']}s)";
    }
    $history_text = empty($history_lines) ? "  (no prior questions)" : implode("\n", $history_lines);

    // Build acoustic block
    $acoustic_block = '';
    $is_voice = !empty($acoustic) && !empty($user_answer);
    if ($is_voice) {
        $sig = [];
        if (isset($acoustic['transcript_confidence']))    $sig[] = "transcript confidence " . round((float)$acoustic['transcript_confidence'] * 100) . "%";
        if (isset($acoustic['duration_sec']))             $sig[] = "spoke for " . round((float)$acoustic['duration_sec'], 1) . "s";
        if (isset($acoustic['wpm']))                       $sig[] = (int)$acoustic['wpm'] . " WPM";
        if (isset($acoustic['silence_ratio']))            $sig[] = round((float)$acoustic['silence_ratio'] * 100) . "% silence";
        if (isset($acoustic['time_to_first_speech_sec'])) $sig[] = "took " . round((float)$acoustic['time_to_first_speech_sec'], 1) . "s to start";
        $acoustic_block = "\n\nAcoustic signals (child SPOKE this answer): " . implode(", ", $sig)
                        . ".\nBe lenient on speech-to-text artifacts ('rotee'='roti'). High silence + slow start = struggle, not necessarily wrong.";
    }

    // Build the combined prompt
    $sys = "You are running an adaptive speech & language voice interview for an Indian child. "
         . "ONE call from you must do TWO things:\n\n"
         . "TASK 1 — SCORE the child's answer to the previous question.\n"
         . "  - Be lenient on Indian English spelling, speech-to-text artifacts, child shorthand.\n"
         . "  - Accept semantic equivalents.\n\n"
         . "TASK 2 — GENERATE TWO candidate next questions:\n"
         . "  - 'q_harder':  question one level above current (for if the answer was correct & fast)\n"
         . "  - 'q_same':    question at the SAME level (for if mixed/slow/wrong)\n"
         . "  Server picks the right one based on its own logic.\n\n"
         . "QUESTION GENERATION RULES:\n"
         . "  - NO multiple choice. NO 'pick from options'. Voice interview only.\n"
         . "  - Question must be answerable by SPEAKING in 1-2 short sentences.\n"
         . "  - NO 'type' / 'write' / 'pick' wording — say 'tell me' / 'say' / 'name' / 'bolo' / 'batao'.\n"
         . "  - Indian context: Indian names, foods (रोटी, दाल), family (दीदी, दादी), settings (स्कूल, मंदिर, बाज़ार).\n"
         . "  - LANGUAGE — VERY IMPORTANT FOR TTS:\n"
         . "      - If mother tongue is Hindi: write the question in **Devanagari ONLY**. Romanized Hindi sounds wrong (TTS spells letter-by-letter).\n"
         . "      - If mother tongue is English: write in **plain English ONLY**.\n"
         . "  - Don't repeat any concept from recent history.\n"
         . "  - Be warm and brief.\n\n"
         . "QUESTION TYPES (use ONLY these two):\n"
         . "  - 'naming': ask a direct question; child says one word or short phrase. ('हम सुबह नाश्ते में क्या खाते हैं?')\n"
         . "  - 'describe': open question for L4+. ('अपने पसंदीदा त्योहार के बारे में बताओ।')\n\n"
         . "DO NOT use fill-in-the-blank questions (sentences with ___). They confuse young/disabled children.\n\n"
         . "LEVELS — generate q_harder and q_same accordingly.\n"
         . "  L1: single sounds, basic words (~18mo-3yr)\n"
         . "  L2: common nouns, 2-word phrases (~3-4yr)\n"
         . "  L3: short sentences, basic grammar (~4-6yr)\n"
         . "  L4: complex sentences, sequencing (~6-8yr)\n"
         . "  L5: advanced narration, abstract vocab (~8-12yr)\n\n"
         . "OUTPUT — JSON only, no fences, no extra text:\n"
         . "{\n"
         . "  \"is_correct\": true|false,\n"
         . "  \"reason\": \"one short sentence\",\n"
         . "  \"q_harder\": {\n"
         . "    \"type\": \"naming\"|\"describe\",\n"
         . "    \"prompt\": \"...\",\n"
         . "    \"expected\": \"answer1|answer2|...\",\n"
         . "    \"level\": " . min(5, $cur_level + 1) . "\n"
         . "  },\n"
         . "  \"q_same\": {\n"
         . "    \"type\": \"naming\"|\"describe\",\n"
         . "    \"prompt\": \"...\",\n"
         . "    \"expected\": \"answer1|answer2|...\",\n"
         . "    \"level\": " . $cur_level . "\n"
         . "  }\n"
         . "}";

    $user = "Child: {$session['child_name']}, age {$age_yrs}, mother tongue: " . ($session['child_mt'] ?: 'English') . "\n"
          . "Current level: L{$cur_level}\n\n"
          . "Recent history:\n{$history_text}\n\n"
          . "JUST-ASKED QUESTION (Q{$seq_so_far}): {$q['prompt']}\n"
          . "  Type: {$q['question_type']}\n"
          . "  Expected: {$q['expected']}\n"
          . "  Child's answer: \"" . trim($user_answer) . "\"\n"
          . "  Response time: {$time_seconds}s"
          . $acoustic_block
          . "\n\nNow score AND generate the two candidate next questions. Return JSON only.";

    // ONE call to Haiku
    $j = eval_haiku_json($sys, $user, 800, 0.5);

    // We need at least is_correct. If even that's missing, fall back to two-step.
    if (!$j || !isset($j['is_correct'])) {
        error_log('[eval_score_and_next] Haiku returned no is_correct; falling back to two-step. Got: ' . json_encode($j));
        $score = eval_score_and_decide($session_id, $question_id, $user_answer, $time_seconds, $acoustic);
        if ($score['should_stop']) {
            return ['scoring' => $score, 'question' => null];
        }
        $next_q = eval_next_question($session_id, true);
        if (!$next_q) {
            // ALSO failed — use canned fallback so the eval doesn't dead-end.
            $is_hindi = preg_match('/[\x{0900}-\x{097F}]/u', (string)$session['child_mt'])
                      || stripos((string)$session['child_mt'], 'hindi') !== false;
            $canned = eval_canned_question((int)$score['next_level'], $is_hindi);
            $new_seq = $seq_so_far + 1;
            db()->prepare("INSERT INTO eval_questions
                           (session_id, seq_no, level, question_type, prompt, options_json, expected, image_concept)
                           VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)")
               ->execute([$session_id, $new_seq, (int)$score['next_level'], $canned['type'], $canned['prompt'], $canned['expected']]);
            $new_qid = (int) db()->lastInsertId();
            db()->prepare("UPDATE eval_sessions SET questions_asked = ? WHERE id = ?")
               ->execute([$new_seq, $session_id]);
            $next_q = [
                'question_id'   => $new_qid,
                'seq_no'        => $new_seq,
                'level'         => (int)$score['next_level'],
                'type'          => $canned['type'],
                'prompt'        => $canned['prompt'],
                'options'       => null,
                'image_concept' => null,
            ];
        }
        return ['scoring' => $score, 'question' => $next_q];
    }

    $is_correct = !empty($j['is_correct']);

    // Verdict (same logic as legacy)
    $fast = $time_seconds <= 8;
    if ($is_correct &&  $fast)  $verdict = 'correct_fast';
    elseif ($is_correct && !$fast) $verdict = 'correct_slow';
    elseif (!$is_correct && $fast) $verdict = 'wrong_fast';
    else                            $verdict = 'wrong_slow';

    // Next level (same logic as legacy)
    $next_level = $cur_level;
    if ($verdict === 'correct_fast')      $next_level = min(5, $cur_level + 1);
    elseif ($verdict === 'correct_slow')  $next_level = $cur_level;
    elseif ($verdict === 'wrong_fast')    $next_level = $cur_level;
    elseif ($verdict === 'wrong_slow')    $next_level = max(1, $cur_level - 1);

    // Stop logic (same as legacy) — minimum 8 questions before adaptive stop
    $should_stop = false; $stop_reason = '';
    if ($seq_so_far >= 15) {
        $should_stop = true; $stop_reason = 'hit hard cap of 15 questions';
    } elseif ($seq_so_far >= 8) {
        $hst = db()->prepare("SELECT level, is_correct FROM eval_questions
                              WHERE session_id = ? AND seq_no < ? AND is_correct IS NOT NULL
                              ORDER BY seq_no DESC LIMIT 2");
        $hst->execute([$session_id, $seq_so_far]);
        $prev2 = array_reverse($hst->fetchAll());
        $recent = $prev2;
        $recent[] = ['level' => $cur_level, 'is_correct' => $is_correct ? 1 : 0];
        if (count($recent) === 3) {
            $levels    = array_column($recent, 'level');
            $corrects  = array_column($recent, 'is_correct');
            if (count(array_unique($levels)) === 1 && count(array_unique($corrects)) === 1) {
                $should_stop = true;
                $stop_reason = "3 consecutive at L{$cur_level} with consistent " . ($corrects[0] ? 'correct' : 'incorrect');
            }
        }
    }

    // Persist scoring on the just-answered question row
    db()->prepare("UPDATE eval_questions
                   SET answered_at = CURRENT_TIMESTAMP,
                       time_seconds = ?,
                       user_answer = ?,
                       answer_mode = ?,
                       acoustic_json = ?,
                       is_correct = ?,
                       ai_verdict = ?,
                       next_level = ?
                   WHERE id = ?")
       ->execute([
           $time_seconds,
           $user_answer,
           $is_voice ? 'voice' : 'text',
           $is_voice ? json_encode($acoustic, JSON_UNESCAPED_UNICODE) : null,
           $is_correct ? 1 : 0,
           $verdict,
           $next_level,
           $question_id,
       ]);

    // Update session level (only if not stopping)
    if (!$should_stop) {
        db()->prepare("UPDATE eval_sessions SET current_level = ? WHERE id = ?")
           ->execute([$next_level, $session_id]);
    }

    $scoring = [
        'is_correct'  => $is_correct,
        'verdict'     => $verdict,
        'next_level'  => $next_level,
        'should_stop' => $should_stop,
        'reason'      => $stop_reason,
    ];

    if ($should_stop) {
        return ['scoring' => $scoring, 'question' => null];
    }

    // Pick a candidate next-question. Be flexible — Haiku may have omitted one,
    // returned mismatched levels, or used a slightly different shape. Try in order:
    //   1. Best matching candidate (level closest to next_level)
    //   2. Either candidate, even if level is off by more
    //   3. Fresh eval_next_question() call (legacy path) as last resort
    $candidate = null;
    $candidates = [];
    foreach (['q_harder', 'q_same'] as $key) {
        if (!empty($j[$key]) && is_array($j[$key]) && !empty($j[$key]['prompt'])) {
            $clvl = isset($j[$key]['level']) ? (int)$j[$key]['level'] : ($key === 'q_harder' ? min(5, $cur_level+1) : $cur_level);
            $candidates[] = ['data' => $j[$key], 'level' => $clvl, 'distance' => abs($clvl - $next_level)];
        }
    }
    if (!empty($candidates)) {
        // Pick the one with smallest distance from next_level
        usort($candidates, function($a, $b) { return $a['distance'] <=> $b['distance']; });
        $candidate = $candidates[0]['data'];
    }

    if (!$candidate || empty($candidate['type']) || empty($candidate['prompt'])) {
        // Last resort: fresh call
        error_log('[eval_score_and_next] no usable candidate; falling back to eval_next_question. Candidates: ' . json_encode(array_map(function($c){return ['lvl'=>$c['level'],'has_prompt'=>!empty($c['data']['prompt'])];}, $candidates)));
        $next_q = eval_next_question($session_id, true);
        if ($next_q) {
            return ['scoring' => $scoring, 'question' => $next_q];
        }

        // Even legacy generation failed — use a no-API fallback question so the eval never dead-ends.
        // Pick a generic level-appropriate question from a small canned set (Hindi or English).
        $is_hindi = preg_match('/[\x{0900}-\x{097F}]/u', (string)$session['child_mt'])
                  || stripos((string)$session['child_mt'], 'hindi') !== false;
        $canned = eval_canned_question($next_level, $is_hindi);
        $new_seq = $seq_so_far + 1;
        db()->prepare("INSERT INTO eval_questions
                       (session_id, seq_no, level, question_type, prompt, options_json, expected, image_concept)
                       VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)")
           ->execute([$session_id, $new_seq, $next_level, $canned['type'], $canned['prompt'], $canned['expected']]);
        $new_qid = (int) db()->lastInsertId();
        db()->prepare("UPDATE eval_sessions SET questions_asked = ? WHERE id = ?")
           ->execute([$new_seq, $session_id]);
        return [
            'scoring'  => $scoring,
            'question' => [
                'question_id'   => $new_qid,
                'seq_no'        => $new_seq,
                'level'         => $next_level,
                'type'          => $canned['type'],
                'prompt'        => $canned['prompt'],
                'options'       => null,
                'image_concept' => null,
            ],
        ];
    }

    // Validate type + clean up
    $type = $candidate['type'];
    if (!in_array($type, ['naming', 'describe'], true)) $type = 'naming';

    // Persist the new question row
    $new_seq = $seq_so_far + 1;
    db()->prepare("INSERT INTO eval_questions
                   (session_id, seq_no, level, question_type, prompt, options_json, expected, image_concept)
                   VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)")
       ->execute([
           $session_id,
           $new_seq,
           $next_level,
           $type,
           (string) $candidate['prompt'],
           (string) ($candidate['expected'] ?? ''),
       ]);
    $new_qid = (int) db()->lastInsertId();

    db()->prepare("UPDATE eval_sessions SET questions_asked = ? WHERE id = ?")
       ->execute([$new_seq, $session_id]);

    return [
        'scoring'  => $scoring,
        'question' => [
            'question_id'   => $new_qid,
            'seq_no'        => $new_seq,
            'level'         => $next_level,
            'type'          => $type,
            'prompt'        => (string) $candidate['prompt'],
            'options'       => null,
            'image_concept' => null,
        ],
    ];
}

/**
 * Finalise the session: pick final level, generate report + sample exercise, mark completed.
 */
function eval_finalise(int $session_id): bool {
    $sst = db()->prepare("SELECT s.*, c.name AS child_name, c.dob AS child_dob,
                                 c.gender AS child_gender, c.mother_tongue AS child_mt
                          FROM eval_sessions s
                          JOIN children c ON c.id = s.child_id
                          WHERE s.id = ?");
    $sst->execute([$session_id]);
    $session = $sst->fetch();
    if (!$session) return false;

    // Pull all questions for context
    $qst = db()->prepare("SELECT seq_no, level, question_type, prompt, user_answer, is_correct, time_seconds
                          FROM eval_questions WHERE session_id = ? ORDER BY seq_no");
    $qst->execute([$session_id]);
    $questions = $qst->fetchAll();

    if (empty($questions)) return false;

    // Determine final level: average of last 3 levels (or all if fewer)
    $tail = array_slice($questions, -3);
    $final_level = (int) round(array_sum(array_column($tail, 'level')) / count($tail));

    // Compute overall pct (correct / total)
    $total = count($questions);
    $right = array_sum(array_column($questions, 'is_correct'));
    $final_pct = $total > 0 ? (int) round(100 * $right / $total) : 0;

    // Build context for report
    $age_yrs = round((float) calc_age_years($session['child_dob']), 1);
    $level_info = eval_speech_level_desc($final_level);

    $hist_lines = [];
    foreach ($questions as $q) {
        $v = $q['is_correct'] ? '✓' : '✗';
        $hist_lines[] = "  Q{$q['seq_no']} L{$q['level']} ({$q['question_type']}): {$q['prompt']} → \""
                      . mb_substr((string)$q['user_answer'], 0, 60) . "\" {$v} ({$q['time_seconds']}s)";
    }
    $hist_text = implode("\n", $hist_lines);

    $sys = "You are a senior speech-language pathologist at EmpowerStudents — a clinical service "
         . "providing evaluations AND professional therapy programs (Speech Therapy, language "
         . "intervention, Occupational Therapy) for Indian children.\n\n"
         . "Write a parent-facing report for this speech & language evaluation. Tone: empathetic, "
         . "expert, reassuring, action-oriented. Position therapy as something WE will do for "
         . "the child (in person at our centre or via video sessions), with parents playing a "
         . "small daily supportive role at home (5 mins/day) — NOT the primary teaching role.\n\n"
         . "Output JSON only:\n"
         . "{\n"
         . "  \"report_md\": \"## Where {ChildName} is now\\n... ## Strengths ... ## Areas to develop ... "
         . "## Recommended next step\\nSentence inviting the parent to start our 1-week speech plan "
         . "(₹99) for personalised daily practice + check-ins from our therapists. \"\n"
         . "  \"sample_exercise_md\": \"## Today's sample exercise (free preview)\\n A SINGLE concrete "
         . "10-minute activity the parent can do TODAY with their child to build the next-level skill. "
         . "Specific, Indian context, age-appropriate. Format: ### What you need ### How to play ### What to watch for\"\n"
         . "}\n\n"
         . "RULES:\n"
         . "- report_md under 350 words. sample_exercise_md under 250 words.\n"
         . "- Be specific about the level (L1-L5) using the level name, not just the number.\n"
         . "- DO NOT mention specific prices for therapy in the report — only mention the ₹99 weekly plan.\n"
         . "- Use child's actual name throughout. Indian context.\n"
         . "- No reasoning trace. Final clean text only.\n";

    $user = "Child: {$session['child_name']}, age {$age_yrs} yrs, "
          . ($session['child_gender'] ?: 'unspecified') . ", mother tongue: "
          . ($session['child_mt'] ?: 'English') . "\n\n"
          . "Final level reached: L{$final_level} — {$level_info['name']}\n"
          . "Level description: {$level_info['desc']}\n"
          . "Age-equivalent: {$level_info['age_eq']}\n\n"
          . "Overall accuracy: {$right}/{$total} ({$final_pct}%)\n"
          . "Total questions: {$total}\n\n"
          . "Full question history:\n{$hist_text}\n\n"
          . "Now generate the report. JSON only.";

    $j = claude_json($sys, $user, 1800, 0.5);
    $report_md = $j['report_md'] ?? null;
    $sample_md = $j['sample_exercise_md'] ?? null;

    db()->prepare("UPDATE eval_sessions
                   SET status = 'completed',
                       completed_at = CURRENT_TIMESTAMP,
                       final_level = ?,
                       final_pct = ?,
                       report_md = ?,
                       sample_exercise_md = ?
                   WHERE id = ?")
       ->execute([$final_level, $final_pct, $report_md, $sample_md, $session_id]);

    return true;
}

/**
 * Whether a parent is eligible for a free evaluation (one-per-account, lifetime).
 */
function eval_free_eligible(int $parent_id): bool {
    $st = db()->prepare("SELECT free_eval_used_at FROM parents WHERE id = ?");
    $st->execute([$parent_id]);
    $used_at = $st->fetchColumn();
    return empty($used_at);
}

/** Mark the free eval as used. */
function eval_consume_free(int $parent_id): void {
    db()->prepare("UPDATE parents SET free_eval_used_at = CURRENT_TIMESTAMP WHERE id = ? AND free_eval_used_at IS NULL")
       ->execute([$parent_id]);
}
