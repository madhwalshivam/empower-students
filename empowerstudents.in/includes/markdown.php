<?php
/**
 * includes/markdown.php — minimal markdown -> HTML renderer for AI-generated
 * report and plan content.
 *
 * Goals:
 *   - Pretty render headings (#, ##, ###), bold, italic, links, code.
 *   - Render GitHub-style tables (| col | col | with separator row).
 *   - Render bullet lists and numbered lists.
 *   - HTML-escape every text segment we pass through (no XSS).
 *   - No dependencies. PHP 7.4 compatible.
 *
 * Not goals:
 *   - Block quotes, fenced code blocks with syntax highlighting, footnotes,
 *     reference-style links, etc. AI output rarely uses these.
 *
 * Usage:
 *   echo md_render($a['ai_summary']);  // returns sanitised HTML, ready to print
 */

if (!function_exists('md_render')) {

function md_render(string $text): string {
    if ($text === '') return '';

    // Normalize line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Split into blocks separated by blank lines
    $blocks = preg_split('/\n{2,}/', trim($text));
    $out = [];

    foreach ($blocks as $block) {
        $block = trim($block, "\n");
        if ($block === '') continue;

        // Horizontal rule
        if (preg_match('/^-{3,}$|^\*{3,}$|^_{3,}$/', $block)) {
            $out[] = '<hr class="md-hr">';
            continue;
        }

        // Heading: # ... or ## ... etc.
        if (preg_match('/^(#{1,6})\s+(.+)$/m', $block, $m) && substr_count($block, "\n") === 0) {
            $level = strlen($m[1]);
            $out[] = '<h' . $level . ' class="md-h md-h' . $level . '">' . md_inline($m[2]) . '</h' . $level . '>';
            continue;
        }

        // Table: line with |, then a separator row of |---|---|
        if (md_looks_like_table($block)) {
            $out[] = md_render_table($block);
            continue;
        }

        // Unordered list — every line starts with "- " or "* "
        if (md_all_lines_match($block, '/^\s*[-*]\s+/')) {
            $out[] = md_render_list($block, 'ul');
            continue;
        }

        // Ordered list — every line starts with "1. " etc.
        if (md_all_lines_match($block, '/^\s*\d+\.\s+/')) {
            $out[] = md_render_list($block, 'ol');
            continue;
        }

        // Default: paragraph. Hard-wrap newlines as <br> within the paragraph.
        $lines = explode("\n", $block);
        $rendered = [];
        foreach ($lines as $ln) {
            // Heading inside what would otherwise be a paragraph block
            if (preg_match('/^(#{1,6})\s+(.+)$/', $ln, $m)) {
                if (!empty($rendered)) {
                    $out[] = '<p class="md-p">' . implode('<br>', $rendered) . '</p>';
                    $rendered = [];
                }
                $level = strlen($m[1]);
                $out[] = '<h' . $level . ' class="md-h md-h' . $level . '">' . md_inline($m[2]) . '</h' . $level . '>';
                continue;
            }
            $rendered[] = md_inline($ln);
        }
        if (!empty($rendered)) {
            $out[] = '<p class="md-p">' . implode('<br>', $rendered) . '</p>';
        }
    }

    return implode("\n", $out);
}

/** Inline formatting: bold, italic, code, links. Always escapes text. */
function md_inline(string $s): string {
    // Step 1: HTML-escape everything first.
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // Step 2: pull out and protect inline code segments before we run the other rules
    //         so we don't re-format inside `code`.
    $codes = [];
    $s = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
        $i = count($codes);
        $codes[$i] = '<code class="md-code">' . $m[1] . '</code>';  // already escaped above
        return "\x00CODE{$i}\x00";
    }, $s);

    // Step 3: links [label](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        // Already-escaped text is in $m[1]; URL needs validation
        $url = $m[2];
        // Allow http(s):, mailto:, tel:, /relative
        if (!preg_match('#^(https?:|mailto:|tel:|/)#i', $url)) {
            return $m[0];
        }
        return '<a class="md-a" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);

    // Step 4: bold (**text**) — order matters: do double-star before single-star
    $s = preg_replace('/\*\*([^*\n]+?)\*\*/', '<strong class="md-b">$1</strong>', $s);
    $s = preg_replace('/__([^_\n]+?)__/', '<strong class="md-b">$1</strong>', $s);

    // Step 5: italic (*text* or _text_) — careful not to swallow already-bold markers
    $s = preg_replace('/(^|[^\*])\*([^*\n]+?)\*(?!\*)/', '$1<em class="md-i">$2</em>', $s);
    $s = preg_replace('/(^|[^_])_([^_\n]+?)_(?!_)/', '$1<em class="md-i">$2</em>', $s);

    // Step 6: restore code segments
    $s = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($codes) {
        return $codes[(int)$m[1]] ?? '';
    }, $s);

    return $s;
}

function md_all_lines_match(string $block, string $pattern): bool {
    $lines = explode("\n", $block);
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        if (!preg_match($pattern, $ln)) return false;
    }
    return true;
}

function md_render_list(string $block, string $tag): string {
    $items = [];
    $current = '';
    foreach (explode("\n", $block) as $ln) {
        if (preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/', $ln, $m)) {
            if ($current !== '') $items[] = $current;
            $current = $m[1];
        } else {
            // Continuation of the previous item
            $current .= ' ' . trim($ln);
        }
    }
    if ($current !== '') $items[] = $current;

    $html = '<' . $tag . ' class="md-list md-' . $tag . '">';
    foreach ($items as $it) {
        $html .= '<li class="md-li">' . md_inline($it) . '</li>';
    }
    $html .= '</' . $tag . '>';
    return $html;
}

function md_looks_like_table(string $block): bool {
    $lines = explode("\n", $block);
    if (count($lines) < 2) return false;
    // First line has at least one pipe AND the second line is a separator row
    if (strpos($lines[0], '|') === false) return false;
    return (bool) preg_match('/^\s*\|?[\s:]*-{3,}[\s:|-]*\|?\s*$/', $lines[1]);
}

function md_render_table(string $block): string {
    $lines = array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== '');
    $lines = array_values($lines);
    $header_cells = md_split_table_row($lines[0]);
    // skip separator (lines[1])
    $body = [];
    for ($i = 2; $i < count($lines); $i++) {
        $body[] = md_split_table_row($lines[$i]);
    }

    $html = '<div class="md-table-wrap"><table class="md-table">';
    $html .= '<thead><tr>';
    foreach ($header_cells as $h) {
        $html .= '<th class="md-th">' . md_inline($h) . '</th>';
    }
    $html .= '</tr></thead>';
    $html .= '<tbody>';
    foreach ($body as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td class="md-td">' . md_inline($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function md_split_table_row(string $line): array {
    $line = trim($line);
    // Strip leading/trailing pipe (PHP 7.4 compatible)
    if ($line !== '' && $line[0] === '|') $line = substr($line, 1);
    if ($line !== '' && substr($line, -1) === '|') $line = substr($line, 0, -1);
    // Split by pipe (markdown doesn't support escaped pipes commonly; we ignore that)
    $parts = explode('|', $line);
    return array_map('trim', $parts);
}

/**
 * The CSS for the rendered markdown. Inject this once on any page that
 * uses md_render(). Class names are namespaced (md-*) so they don't
 * collide with anything else.
 */
function md_css(): string {
    return '<style>
      .md-h { font-weight: 700; color: rgb(15,23,42); margin: 1.1em 0 0.45em; line-height: 1.25; }
      .md-h1 { font-size: 1.5rem; }
      .md-h2 { font-size: 1.25rem; }
      .md-h3 { font-size: 1.1rem; color: rgb(67,56,202); }
      .md-h4, .md-h5, .md-h6 { font-size: 1rem; color: rgb(71,85,105); }
      .md-p  { margin: 0 0 0.85em; line-height: 1.65; color: rgb(51,65,85); }
      .md-b  { font-weight: 600; color: rgb(15,23,42); }
      .md-i  { font-style: italic; }
      .md-a  { color: rgb(79,70,229); text-decoration: underline; }
      .md-a:hover { color: rgb(67,56,202); }
      .md-code { background: rgb(241,245,249); padding: 1px 6px; border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9em; color: rgb(190,18,60); }
      .md-list { margin: 0 0 1em 0; padding-left: 1.4em; line-height: 1.6; color: rgb(51,65,85); }
      .md-list .md-li { margin: 0.25em 0; }
      .md-ul { list-style: disc outside; }
      .md-ol { list-style: decimal outside; }
      .md-hr { border: none; border-top: 1px solid rgb(226,232,240); margin: 1.6em 0; }
      .md-table-wrap { overflow-x: auto; margin: 0.75em 0 1.25em; border: 1px solid rgb(226,232,240); border-radius: 10px; background: white; }
      .md-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
      .md-th { background: rgb(248,250,252); text-align: left; padding: 9px 12px; font-weight: 600; color: rgb(30,41,59); border-bottom: 1px solid rgb(226,232,240); }
      .md-td { padding: 9px 12px; border-bottom: 1px solid rgb(241,245,249); color: rgb(51,65,85); vertical-align: top; }
      .md-table tr:last-child .md-td { border-bottom: none; }
      .md-table tr:nth-child(even) .md-td { background: rgba(241,245,249,0.4); }
    </style>';
}

} // end function_exists guard
