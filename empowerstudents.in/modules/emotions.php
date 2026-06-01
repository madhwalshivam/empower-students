<?php
require_once __DIR__ . '/_questionnaire.php';
$child = module_require_child();
$age   = calc_age_years($child['dob']);
$band  = age_band($age);

$qs = [
    [
        'q'    => 'Can the child name basic feelings (happy, sad, angry, scared)?',
        'q_hi' => 'क्या बच्चा बुनियादी भावनाओं को नाम दे सकता है (खुश, उदास, गुस्सा, डरा हुआ)?',
        'type' => 'yesno', 'concern_if' => 'no',
    ],
    [
        'q'        => 'How well does the child recover after upset? (0 takes hours – 10 within minutes)',
        'q_hi'     => 'परेशान होने के बाद बच्चा कितनी जल्दी संभलता है? (0 घंटों लगते हैं – 10 कुछ मिनटों में)',
        'type'     => 'likert', 'min' => 0, 'max' => 10, 'concern_if' => '<=3',
        'scale'    => '0 = takes hours · 10 = within minutes',
        'scale_hi' => '0 = घंटों लगते हैं · 10 = कुछ मिनटों में',
    ],
    [
        'q'        => 'Frequency of emotional outbursts (last 2 weeks)? (0 none – 10 daily multiple)',
        'q_hi'     => 'पिछले 2 हफ़्तों में भावनात्मक प्रकोप कितनी बार? (0 कभी नहीं – 10 रोज़ कई बार)',
        'type'     => 'likert', 'min' => 0, 'max' => 10, 'concern_if' => '>=7',
        'scale'    => '0 = none · 10 = multiple times daily',
        'scale_hi' => '0 = कभी नहीं · 10 = रोज़ कई बार',
    ],
    [
        'q'    => 'Does the child show empathy towards others?',
        'q_hi' => 'क्या बच्चा दूसरों के प्रति सहानुभूति दिखाता है?',
        'type' => 'yesno', 'concern_if' => 'no',
    ],
    [
        'q'    => 'Is there frequent fear, worry or sadness reported?',
        'q_hi' => 'क्या बार-बार डर, चिंता या उदासी दिखाई देती है?',
        'type' => 'yesno', 'concern_if' => 'yes',
    ],
    [
        'q'    => 'Does the child seek comfort from a parent when upset?',
        'q_hi' => 'क्या परेशान होने पर बच्चा माता-पिता से सांत्वना चाहता है?',
        'type' => 'yesno', 'concern_if' => 'no',
    ],
    [
        'q'    => 'Is there self-injury (head-banging, hair pulling, biting self)?',
        'q_hi' => 'क्या बच्चा खुद को नुकसान पहुँचाता है (सिर मारना, बाल खींचना, खुद को काटना)?',
        'type' => 'yesno', 'concern_if' => 'yes', 'critical' => true,
    ],
];

if ($band === 'teen' || $band === 'preteen') {
    $qs[] = [
        'q'    => 'Has the child expressed feeling hopeless or worthless in the last month?',
        'q_hi' => 'क्या बच्चे ने पिछले महीने में निराश या व्यर्थ महसूस करने की बात कही है?',
        'type' => 'yesno', 'concern_if' => 'yes', 'critical' => true,
    ];
}

run_questionnaire($child, [
    'module_key'   => 'emotions',
    'title'        => 'Emotions',
    'title_hi'     => 'भावनाएँ',
    'intro'        => 'How does ' . e($child['name']) . ' name, regulate and express feelings?',
    'intro_hi'     => e($child['name']) . ' भावनाओं को कैसे पहचानते, नियंत्रित और व्यक्त करते हैं?',
    'questions'    => $qs,
    'ai_system'    => 'You are a child counsellor. Empathetic and gentle. Comment on emotion vocabulary, regulation, attachment, and any red-flags. Recommend a simple daily ritual (e.g. emotion check-in) and, if any critical flag is set, urge same-week professional consultation. 4–6 sentences.',
    'ai_user_tail' => 'Write the parent-facing summary now.',
]);
