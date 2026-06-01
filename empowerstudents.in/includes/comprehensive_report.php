<?php
/**
 * includes/comprehensive_report.php
 *
 * Builds the ₹1000 Parent Evaluation comprehensive report:
 *   • HTML report (always)
 *   • PDF if mPDF is available; otherwise the HTML file (parent can print)
 *   • Hindi + English summary paragraph (one Claude call)
 *   • Leda voice MP3 for each paragraph
 *
 * All artefacts saved to parent_reflect_sessions row:
 *   report_pdf_path, summary_text_hi, summary_text_en,
 *   summary_audio_hi, summary_audio_en, pdf_generated_at
 *
 * Designed to be called from the report queue worker (~1 hour after eval).
 *
 * Public API:
 *   comprehensive_report_generate(int $session_id, bool $force = false): array
 *
 * Returns ['ok' => bool, 'pdf_path' => ?, 'error' => ?, ...]
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/leda_tts.php';
require_once __DIR__ . '/parent_reflect_home_climate.php';


// ───────────────────────────────────────────────────────────
// Hindi + English summary paragraph generation
// ───────────────────────────────────────────────────────────
function _comprehensive_generate_summary(array $session, array $cards): array {
    $convo_text = '';
    if (!empty($session['id'])) {
        $st = db()->prepare("SELECT question, transcript FROM parent_reflect_turns
                              WHERE session_id = ? AND transcript IS NOT NULL AND transcript != ''
                              ORDER BY turn_no ASC");
        $st->execute([(int)$session['id']]);
        $rows = $st->fetchAll();
        foreach ($rows as $r) {
            $q = trim((string)$r['question']);
            $a = trim((string)$r['transcript']);
            if ($q !== '') $convo_text .= "AI: " . mb_substr($q, 0, 200) . "\n";
            if ($a !== '') $convo_text .= "Parent: " . mb_substr($a, 0, 300) . "\n";
        }
    }
    if (mb_strlen($convo_text) > 5000) {
        $convo_text = mb_substr($convo_text, 0, 5000) . "\n[...truncated]";
    }

    // Format scores for the prompt
    $score_lines = [];
    foreach ($cards['cards'] ?? [] as $k => $c) {
        $score_lines[] = sprintf("- %s: %d/100 (%s)", $c['label'] ?? $k, (int)($c['score'] ?? 0), $c['band_label'] ?? '');
    }
    $score_block = implode("\n", $score_lines);

    $sys = <<<SYS
You are a clinical psychologist writing the headline summary paragraph for a
parent's home-climate evaluation report. The summary will be:
  1. Printed at the top of the PDF report
  2. Read out loud in Hindi voice (Leda — calm, female) to the parent
  3. Read out loud in English voice as well

OUTPUT REQUIREMENT: JSON only, in this exact shape:
{
  "hindi": "एक paragraph in conversational Hindi, 3-5 sentences, addressing the parent as 'आप'. Use 1-2 specific phrases from their actual answers in quotes. Acknowledge a genuine strength + an area that needs care. End with hope and an invitation to the 7-day course.",
  "english": "Same content as hindi but in warm English, 3-5 sentences. Same structure: name a real phrase they used, acknowledge a strength + an area, end with hope + invitation to the 7-day course."
}

CRITICAL:
- The paragraph is THE PERSON'S OWN STORY in 4 sentences. Specific, never generic.
- It must be readable in under 60 seconds when spoken aloud (Hindi ~150 words, English ~120 words).
- Never use clinical jargon or labels.
- Honest, never falsely cheerful.
SYS;

    $usr = "=== Scores ===\n$score_block\n\n=== Transcript (parent's actual words) ===\n$convo_text\n\nGenerate the JSON now. JSON only, no preamble.";

    $resp = function_exists('claude_chat')
        ? claude_chat($sys, [['role' => 'user', 'content' => $usr]], 1500, 0.5)
        : '';
    $clean = trim((string)$resp);
    if ($clean === '') {
        return [
            'hi' => 'आपकी आज की reflection में कई important बातें सामने आईं। हम जल्द ही आपके सामने एक detailed report रखेंगे।',
            'en' => 'Your reflection today surfaced many important things. A detailed report follows below.',
            'used_fallback' => true,
        ];
    }
    if (strpos($clean, '```') !== false) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
    }
    $j = json_decode($clean, true);
    return [
        'hi' => trim((string)($j['hindi']  ?? '')),
        'en' => trim((string)($j['english'] ?? '')),
        'used_fallback' => false,
    ];
}


// ───────────────────────────────────────────────────────────
// Build the HTML body of the report (used by both PDF and web view)
// ───────────────────────────────────────────────────────────
function comprehensive_report_html(array $session, array $cards, array $summary, ?array $parent = null): string {
    $parent_name = htmlspecialchars($parent['name'] ?? 'Parent');
    $whatsapp    = htmlspecialchars($parent['whatsapp'] ?? '');
    $done_date   = htmlspecialchars(date('F j, Y', strtotime((string)$session['completed_at'] . ' UTC')));
    $session_id  = (int)$session['id'];

    $summary_hi = htmlspecialchars($summary['hi'] ?? '');
    $summary_en = htmlspecialchars($summary['en'] ?? '');

    // Markdown report body (already exists in parent_summary_md)
    $report_md = (string)($session['parent_summary_md'] ?? '');
    $report_html = _comprehensive_md_to_html($report_md);

    // Cards rendered as table rows
    $rows = '';
    $axis_labels_en = [
        'couple_harmony'   => 'Couple Harmony',
        'joint_family'     => 'Joint Family Balance',
        'parent_wellbeing' => 'Your Wellbeing',
        'child_climate'    => "Child's Emotional Climate",
        'support_network'  => 'Support Network',
    ];
    foreach ($cards['cards'] ?? [] as $key => $c) {
        $score = (int)($c['score'] ?? 0);
        $label = $axis_labels_en[$key] ?? ($c['label'] ?? $key);
        $band  = htmlspecialchars($c['band_label'] ?? '');
        $color = $score >= 70 ? '#059669' : ($score >= 45 ? '#d97706' : '#dc2626');
        $rows .= "<tr>
            <td><strong>$label</strong></td>
            <td style=\"color: $color; font-weight: bold; text-align: right\">$score/100</td>
            <td style=\"color: $color\">$band</td>
        </tr>";
    }

    // Per-axis findings + exercises
    $details = '';
    foreach ($cards['cards'] ?? [] as $key => $c) {
        $label = $axis_labels_en[$key] ?? ($c['label'] ?? $key);
        $score = (int)($c['score'] ?? 0);
        $finding = htmlspecialchars((string)($c['finding'] ?? ''));
        $exercise = htmlspecialchars((string)($c['exercise'] ?? ''));
        $emoji = $c['emoji'] ?? '·';
        $details .= '<div class="axis-card">
            <h3>' . $emoji . ' ' . $label . ' — ' . $score . '/100</h3>
            <p class="finding">' . $finding . '</p>
            <div class="exercise"><strong>This week:</strong> ' . $exercise . '</div>
        </div>';
    }

    // Build axis score chart as inline SVG (works in PDF and HTML)
    $svg = _comprehensive_render_score_svg($cards['cards'] ?? []);

    $css = '<style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; color: #1e293b; line-height: 1.5; max-width: 760px; margin: 0 auto; padding: 24px; }
        h1, h2, h3 { color: #0f172a; margin-top: 1.5em; }
        h1 { font-size: 24px; border-bottom: 3px solid #059669; padding-bottom: 8px; }
        h2 { font-size: 18px; }
        h3 { font-size: 15px; }
        .header { background: linear-gradient(135deg, #059669, #0d9488); color: white; padding: 22px; border-radius: 8px; margin-bottom: 22px; }
        .header h1 { color: white; border: none; margin: 0; }
        .header p { color: rgba(255,255,255,0.92); margin: 4px 0 0 0; font-size: 13px; }
        .summary-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; margin: 18px 0; }
        .summary-box h2 { color: #065f46; margin: 0 0 10px 0; }
        .summary-box .lang-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 12px; font-weight: 700; }
        .summary-box p { margin: 4px 0; font-size: 14px; line-height: 1.7; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 10px 8px; text-align: left; font-size: 14px; }
        th { background: #f9fafb; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .axis-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px; margin: 10px 0; page-break-inside: avoid; }
        .axis-card h3 { margin: 0 0 6px 0; color: #0f172a; }
        .axis-card .finding { color: #334155; font-size: 13px; margin: 0 0 8px 0; }
        .axis-card .exercise { background: #fef3c7; border-left: 3px solid #d97706; padding: 8px 10px; font-size: 13px; color: #92400e; border-radius: 0 4px 4px 0; }
        .narrative { background: #fafafa; padding: 14px 18px; border-left: 3px solid #6366f1; border-radius: 4px; margin: 14px 0; }
        .narrative p { font-size: 13px; line-height: 1.65; }
        .footer { margin-top: 30px; padding-top: 16px; border-top: 2px solid #e5e7eb; color: #64748b; font-size: 11px; text-align: center; }
        .footer a { color: #059669; text-decoration: none; }
        .next-cta { background: #fef3c7; border: 2px solid #fcd34d; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .next-cta h3 { color: #78350f; margin: 0 0 6px 0; }
        .next-cta p { color: #78350f; font-size: 13px; margin: 4px 0; }
        .chart-wrap { text-align: center; margin: 14px 0; }
        @media print {
            body { padding: 0; }
            .axis-card { box-shadow: none; }
        }
    </style>';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Parent Evaluation Report — ' . $parent_name . '</title>' . $css . '</head><body>

    <div class="header">
        <h1>Parent Evaluation Report</h1>
        <p>For: ' . $parent_name . ' (' . $whatsapp . ')</p>
        <p>Completed: ' . $done_date . '  ·  Session ID: ' . $session_id . '</p>
        <p>Issued by: EmpowerStudents · Clinical Psychology Panel</p>
    </div>

    <div class="summary-box">
        <h2>🌿 Headline Summary</h2>
        <div class="lang-label">हिंदी</div>
        <p style="font-family: \'Noto Sans Devanagari\', Arial, sans-serif;">' . $summary_hi . '</p>
        <div class="lang-label">English</div>
        <p>' . $summary_en . '</p>
    </div>

    <h2>📊 5-Axis Home Climate</h2>
    <div class="chart-wrap">' . $svg . '</div>
    <table>
        <tr><th>Dimension</th><th style="text-align:right">Score</th><th>Band</th></tr>
        ' . $rows . '
    </table>

    <h2>🔍 What we found, axis by axis</h2>
    ' . $details . '

    <h2>📖 Narrative — your story, in your words</h2>
    <div class="narrative">' . $report_html . '</div>

    <div class="next-cta">
        <h3>🌱 What\'s next: 7-Day Home Environment Course (₹4000)</h3>
        <p>Daily voice interview + practice + meditation, affirmation and motivation — all tailored to YOUR home, in Leda voice.</p>
        <p>Track real change over 7 days. Available now through your evaluation page.</p>
    </div>

    <div class="footer">
        <p>This is a wellness assessment, not a medical diagnosis. Our clinical psychologist will call within 48 hours.</p>
        <p>EmpowerStudents · care@empowerstudents.in · +91-9311696923 · WhatsApp +91-9311883132</p>
        <p>Issued: ' . htmlspecialchars(date('d M Y H:i')) . ' IST  ·  Session ' . $session_id . '</p>
    </div>

    </body></html>';
}


function _comprehensive_md_to_html(string $md): string {
    if (function_exists('prose_render_md')) return prose_render_md($md);
    // Lightweight fallback
    $html = htmlspecialchars($md);
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace("/\n\n+/", '</p><p>', $html);
    return '<p>' . $html . '</p>';
}


function _comprehensive_render_score_svg(array $cards): string {
    $axes = [
        'couple_harmony', 'joint_family', 'parent_wellbeing', 'child_climate', 'support_network',
    ];
    $labels = ['Couple','Family','Self','Child','Support'];
    $w = 600; $h = 200;
    $bar_h = 18; $gap = 8; $left = 80; $right = 40;
    $bar_max = $w - $left - $right;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" style="background:#fff">';
    foreach ($axes as $i => $k) {
        $score = (int)($cards[$k]['score'] ?? 0);
        $y = 16 + $i * ($bar_h + $gap);
        $color = $score >= 70 ? '#059669' : ($score >= 45 ? '#d97706' : '#dc2626');
        $bar_w = $bar_max * ($score / 100);
        $svg .= '<text x="6" y="' . ($y + $bar_h - 4) . '" font-family="Arial,sans-serif" font-size="12" fill="#334155">' . $labels[$i] . '</text>';
        $svg .= '<rect x="' . $left . '" y="' . $y . '" width="' . $bar_max . '" height="' . $bar_h . '" fill="#f1f5f9" rx="3" />';
        $svg .= '<rect x="' . $left . '" y="' . $y . '" width="' . $bar_w . '" height="' . $bar_h . '" fill="' . $color . '" rx="3" />';
        $svg .= '<text x="' . ($left + $bar_max + 6) . '" y="' . ($y + $bar_h - 4) . '" font-family="Arial,sans-serif" font-size="12" fill="' . $color . '" font-weight="bold">' . $score . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}


// ───────────────────────────────────────────────────────────
// Main: generate everything (idempotent unless force=true)
// ───────────────────────────────────────────────────────────
function comprehensive_report_generate(int $session_id, bool $force = false): array {
    $st = db()->prepare("SELECT * FROM parent_reflect_sessions WHERE id = ?");
    $st->execute([$session_id]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'error' => 'Session not found'];
    if ($session['status'] !== 'completed') return ['ok' => false, 'error' => 'Session not completed'];

    if (!$force && !empty($session['report_pdf_path']) && !empty($session['summary_audio_hi'])) {
        return ['ok' => true, 'pdf_path' => $session['report_pdf_path'], 'cached' => true];
    }

    // Ensure home climate cards are populated
    if (empty($session['home_climate_cards_json']) && function_exists('home_climate_analyse')) {
        home_climate_analyse($session_id);
        $st->execute([$session_id]);
        $session = $st->fetch();
    }
    $cards = json_decode((string)($session['home_climate_cards_json'] ?? ''), true) ?: ['cards' => []];

    // Parent row
    $pst = db()->prepare("SELECT id, name, whatsapp FROM parents WHERE id = ?");
    $pst->execute([(int)$session['parent_id']]);
    $parent = $pst->fetch() ?: [];

    // 1. Generate Hindi + English summary paragraphs
    $summary = _comprehensive_generate_summary($session, $cards);
    if (empty($summary['hi']) || empty($summary['en'])) {
        return ['ok' => false, 'error' => 'Summary generation failed'];
    }

    // 2. Synthesize Leda voice for both
    $audio_hi = leda_tts_synthesize($summary['hi'], 'hi');
    $audio_en = leda_tts_synthesize($summary['en'], 'en');

    // 3. Build HTML + write to disk
    $html = comprehensive_report_html($session, $cards, $summary, $parent);
    $base_dir_fs  = __DIR__ . '/../reports';
    $base_dir_web = '/reports';
    @mkdir($base_dir_fs, 0775, true);

    $fname_html = 'eval-' . $session_id . '-' . hash('sha256', $session_id . '|' . ($session['parent_id'] ?? '')) . '.html';
    $fname_html = substr($fname_html, 0, 60) . '.html';
    $path_html_fs  = $base_dir_fs . '/' . $fname_html;
    $path_html_web = $base_dir_web . '/' . $fname_html;
    file_put_contents($path_html_fs, $html);

    // 4. Try real PDF via mPDF
    $pdf_web = null;
    $mpdf_autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($mpdf_autoload)) {
        try {
            require_once $mpdf_autoload;
            if (class_exists('\\Mpdf\\Mpdf')) {
                $pdf_fname = str_replace('.html', '.pdf', $fname_html);
                $pdf_path_fs = $base_dir_fs . '/' . $pdf_fname;
                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'margin_left' => 15, 'margin_right' => 15,
                    'margin_top' => 15, 'margin_bottom' => 15,
                    'tempDir' => sys_get_temp_dir(),
                    'default_font' => 'dejavusans',
                ]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($pdf_path_fs, 'F');
                $pdf_web = $base_dir_web . '/' . $pdf_fname;
            }
        } catch (Throwable $e) {
            error_log('[comprehensive_report mPDF] ' . $e->getMessage());
        }
    }

    $final_path = $pdf_web ?: $path_html_web;

    // 5. Save back to session
    db()->prepare("UPDATE parent_reflect_sessions
                   SET report_pdf_path = ?,
                       summary_text_hi = ?, summary_text_en = ?,
                       summary_audio_hi = ?, summary_audio_en = ?,
                       pdf_generated_at = CURRENT_TIMESTAMP
                   WHERE id = ?")
       ->execute([$final_path, $summary['hi'], $summary['en'], $audio_hi, $audio_en, $session_id]);

    return [
        'ok'             => true,
        'pdf_path'       => $final_path,
        'html_path'      => $path_html_web,
        'summary_hi'     => $summary['hi'],
        'summary_en'     => $summary['en'],
        'audio_hi'       => $audio_hi,
        'audio_en'       => $audio_en,
        'used_real_pdf'  => $pdf_web !== null,
    ];
}
