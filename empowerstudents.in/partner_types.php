<?php
/**
 * includes/partner_types.php
 *
 * Single source of truth for per-partner-type configuration:
 * labels, copy, colors, and helper accessors.
 *
 * Types:
 *   pediatrician — original/default. Clinic + doctor.
 *   school       — primary/secondary school. School + principal/counsellor.
 *   coaching     — coaching institute. Institute + director.
 *   teacher      — individual teacher. School + teacher.
 *
 * Schema additions handled in partner_types_ensure_schema().
 */

function partner_types_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('partner_type', $names, true)) {
            // Default existing rows to pediatrician (since all current partners are docs)
            @db()->exec("ALTER TABLE partners ADD COLUMN partner_type TEXT DEFAULT 'pediatrician'");
        }
        if (!in_array('institution_name', $names, true)) {
            @db()->exec("ALTER TABLE partners ADD COLUMN institution_name TEXT");
        }
        if (!in_array('referrer_role', $names, true)) {
            @db()->exec("ALTER TABLE partners ADD COLUMN referrer_role TEXT");
        }
    } catch (Throwable $_) {}
}

/**
 * Returns the full type config for a given partner_type key.
 * Falls back to pediatrician for unknown types.
 */
function partner_type_config(string $type): array {
    static $configs = null;
    if ($configs === null) {
        $configs = [
            'pediatrician' => [
                'key'                 => 'pediatrician',
                'label_en'            => 'Pediatrician',
                'label_hi'            => 'पीडियाट्रिशियन',
                'institution_word_en' => 'Clinic',
                'institution_word_hi' => 'क्लिनिक',
                'referrer_word_en'    => 'Doctor',
                'referrer_word_hi'    => 'डॉक्टर',
                'recommended_by_en'   => 'Recommended by your pediatrician',
                'recommended_by_hi'   => 'आपके पीडियाट्रिशियन की अनुशंसा',
                'audience_en'         => 'patients',
                'audience_hi'         => 'मरीज़ों',
                'cta_en'              => 'Referred patients & families',
                'cta_hi'              => 'भेजे गए मरीज़ और परिवार',
                'why_partner_en'      => "I see many parents who are worried about their child's development. EmpowerStudents gives them a structured, AI-guided evaluation that I can review and use in our consultation.",
                'why_partner_hi'      => "मैं अक्सर ऐसे माता-पिता को देखता/देखती हूँ जो अपने बच्चे के विकास को लेकर चिंतित हैं। EmpowerStudents उन्हें एक संरचित, AI-आधारित मूल्यांकन देता है जिसे मैं समीक्षा और परामर्श में उपयोग कर सकता/सकती हूँ।",
                'badge_color'         => 'emerald',
                'badge_emoji'         => '🩺',
            ],
            'school' => [
                'key'                 => 'school',
                'label_en'            => 'School',
                'label_hi'            => 'स्कूल',
                'institution_word_en' => 'School',
                'institution_word_hi' => 'स्कूल',
                'referrer_word_en'    => 'Principal',
                'referrer_word_hi'    => 'प्रधानाचार्य',
                'recommended_by_en'   => 'Recommended by your school',
                'recommended_by_hi'   => 'आपके स्कूल की अनुशंसा',
                'audience_en'         => "students' parents",
                'audience_hi'         => "विद्यार्थियों के परिवार",
                'cta_en'              => 'Students whose parents joined',
                'cta_hi'              => 'जिन विद्यार्थियों के परिवार जुड़े',
                'why_partner_en'      => "Our school cares deeply about the holistic development of every child. EmpowerStudents helps parents understand their child's cognitive, emotional and academic profile — a perfect partner to what we teach in the classroom.",
                'why_partner_hi'      => "हमारा स्कूल हर बच्चे के समग्र विकास की परवाह करता है। EmpowerStudents माता-पिता को उनके बच्चे की संज्ञानात्मक, भावनात्मक और शैक्षणिक प्रोफ़ाइल को समझने में मदद करता है — जो हम कक्षा में सिखाते हैं उसके लिए एक उत्तम सहयोगी।",
                'badge_color'         => 'blue',
                'badge_emoji'         => '🏫',
            ],
            'coaching' => [
                'key'                 => 'coaching',
                'label_en'            => 'Coaching Institute',
                'label_hi'            => 'कोचिंग सेंटर',
                'institution_word_en' => 'Institute',
                'institution_word_hi' => 'संस्थान',
                'referrer_word_en'    => 'Director',
                'referrer_word_hi'    => 'निदेशक',
                'recommended_by_en'   => 'Recommended by your coaching institute',
                'recommended_by_hi'   => 'आपके कोचिंग संस्थान की अनुशंसा',
                'audience_en'         => "students' parents",
                'audience_hi'         => "विद्यार्थियों के परिवार",
                'cta_en'              => 'Students whose parents joined',
                'cta_hi'              => 'जिन विद्यार्थियों के परिवार जुड़े',
                'why_partner_en'      => "Beyond test prep, we care about each student's overall well-being and learning style. EmpowerStudents helps parents see their child's profile holistically — confidence, focus, emotional balance — alongside academics.",
                'why_partner_hi'      => "परीक्षा की तैयारी से परे, हम हर विद्यार्थी की समग्र भलाई और सीखने की शैली की परवाह करते हैं। EmpowerStudents माता-पिता को उनके बच्चे की प्रोफ़ाइल को समग्र रूप से देखने में मदद करता है।",
                'badge_color'         => 'indigo',
                'badge_emoji'         => '📚',
            ],
            'teacher' => [
                'key'                 => 'teacher',
                'label_en'            => 'Teacher',
                'label_hi'            => 'शिक्षक/शिक्षिका',
                'institution_word_en' => 'School',
                'institution_word_hi' => 'स्कूल',
                'referrer_word_en'    => 'Teacher',
                'referrer_word_hi'    => 'शिक्षक/शिक्षिका',
                'recommended_by_en'   => 'Recommended by your teacher',
                'recommended_by_hi'   => 'आपकी शिक्षिका/शिक्षक की अनुशंसा',
                'audience_en'         => "students' parents",
                'audience_hi'         => "विद्यार्थियों के परिवार",
                'cta_en'              => 'Students whose parents joined',
                'cta_hi'              => 'जिन विद्यार्थियों के परिवार जुड़े',
                'why_partner_en'      => "As a teacher, I see students daily and can spot when something feels different — a focus gap, anxiety, a hidden strength. EmpowerStudents helps parents act on what I notice, with AI-guided insight and expert support.",
                'why_partner_hi'      => "एक शिक्षक/शिक्षिका के रूप में, मैं विद्यार्थियों को रोज़ देखता/देखती हूँ और कुछ अलग महसूस होने पर पहचान सकता/सकती हूँ। EmpowerStudents माता-पिता को मदद करता है उस पर कार्रवाई करने में जो मैं नोटिस करता/करती हूँ।",
                'badge_color'         => 'amber',
                'badge_emoji'         => '👩‍🏫',
            ],
        ];
    }
    return $configs[$type] ?? $configs['pediatrician'];
}

/**
 * Returns a localized config: applies _en or _hi suffix to relevant fields
 * and returns a flat array.
 */
function partner_type_localized(string $type, bool $is_hindi): array {
    $cfg = partner_type_config($type);
    $sfx = $is_hindi ? '_hi' : '_en';
    return [
        'key'             => $cfg['key'],
        'label'           => $cfg['label' . $sfx],
        'institution_word'=> $cfg['institution_word' . $sfx],
        'referrer_word'   => $cfg['referrer_word' . $sfx],
        'recommended_by'  => $cfg['recommended_by' . $sfx],
        'audience'        => $cfg['audience' . $sfx],
        'cta_word'        => $cfg['cta' . $sfx],
        'why_partner'     => $cfg['why_partner' . $sfx],
        'badge_color'     => $cfg['badge_color'],
        'badge_emoji'     => $cfg['badge_emoji'],
    ];
}

/**
 * Returns all 4 types — for use in admin/signup dropdowns.
 */
function partner_types_all(): array {
    return [
        'pediatrician' => partner_type_config('pediatrician'),
        'school'       => partner_type_config('school'),
        'coaching'     => partner_type_config('coaching'),
        'teacher'      => partner_type_config('teacher'),
    ];
}
