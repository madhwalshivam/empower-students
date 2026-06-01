<?php
/**
 * includes/comprehensive_report_v3.php
 *
 * v3 Comprehensive Report — the deliverable that goes to:
 *   1. Parent's dashboard (PDF download)
 *   2. Pediatrician's email/WhatsApp (if referred)
 *   3. Internal psychologist review queue
 *
 * Sections (in order):
 *   COVER  — clinic+doctor header (if pediatrician referred), parent details, date
 *   ONE-LINE — Leda summary in Hindi + English (+ QR codes for audio if real PDF)
 *   LISTING — 9-area table with index/severity/urgency/course-day
 *   EXPANSION — per-area: parent's quoted phrases + the finding + what we noticed
 *   OVERALL  — burden index, top-3 urgent
 *   COURSE PLAN — 7 days, which day addresses which area
 *   NARRATIVE — "your story in your words" (existing pr_finalise output)
 *   NEXT  — psychologist call, course CTA, helpline if safety flag
 *   FOOTER — issuer info, session id, disclaimers
 *
 * Public API:
 *   comprehensive_v3_generate(int $session_id, bool $force = false): array
 *     Same return shape as v2.
 *
 *   comprehensive_v3_html(array $session, array $listing, array $parent, ?array $partner): string
 *     Returns the full HTML body — used by both PDF render and web view.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/leda_tts.php';
require_once __DIR__ . '/parent_eval_v3.php';
require_once __DIR__ . '/parent_reflect_home_climate.php';


/**
 * Generate v3 comprehensive report — listing-first, narrative-secondary.
 */
function comprehensive_v3_generate(int $session_id, bool $force = false): array {
    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'error' => 'Session not found'];
    if ($session['status'] !== 'completed') return ['ok' => false, 'error' => 'Session not completed'];

    if (!$force && !empty($session['report_pdf_path']) && !empty($session['summary_audio_hi'])) {
        return ['ok' => true, 'pdf_path' => $session['report_pdf_path'], 'cached' => true];
    }

    // Ensure v3 listing exists
    if (empty($session['v3_listing_json'])) {
        pr_v3_generate_listing($session_id);
        $st->execute([$session_id]);
        $session = $st->fetch();
    }
    $listing = json_decode((string)($session['v3_listing_json'] ?? ''), true) ?: [];
    if (empty($listing['areas'])) {
        return ['ok' => false, 'error' => 'Listing generation failed'];
    }

    $language = $listing['language'] ?? 'hi';

    // Parent
    $pst = db()->prepare("SELECT id, name, whatsapp, partner_id FROM parents WHERE id = ?");
    $pst->execute([(int)$session['parent_id']]);
    $parent = $pst->fetch() ?: [];

    // Partner (referring pediatrician) if any
    $partner = null;
    if (!empty($parent['partner_id'])) {
        $rst = db()->prepare("SELECT * FROM partners WHERE id = ?");
        $rst->execute([(int)$parent['partner_id']]);
        $partner = $rst->fetch() ?: null;
    }

    // Generate the one-line bilingual summary (replaces v2 _generate_summary)
    $summary = _comprehensive_v3_summary($session, $listing, $parent, $language);
    if (empty($summary['hi']) || empty($summary['en'])) {
        return ['ok' => false, 'error' => 'Summary generation failed'];
    }

    // Synthesize Leda voice for both
    $audio_hi = leda_tts_synthesize($summary['hi'], 'hi');
    $audio_en = leda_tts_synthesize($summary['en'], 'en');

    // Build HTML
    $html = comprehensive_v3_html($session, $listing, $parent, $partner, $summary, $language);

    // Persist HTML
    $base_dir_fs  = __DIR__ . '/../reports';
    $base_dir_web = '/reports';
    @mkdir($base_dir_fs, 0775, true);

    $fname_base = 'eval-' . $session_id . '-v3-' . substr(hash('sha256', $session_id . '|' . ($session['parent_id'] ?? '')), 0, 24);
    $path_html_fs  = $base_dir_fs . '/' . $fname_base . '.html';
    $path_html_web = $base_dir_web . '/' . $fname_base . '.html';
    file_put_contents($path_html_fs, $html);
    @chmod($path_html_fs, 0644);

    // Try real PDF via mPDF
    $pdf_web = null;
    $mpdf_autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($mpdf_autoload)) {
        try {
            require_once $mpdf_autoload;
            if (class_exists('\\Mpdf\\Mpdf')) {
                $pdf_path_fs = $base_dir_fs . '/' . $fname_base . '.pdf';
                $mpdf = new \Mpdf\Mpdf([
                    'mode'         => 'utf-8',
                    'format'       => 'A4',
                    'margin_left'  => 14, 'margin_right'  => 14,
                    'margin_top'   => 14, 'margin_bottom' => 14,
                    'tempDir'      => sys_get_temp_dir(),
                    'default_font' => 'dejavusans',
                ]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($pdf_path_fs, 'F');
                @chmod($pdf_path_fs, 0644);
                $pdf_web = $base_dir_web . '/' . $fname_base . '.pdf';
            }
        } catch (Throwable $e) {
            error_log('[comprehensive_v3 mPDF] ' . $e->getMessage());
        }
    }

    // Or wkhtmltopdf if available (dev environment)
    if (!$pdf_web) {
        $wkpath = trim((string)@shell_exec('command -v wkhtmltopdf 2>/dev/null'));
        if ($wkpath && is_executable($wkpath)) {
            $pdf_path_fs = $base_dir_fs . '/' . $fname_base . '.pdf';
            $cmd = escapeshellcmd($wkpath)
                 . ' --enable-local-file-access --encoding utf-8 --print-media-type '
                 . ' --margin-top 14mm --margin-bottom 14mm --margin-left 14mm --margin-right 14mm '
                 . escapeshellarg($path_html_fs) . ' ' . escapeshellarg($pdf_path_fs)
                 . ' 2>&1';
            $out = shell_exec($cmd);
            if (file_exists($pdf_path_fs) && filesize($pdf_path_fs) > 1000) {
                @chmod($pdf_path_fs, 0644);
                $pdf_web = $base_dir_web . '/' . $fname_base . '.pdf';
            }
        }
    }

    $final_path = $pdf_web ?: $path_html_web;

    // Save back to session
    db()->prepare("UPDATE parent_reflect_sessions
                   SET report_pdf_path = ?,
                       summary_text_hi = ?, summary_text_en = ?,
                       summary_audio_hi = ?, summary_audio_en = ?,
                       pdf_generated_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$final_path, $summary['hi'], $summary['en'], $audio_hi, $audio_en, $session_id]);

    return [
        'ok'            => true,
        'pdf_path'      => $final_path,
        'html_path'     => $path_html_web,
        'summary_hi'    => $summary['hi'],
        'summary_en'    => $summary['en'],
        'audio_hi'      => $audio_hi,
        'audio_en'      => $audio_en,
        'used_real_pdf' => $pdf_web !== null,
    ];
}


function _comprehensive_v3_summary(array $session, array $listing, array $parent, string $language): array {
    $convo_text = '';
    $st = db()->prepare("SELECT question, transcript FROM parent_reflect_turns
                          WHERE session_id = ? AND transcript IS NOT NULL AND transcript != ''
                          ORDER BY turn_no ASC");
    $st->execute([(int)$session['id']]);
    foreach ($st->fetchAll() as $r) {
        $q = trim((string)$r['question']); $a = trim((string)$r['transcript']);
        if ($q !== '') $convo_text .= "AI: " . mb_substr($q, 0, 200) . "\n";
        if ($a !== '') $convo_text .= "Parent: " . mb_substr($a, 0, 300) . "\n";
    }
    if (mb_strlen($convo_text) > 5000) $convo_text = mb_substr($convo_text, 0, 5000) . "\n[...]";

    $top_urgent = $listing['top_3_urgent_areas'] ?? [];
    $overall = (int)($listing['overall_index'] ?? 50);
    $one_liner = (string)($listing['one_line_summary'] ?? '');

    $sys = "You are a clinical psychologist writing a 4-5 sentence bilingual headline summary for a parent's evaluation report. "
         . "It will be:\n"
         . "  1. Printed at the top of the PDF\n"
         . "  2. Spoken aloud in Hindi+English Leda voice\n\n"
         . "Output JSON only:\n"
         . "{\n"
         . "  \"hindi\": \"4-5 sentences in warm Hindi (Devanagari, respectful 'आप'). NAME a real phrase from their answers. Acknowledge what's heavy but ALSO acknowledge a real strength you noticed. End with: 'पूरी detail अगले page पर है — और 7-day course उन्हीं areas पर काम करेगा।'\",\n"
         . "  \"english\": \"Same content in warm English. End with: 'Full detail is in the pages that follow — and the 7-day course addresses exactly these areas.'\"\n"
         . "}\n\n"
         . "CRITICAL: Each must be readable in under 70 seconds (Hindi ~140 words, English ~110 words). No advice. Honest, never falsely cheerful.";

    $usr = "Listing summary: $one_liner\n"
         . "Overall burden index: $overall/100\n"
         . "Top 3 urgent areas: " . implode(', ', $top_urgent) . "\n\n"
         . "=== Transcript ===\n$convo_text\n\nProduce the JSON now.";

    $resp = function_exists('claude_chat') ? claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1500, 0.5) : '';
    $clean = trim((string)$resp);
    if ($clean === '') return ['hi' => '', 'en' => ''];
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }
    $j = json_decode($clean, true);
    return [
        'hi' => trim((string)($j['hindi']  ?? '')),
        'en' => trim((string)($j['english'] ?? '')),
    ];
}


/**
 * Render the v3 comprehensive HTML report.
 */
function comprehensive_v3_html(array $session, array $listing, array $parent, ?array $partner, array $summary, string $language): string {
    $is_hindi = $language === 'hi';
    $areas_def = pr_v3_areas();

    $parent_name = htmlspecialchars($parent['name'] ?? 'Parent');
    $whatsapp    = htmlspecialchars($parent['whatsapp'] ?? '');
    $done_date   = htmlspecialchars(date('F j, Y · g:i a', strtotime((string)$session['completed_at'] . ' UTC')));
    $session_id  = (int)$session['id'];

    // Build cover header — pediatrician branding if referred
    $cover_html = '<div class="cover">';
    $cover_html .= '<div class="cover-title">PARENT EVALUATION REPORT</div>';
    $cover_html .= '<div class="cover-subtitle">' . ($is_hindi ? 'समग्र मूल्यांकन — 9 क्षेत्रों में' : 'Comprehensive Listing — across 9 life areas') . '</div>';

    if ($partner) {
        $clinic_name = htmlspecialchars($partner['name'] ?? '');
        $doc_name = htmlspecialchars($partner['contact_name'] ?? $clinic_name);
        $doc_credentials = htmlspecialchars($partner['doctor_credentials'] ?? 'Pediatrician');
        $cover_html .= '<div class="partner-strip">';
        $cover_html .= '<div class="partner-row">';
        if (!empty($partner['clinic_image_path'])) {
            $cover_html .= '<img src="' . htmlspecialchars($partner['clinic_image_path']) . '" class="clinic-img" alt="">';
        } else {
            $cover_html .= '<div class="clinic-img-ph">[Clinic logo]</div>';
        }
        $cover_html .= '<div class="partner-text">';
        $cover_html .= '<div class="partner-label">REFERRED BY</div>';
        $cover_html .= '<div class="partner-clinic">' . $clinic_name . '</div>';
        $cover_html .= '<div class="partner-doc">' . $doc_name . ' · ' . $doc_credentials . '</div>';
        if (!empty($partner['city'])) {
            $cover_html .= '<div class="partner-city">' . htmlspecialchars($partner['city']) . '</div>';
        }
        $cover_html .= '</div>';
        if (!empty($partner['doctor_image_path'])) {
            $cover_html .= '<img src="' . htmlspecialchars($partner['doctor_image_path']) . '" class="doc-img" alt="">';
        } else {
            $cover_html .= '<div class="doc-img-ph">[Doctor]</div>';
        }
        $cover_html .= '</div>';
        $cover_html .= '</div>';
    }

    $cover_html .= '<div class="parent-block">';
    $cover_html .= '<div><strong>Parent:</strong> ' . $parent_name . '</div>';
    $cover_html .= '<div><strong>Contact:</strong> ' . $whatsapp . '</div>';
    $cover_html .= '<div><strong>Completed:</strong> ' . $done_date . '</div>';
    $cover_html .= '<div><strong>Report ID:</strong> EMP-' . str_pad((string)$session_id, 6, '0', STR_PAD_LEFT) . '</div>';
    $cover_html .= '</div>';
    $cover_html .= '</div>';

    // ─── Summary block (Leda voice) ───
    $summary_block = '<div class="summary-box">';
    $summary_block .= '<div class="summary-title">🌿 ' . ($is_hindi ? 'मुख्य सारांश — Leda की आवाज़ में' : 'Headline Summary — in Leda voice') . '</div>';
    $summary_block .= '<div class="lang-label">हिंदी</div>';
    $summary_block .= '<p class="summary-hi">' . nl2br(htmlspecialchars($summary['hi'] ?? '')) . '</p>';
    $summary_block .= '<div class="lang-label">English</div>';
    $summary_block .= '<p class="summary-en">' . nl2br(htmlspecialchars($summary['en'] ?? '')) . '</p>';
    $summary_block .= '<div class="summary-note">📱 ' . ($is_hindi ? 'Voice audio आपके evaluation page पर सुनें।' : 'Voice audio is on your evaluation page online.') . '</div>';
    $summary_block .= '</div>';

    // ─── 9-area listing table ───
    $sev_color = [
        'critical' => '#dc2626',
        'high'     => '#ea580c',
        'moderate' => '#d97706',
        'low'      => '#059669',
    ];
    $sev_label = $is_hindi
        ? ['critical' => 'गंभीर', 'high' => 'भारी', 'moderate' => 'मध्यम', 'low' => 'हल्का']
        : ['critical' => 'Critical', 'high' => 'High', 'moderate' => 'Moderate', 'low' => 'Low'];
    $urg_label = $is_hindi
        ? ['today' => 'आज ही', 'this_week' => 'इस हफ़्ते', 'this_month' => 'इस महीने', 'can_wait' => 'अभी रुक सकता है']
        : ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'can_wait' => 'Can wait'];

    $table_html = '<h2 class="section-title">📋 ' . ($is_hindi ? '9 क्षेत्रों का listing' : '9-Area Listing') . '</h2>';
    $table_html .= '<p class="section-note">' . ($is_hindi ? 'समस्या को नाम देना — समाधान की शुरुआत है।' : 'Naming the problem is the start of the solution.') . '</p>';

    $table_html .= '<table class="listing-table">';
    $table_html .= '<tr><th>Area</th><th>Index</th><th>Severity</th><th>Urgency</th><th>Course Day</th></tr>';
    foreach ($areas_def as $key => $meta) {
        $a = $listing['areas'][$key] ?? null;
        $label = $is_hindi ? $meta['label_hi'] : $meta['label_en'];
        if (!$a || empty($a['covered'])) {
            $table_html .= '<tr style="opacity:0.5"><td>' . $meta['emoji'] . ' ' . htmlspecialchars($label) . '</td><td colspan="4" style="font-style:italic">'
                        . ($is_hindi ? 'आज नहीं पूछा गया' : 'Not explored today') . '</td></tr>';
            continue;
        }
        $idx = (int)($a['index'] ?? 0);
        $sev = $a['severity'] ?? 'moderate';
        $urg = $a['urgency'] ?? 'this_month';
        $color = $sev_color[$sev] ?? '#d97706';
        $cd = $a['course_day'] ?? null;
        $table_html .= '<tr>';
        $table_html .= '<td><strong>' . $meta['emoji'] . ' ' . htmlspecialchars($label) . '</strong></td>';
        $table_html .= '<td style="text-align:center"><span class="idx-bar"><span class="idx-bar-fill" style="background:' . $color . ';width:' . $idx . '%"></span></span><span class="idx-num">' . $idx . '</span></td>';
        $table_html .= '<td><span class="sev-pill" style="background:' . $color . '22;color:' . $color . '">' . htmlspecialchars($sev_label[$sev] ?? $sev) . '</span></td>';
        $table_html .= '<td>' . htmlspecialchars($urg_label[$urg] ?? $urg) . '</td>';
        $table_html .= '<td>' . ($cd ? 'Day ' . (int)$cd : '—') . '</td>';
        $table_html .= '</tr>';
    }
    $table_html .= '</table>';

    // Overall + top 3 urgent
    $overall = (int)($listing['overall_index'] ?? 0);
    $top3 = $listing['top_3_urgent_areas'] ?? [];
    $top3_names = [];
    foreach ($top3 as $k) {
        if (isset($areas_def[$k])) {
            $top3_names[] = $is_hindi ? $areas_def[$k]['label_hi'] : $areas_def[$k]['label_en'];
        }
    }
    $overall_color = $overall >= 60 ? '#dc2626' : ($overall >= 40 ? '#d97706' : '#059669');
    $overall_html = '<div class="overall-box">';
    $overall_html .= '<div class="overall-left"><div class="overall-label">' . ($is_hindi ? 'कुल burden index' : 'Overall burden index') . '</div>';
    $overall_html .= '<div class="overall-num" style="color:' . $overall_color . '">' . $overall . '<span style="font-size:14px">/100</span></div></div>';
    if ($top3_names) {
        $overall_html .= '<div class="overall-right"><div class="overall-label">' . ($is_hindi ? 'Top 3 urgent areas' : 'Top 3 urgent areas') . '</div>';
        $overall_html .= '<div class="overall-top3">';
        foreach ($top3_names as $i => $n) {
            $overall_html .= '<div>' . ($i + 1) . '. ' . htmlspecialchars($n) . '</div>';
        }
        $overall_html .= '</div></div>';
    }
    $overall_html .= '</div>';

    // ─── Per-area expansion ───
    $expansion_html = '<h2 class="section-title">🔍 ' . ($is_hindi ? 'क्षेत्र-दर-क्षेत्र विस्तार' : 'Area-by-area expansion') . '</h2>';
    foreach ($areas_def as $key => $meta) {
        $a = $listing['areas'][$key] ?? null;
        if (!$a || empty($a['covered'])) continue;
        $label = $is_hindi ? $meta['label_hi'] : $meta['label_en'];
        $idx = (int)($a['index'] ?? 0);
        $sev = $a['severity'] ?? 'moderate';
        $color = $sev_color[$sev] ?? '#d97706';
        $finding = (string)($a['finding'] ?? '');
        $sev_note = (string)($a['severity_note'] ?? '');
        $cd = $a['course_day'] ?? null;

        $expansion_html .= '<div class="axis-card">';
        $expansion_html .= '<div class="axis-head" style="border-left-color:' . $color . '">';
        $expansion_html .= '<div class="axis-title">' . $meta['emoji'] . ' ' . htmlspecialchars($label) . '</div>';
        $expansion_html .= '<div class="axis-meta">';
        $expansion_html .= '<span class="axis-pill" style="background:' . $color . '22;color:' . $color . '">' . htmlspecialchars($sev_label[$sev] ?? $sev) . '</span>';
        $expansion_html .= '<span class="axis-idx">' . $idx . '/100</span>';
        $expansion_html .= '</div></div>';
        if ($finding) $expansion_html .= '<p class="axis-finding">' . nl2br(htmlspecialchars($finding)) . '</p>';
        if ($sev_note) $expansion_html .= '<p class="axis-sev-note"><em>' . htmlspecialchars($sev_note) . '</em></p>';
        if ($cd) {
            $expansion_html .= '<div class="axis-course-tip">📅 ' . ($is_hindi ? 'Course Day ' : 'Course Day ') . (int)$cd . ' '
                            . ($is_hindi ? 'इस क्षेत्र पर काम करता है।' : 'addresses this area.') . '</div>';
        }
        $expansion_html .= '</div>';
    }

    // ─── Course plan ───
    $course_html = '<h2 class="section-title page-break-before">📅 ' . ($is_hindi ? '7-दिन का course plan — आपके लिए' : '7-Day Course Plan — tailored to you') . '</h2>';
    $course_html .= '<p class="section-note">' . ($is_hindi ? 'हर दिन एक specific area को address करता है, आपकी interview के आधार पर।' : 'Each day addresses a specific area, calibrated to your interview.') . '</p>';
    $course_html .= '<div class="course-grid">';
    foreach ($listing['course_plan'] ?? [] as $d) {
        $day = (int)($d['day'] ?? 0);
        $theme = htmlspecialchars($d['theme'] ?? '');
        $addresses = $d['addresses_areas'] ?? [];
        $why = htmlspecialchars($d['why'] ?? '');
        $addr_labels = [];
        foreach ($addresses as $k) {
            if (isset($areas_def[$k])) $addr_labels[] = $is_hindi ? $areas_def[$k]['label_hi'] : $areas_def[$k]['label_en'];
        }
        $course_html .= '<div class="course-card">';
        $course_html .= '<div class="course-day-num">Day ' . $day . '</div>';
        $course_html .= '<div class="course-theme">' . $theme . '</div>';
        if ($addr_labels) {
            $course_html .= '<div class="course-addresses">→ ' . htmlspecialchars(implode(', ', $addr_labels)) . '</div>';
        }
        if ($why) $course_html .= '<div class="course-why">' . $why . '</div>';
        $course_html .= '</div>';
    }
    $course_html .= '</div>';

    // ─── Narrative — "your story" ───
    $narrative_md = (string)($session['parent_summary_md'] ?? '');
    $narrative_html = '';
    if ($narrative_md !== '') {
        $narrative_html = '<h2 class="section-title page-break-before">📖 ' . ($is_hindi ? 'आपकी story — आपकी ज़ुबानी' : 'Your story — in your words') . '</h2>';
        $narrative_html .= '<div class="narrative-box">' . _comprehensive_v3_md_to_html($narrative_md) . '</div>';
    }

    // ─── Next steps ───
    $safety = (int)($listing['safety_flag'] ?? 0);
    $next_html = '<div class="next-box page-break-before">';
    $next_html .= '<h2 class="section-title" style="margin-top:0">🌱 ' . ($is_hindi ? 'अगले क़दम' : 'Next steps') . '</h2>';
    if ($safety) {
        $next_html .= '<div class="safety-box">';
        $next_html .= '<strong>🤲 ' . ($is_hindi ? 'आप अकेले नहीं हैं' : "You're not alone") . '</strong><br>';
        $next_html .= ($is_hindi ? 'हमारी psychologist 24 घंटे में call करेंगी। तब तक — ' : 'Our psychologist will call within 24 hours. Meanwhile — ') . '<br>';
        $next_html .= '<strong>iCall</strong> 9152987821 · <strong>Vandrevala</strong> 1860-2662-345 · <strong>KIRAN</strong> 1800-599-0019';
        $next_html .= '</div>';
    } else {
        $next_html .= '<p>📞 ' . ($is_hindi
            ? "हमारी psychologist 48 घंटे में आपको <strong>$whatsapp</strong> पर call करेंगी।"
            : "Our psychologist will call you within 48 hours at <strong>$whatsapp</strong>.") . '</p>';
    }
    $next_html .= '<div class="course-cta">';
    $next_html .= '<div class="cta-title">🌿 ' . ($is_hindi ? '7-Day Home Course — ₹4,000' : '7-Day Home Course — ₹4,000') . '</div>';
    $next_html .= '<p>' . ($is_hindi
        ? "ऊपर के top-3 urgent areas पर 7 दिन का structured pathway — रोज़ AI voice interview + practice + meditation, affirmation, motivation, सब Leda की आवाज़ में।"
        : "A 7-day structured pathway addressing the top-3 urgent areas above — daily AI voice interview + practice + meditation, affirmation, motivation, all in Leda voice.") . '</p>';
    $next_html .= '</div>';
    $next_html .= '</div>';

    // ─── Footer ───
    $footer = '<div class="footer-block">';
    $footer .= '<p><em>' . ($is_hindi
        ? 'यह एक wellness assessment है, चिकित्सकीय निदान नहीं। हमारी clinical psychologist की समीक्षा के बाद आपको call की जाएगी।'
        : 'This is a wellness assessment, not a medical diagnosis. A call follows our clinical psychologist\'s review.') . '</em></p>';
    $footer .= '<p>EmpowerStudents.in · care@empowerstudents.in · +91-9311696923 · WhatsApp +91-9311883132</p>';
    $footer .= '<p>Report ID EMP-' . str_pad((string)$session_id, 6, '0', STR_PAD_LEFT) . ' · Issued ' . htmlspecialchars(date('d M Y H:i')) . ' IST</p>';
    $footer .= '</div>';

    $css = '<style>
        body { font-family: "Helvetica Neue", "Noto Sans Devanagari", Arial, sans-serif; color:#1e293b; line-height:1.5; max-width:760px; margin:0 auto; padding:24px; font-size:13px; }
        h1, h2 { color:#0f172a; }
        .cover { background:linear-gradient(135deg, #059669, #0d9488); color:white; padding:28px 24px; border-radius:10px; margin-bottom:18px; }
        .cover-title { font-size:22px; font-weight:800; letter-spacing:1px; }
        .cover-subtitle { font-size:13px; opacity:0.92; margin-bottom:14px; }
        .partner-strip { background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.25); border-radius:8px; padding:12px; margin:14px 0; }
        .partner-row { display:flex; align-items:center; gap:14px; }
        .clinic-img, .clinic-img-ph { width:90px; height:70px; border-radius:6px; object-fit:cover; background:white; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:9px; }
        .doc-img, .doc-img-ph { width:60px; height:60px; border-radius:50%; object-fit:cover; background:white; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:9px; border:2px solid white; }
        .partner-text { flex:1; }
        .partner-label { font-size:9px; opacity:0.85; letter-spacing:1.2px; }
        .partner-clinic { font-size:14px; font-weight:700; margin-top:2px; }
        .partner-doc { font-size:12px; opacity:0.92; margin-top:2px; }
        .partner-city { font-size:10px; opacity:0.85; margin-top:1px; }
        .parent-block { background:rgba(255,255,255,0.12); border-radius:6px; padding:10px 14px; font-size:12px; margin-top:8px; }
        .parent-block div { margin-bottom:2px; }
        .summary-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:18px; margin-bottom:20px; }
        .summary-title { font-size:15px; font-weight:700; color:#065f46; margin-bottom:10px; }
        .lang-label { font-size:10px; color:#6b7280; font-weight:700; letter-spacing:1px; text-transform:uppercase; margin-top:12px; margin-bottom:4px; }
        .summary-hi { font-family:"Noto Sans Devanagari", Arial, sans-serif; line-height:1.7; }
        .summary-en { line-height:1.65; }
        .summary-note { background:white; border-left:3px solid #10b981; padding:8px 12px; font-size:11px; color:#475569; margin-top:14px; border-radius:0 4px 4px 0; }
        .section-title { font-size:17px; margin-top:24px; margin-bottom:4px; padding-bottom:6px; border-bottom:2px solid #059669; }
        .section-note { font-size:11px; color:#64748b; margin-bottom:14px; font-style:italic; }
        .listing-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
        .listing-table th, .listing-table td { padding:8px 10px; border-bottom:1px solid #e5e7eb; text-align:left; }
        .listing-table th { background:#f9fafb; color:#475569; font-size:10px; text-transform:uppercase; letter-spacing:0.6px; }
        .listing-table td { font-size:12px; }
        .idx-bar { display:inline-block; width:60px; height:5px; background:#e5e7eb; border-radius:3px; vertical-align:middle; margin-right:6px; overflow:hidden; }
        .idx-bar-fill { display:inline-block; height:100%; vertical-align:top; border-radius:3px; }
        .idx-num { font-weight:700; font-size:12px; }
        .sev-pill { padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; }
        .overall-box { display:flex; gap:14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px; margin-bottom:18px; }
        .overall-left { flex:1; border-right:1px solid #e5e7eb; padding-right:14px; }
        .overall-right { flex:1.4; padding-left:6px; }
        .overall-label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:0.7px; }
        .overall-num { font-size:32px; font-weight:800; margin-top:4px; }
        .overall-top3 div { font-size:12px; color:#1e293b; margin-top:3px; }
        .axis-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:0; margin:10px 0; page-break-inside:avoid; overflow:hidden; }
        .axis-head { background:#f9fafb; padding:9px 12px; border-left:3px solid #d97706; display:flex; justify-content:space-between; align-items:center; }
        .axis-title { font-weight:700; font-size:13px; color:#0f172a; }
        .axis-meta { display:flex; gap:8px; align-items:center; font-size:11px; }
        .axis-pill { padding:2px 8px; border-radius:10px; font-weight:700; font-size:10px; }
        .axis-idx { font-weight:700; color:#334155; }
        .axis-finding { padding:10px 12px; font-size:12px; color:#334155; line-height:1.6; margin:0; font-family:"Noto Sans Devanagari", Arial, sans-serif; }
        .axis-sev-note { padding:0 12px 8px; font-size:11px; color:#64748b; margin:0; }
        .axis-course-tip { background:#eef2ff; border-top:1px solid #e0e7ff; padding:7px 12px; font-size:11px; color:#4338ca; }
        .course-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .course-card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px; page-break-inside:avoid; }
        .course-day-num { font-size:9px; font-weight:800; color:#6366f1; letter-spacing:1px; }
        .course-theme { font-weight:700; font-size:13px; color:#1e293b; margin:2px 0; }
        .course-addresses { font-size:10px; color:#64748b; margin:4px 0; }
        .course-why { font-size:11px; color:#334155; margin-top:5px; line-height:1.5; font-family:"Noto Sans Devanagari", Arial, sans-serif; }
        .narrative-box { background:#fafafa; border-left:3px solid #6366f1; padding:14px 18px; border-radius:0 4px 4px 0; font-family:"Noto Sans Devanagari", Arial, sans-serif; line-height:1.7; font-size:12px; }
        .narrative-box h2, .narrative-box h3 { font-size:13px; margin-top:12px; border:none; padding:0; }
        .next-box { background:#fef3c7; border:2px solid #fcd34d; border-radius:10px; padding:16px; margin-top:18px; }
        .next-box p { font-size:12px; color:#78350f; line-height:1.6; }
        .safety-box { background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:10px; font-size:12px; color:#991b1b; margin-bottom:14px; }
        .course-cta { background:white; border:1px dashed #fcd34d; border-radius:6px; padding:12px; margin-top:10px; }
        .cta-title { font-size:14px; font-weight:800; color:#78350f; margin-bottom:6px; }
        .footer-block { margin-top:24px; padding-top:14px; border-top:2px solid #e5e7eb; color:#64748b; font-size:10px; text-align:center; }
        .footer-block p { margin:3px 0; }
        .page-break-before { page-break-before:always; }
        @media print { body { padding:0; } .axis-card, .course-card { box-shadow:none; } }
    </style>';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Parent Evaluation Report — ' . $parent_name . '</title>' . $css . '</head><body>'
         . $cover_html
         . $summary_block
         . $table_html
         . $overall_html
         . $expansion_html
         . $course_html
         . $narrative_html
         . $next_html
         . $footer
         . '</body></html>';
}


function _comprehensive_v3_md_to_html(string $md): string {
    if (function_exists('prose_render_md')) return prose_render_md($md);
    $html = htmlspecialchars($md);
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace("/\n\n+/", '</p><p>', $html);
    return '<p>' . $html . '</p>';
}
