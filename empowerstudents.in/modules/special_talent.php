<?php
require_once __DIR__ . '/_questionnaire.php';
$child = module_require_child();
$age = calc_age_years($child['dob']);
$band = age_band($age);

// Talent screener — focuses on islands of ability commonly seen in autism / ADHD.
// Likert 0-5 (0 = not at all, 5 = exceptional).
$qs = [
    ['q' => 'Drawing, painting, sculpting or visual art skills',
     'q_hi' => 'चित्रकला, पेंटिंग, मूर्तिकला या दृश्य कला में कौशल',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Music — sings in tune, plays an instrument, or remembers tunes after one listen',
     'q_hi' => 'संगीत — सुर में गाता है, वाद्य बजाता है, या एक बार सुनकर धुन याद कर लेता है',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Numbers — does sums in head, loves numbers, calendar/date calculations',
     'q_hi' => 'संख्याएँ — मन में जोड़-घटाव, संख्याओं से लगाव, तारीख़ों की गणना',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Memory — remembers facts, places, routes, names long after others forget',
     'q_hi' => 'स्मृति — तथ्य, जगह, रास्ते, नाम लंबे समय तक याद रखता है',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Mechanical / building — Lego, puzzles, dismantling and reassembling things',
     'q_hi' => 'यांत्रिक / निर्माण — लेगो, पहेलियाँ, चीज़ों को खोलना और जोड़ना',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Computers / coding / video-game strategy',
     'q_hi' => 'कंप्यूटर / कोडिंग / वीडियो-गेम रणनीति',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Sports or movement (dance, gymnastics, athletics, martial arts)',
     'q_hi' => 'खेल या शारीरिक गतिविधि (नृत्य, जिमनास्टिक, एथलेटिक्स, मार्शल आर्ट)',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Storytelling, writing, poetry or vivid imagination',
     'q_hi' => 'कहानी सुनाना, लेखन, कविता या जीवंत कल्पनाशक्ति',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Languages — picks up new words/accents quickly',
     'q_hi' => 'भाषाएँ — नए शब्द / उच्चारण जल्दी सीख लेता है',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Empathy / care — for younger kids, animals, or those in distress',
     'q_hi' => 'सहानुभूति / देखभाल — छोटे बच्चों, जानवरों, या परेशान लोगों के लिए',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Nature / science curiosity — loves animals, plants, space, experiments',
     'q_hi' => 'प्रकृति / विज्ञान में जिज्ञासा — जानवरों, पौधों, अंतरिक्ष, प्रयोगों में रुचि',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Leadership — others naturally follow him/her',
     'q_hi' => 'नेतृत्व — दूसरे स्वाभाविक रूप से उसका अनुसरण करते हैं',
     'type' => 'likert', 'min' => 0, 'max' => 5],
    ['q' => 'Has an unusually intense interest in a narrow topic? (trains, dinosaurs, maps, etc.)',
     'q_hi' => 'क्या किसी एक विषय में असामान्य रूप से गहरी रुचि है? (ट्रेन, डायनासोर, नक़्शे, आदि)',
     'type' => 'yesno'],
    ['q' => 'Briefly describe any special talent or strong interest you have noticed',
     'q_hi' => 'कोई विशेष प्रतिभा या गहरी रुचि जो आपने देखी हो — संक्षेप में बताएँ',
     'type' => 'text'],
];

run_questionnaire($child, [
    'module_key' => 'special_talent',
    'title'      => 'Special talent screener',
    'title_hi'   => 'विशेष प्रतिभा स्क्रीनर',
    'intro'      => 'Children — especially those on the autism spectrum or with ADHD — often have one or two areas where they shine far above peers. Spotting these early helps parents and teachers nurture them.',
    'intro_hi'   => 'बच्चे — विशेषकर ऑटिज़्म स्पेक्ट्रम या ADHD वाले — अक्सर एक-दो ऐसे क्षेत्रों में चमकते हैं जहाँ वे साथियों से कहीं आगे होते हैं। इन्हें जल्दी पहचानना माता-पिता और शिक्षकों को उनके पोषण में मदद करता है।',
    'questions'  => $qs,
    'max_per'    => 5,
    'ai_system'  => 'You are a child development specialist with experience in giftedness, autism and ADHD. Identify the child\'s top 2-3 talent areas and give parent-friendly, concrete suggestions to nurture each (Indian context, mix of free and low-cost ideas). If a narrow intense interest is reported, frame it as a strength to channel. Keep it warm and practical. Maximum 250 words. Plain text, no markdown.',
    'ai_user_tail' => 'Highlight the top strengths and how to nurture them at home and at school.',
]);
