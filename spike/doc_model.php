<?php
/**
 * Shared doc-walking logic used by walk_doc.php and roundtrip.php.
 *
 * The Docs API returns a document as body.content: an ordered array of
 * "structural elements" — each is one of paragraph | table | sectionBreak | tableOfContents.
 * A paragraph carries paragraphStyle.namedStyleType (TITLE, HEADING_1..6, NORMAL_TEXT)
 * and an "elements" array of textRun pieces (each with its own text + textStyle).
 *
 * We walk that tree the way you'd walk a DOM and produce two things:
 *   - a flat outline (for eyeballing structure), and
 *   - a "sections" model: content grouped under the most recent heading. This is the
 *     shape the eventual Elementor pipeline consumes (heading => section, body => fill).
 */

declare(strict_types=1);

use Google\Service\Docs\Document;
use Google\Service\Docs\StructuralElement;

/** Extract concatenated plain text from a paragraph's textRun elements. */
function paragraph_text(object $paragraph): string
{
    $text = '';
    foreach (($paragraph->getElements() ?? []) as $el) {
        $run = $el->getTextRun();
        if ($run) {
            $text .= $run->getContent();
        }
    }
    return rtrim($text, "\n");
}

/** Does any textRun in this paragraph have bold/italic set? (paragraph-level fidelity signal) */
function paragraph_formatting(object $paragraph): array
{
    $flags = ['bold' => false, 'italic' => false, 'link' => false];
    foreach (paragraph_runs($paragraph) as $run) {
        if ($run['bold']) $flags['bold'] = true;
        if ($run['italic']) $flags['italic'] = true;
        if ($run['link']) $flags['link'] = true;
    }
    return $flags;
}

/**
 * Break a paragraph into styled runs — the substring-level formatting we need for import.
 * Each run: ['text' => string, 'bold' => bool, 'italic' => bool, 'underline' => bool,
 *            'link' => string|null].  ($link is the URL, or null.)
 *
 * The Docs API already splits a paragraph into textRun pieces at every style boundary,
 * so "the first 3 words are bold, then a linked phrase, then normal" arrives as 3 runs.
 * We just normalize them. Adjacent runs with identical styling are merged so the output
 * is compact.
 */
function paragraph_runs(object $paragraph): array
{
    $runs = [];
    foreach (($paragraph->getElements() ?? []) as $el) {
        $run = $el->getTextRun();
        if (!$run) continue;
        $text = $run->getContent();
        if ($text === '' || $text === null) continue;

        $style = $run->getTextStyle();
        $link = null;
        if ($style && $style->getLink()) {
            // A link can point at a URL, a bookmark, or a heading; we surface the URL.
            $link = $style->getLink()->getUrl();
        }
        $piece = [
            'text' => $text,
            'bold' => (bool) ($style?->getBold()),
            'italic' => (bool) ($style?->getItalic()),
            'underline' => (bool) ($style?->getUnderline()),
            'link' => $link,
        ];

        // Merge into the previous run if styling is identical (keeps output tidy).
        $prev = $runs ? $runs[count($runs) - 1] : null;
        if ($prev
            && $prev['bold'] === $piece['bold']
            && $prev['italic'] === $piece['italic']
            && $prev['underline'] === $piece['underline']
            && $prev['link'] === $piece['link']) {
            $runs[count($runs) - 1]['text'] .= $piece['text'];
        } else {
            $runs[] = $piece;
        }
    }

    // Trim the trailing newline off the paragraph's last run.
    if ($runs) {
        $runs[count($runs) - 1]['text'] = rtrim($runs[count($runs) - 1]['text'], "\n");
    }
    return $runs;
}

/**
 * Render styled runs to inline HTML — this is what you inject into an Elementor
 * text/heading widget, so bold/italic/link substrings format correctly on import.
 * Nesting order (link outermost) is chosen so <a><strong>…</strong></a> renders cleanly.
 */
function runs_to_html(array $runs): string
{
    $html = '';
    foreach ($runs as $run) {
        $t = htmlspecialchars($run['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($t === '') continue;
        if ($run['bold'])      $t = "<strong>{$t}</strong>";
        if ($run['italic'])    $t = "<em>{$t}</em>";
        if ($run['underline']) $t = "<u>{$t}</u>";
        if ($run['link']) {
            $href = htmlspecialchars($run['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = "<a href=\"{$href}\">{$t}</a>";
        }
        $html .= $t;
    }
    return $html;
}

/**
 * Extract a table into a 2D array of cells. Each cell is:
 *   ['text' => plain text, 'html' => import-ready inline HTML preserving bold/italic/links].
 * A cell's content is a list of paragraphs; multi-paragraph cells join text with newlines
 * and wrap each paragraph in <p> in the HTML.
 */
function table_cells(object $table): array
{
    $out = [];
    foreach (($table->getTableRows() ?? []) as $row) {
        $rowCells = [];
        foreach (($row->getTableCells() ?? []) as $cell) {
            $textParts = [];
            $htmlParts = [];
            foreach (($cell->getContent() ?? []) as $se) {
                $p = $se->getParagraph();
                if (!$p) continue;
                $t = paragraph_text($p);
                if ($t === '') continue;
                $textParts[] = $t;
                $htmlParts[] = '<p>' . runs_to_html(paragraph_runs($p)) . '</p>';
            }
            $rowCells[] = [
                'text' => implode("\n", $textParts),
                'html' => count($htmlParts) === 1
                    // single-paragraph cell: unwrap the lone <p> for cleaner injection
                    ? preg_replace('#^<p>(.*)</p>$#s', '$1', $htmlParts[0])
                    : implode('', $htmlParts),
            ];
        }
        $out[] = $rowCells;
    }
    return $out;
}

/** Plain-text of a cell from the structured grid table_cells() returns. */
function cell_text(array $cells, int $r, int $c): string
{
    return $cells[$r][$c]['text'] ?? '';
}

/**
 * Walk a Document into a normalized array of "nodes", each:
 *   ['type' => 'heading'|'paragraph'|'list_item'|'table'|'image'|'section_break',
 *    'level' => int|null, 'text' => string, 'fmt' => array, 'meta' => array]
 */
function walk_document(Document $doc): array
{
    $nodes = [];
    $content = $doc->getBody()->getContent() ?? [];

    /** @var StructuralElement $se */
    foreach ($content as $se) {
        $paragraph = $se->getParagraph();
        $table = $se->getTable();
        $sectionBreak = $se->getSectionBreak();

        if ($sectionBreak) {
            $nodes[] = ['type' => 'section_break', 'level' => null, 'text' => '', 'fmt' => [], 'meta' => []];
            continue;
        }

        if ($table) {
            $rows = $table->getRows();
            $cols = $table->getColumns();
            $cells = table_cells($table);
            // For the common label/value block (2 columns), surface col0=>col1 as a preview.
            $preview = "[table {$rows}x{$cols}]";
            if ($cols === 2 && count($cells) === 1) {
                $preview = trim(cell_text($cells, 0, 0)) . ' => ' . trim(cell_text($cells, 0, 1));
            } elseif (trim(cell_text($cells, 0, 0)) !== '') {
                $preview = "[table {$rows}x{$cols}] " . trim(cell_text($cells, 0, 0));
            }
            $nodes[] = ['type' => 'table', 'level' => null, 'text' => $preview,
                        'fmt' => [], 'meta' => ['rows' => $rows, 'cols' => $cols, 'cells' => $cells]];
            continue;
        }

        if ($paragraph) {
            $style = $paragraph->getParagraphStyle();
            $named = $style ? $style->getNamedStyleType() : 'NORMAL_TEXT';
            $bullet = $paragraph->getBullet(); // present => this paragraph is a list item
            $text = paragraph_text($paragraph);
            $runs = paragraph_runs($paragraph);   // substring-level bold/italic/link
            $html = runs_to_html($runs);          // import-ready inline HTML
            $fmt = paragraph_formatting($paragraph);

            // Detect inline images: an element with an inlineObjectElement.
            $hasImage = false;
            foreach (($paragraph->getElements() ?? []) as $el) {
                if ($el->getInlineObjectElement()) { $hasImage = true; break; }
            }
            if ($hasImage) {
                $nodes[] = ['type' => 'image', 'level' => null, 'text' => $text,
                            'fmt' => [], 'runs' => $runs, 'html' => $html,
                            'meta' => ['namedStyle' => $named]];
                // an image paragraph can also carry text; fall through only if text remains
                if (trim($text) === '') continue;
            }

            if (str_starts_with((string)$named, 'HEADING_')) {
                $level = (int) substr($named, strlen('HEADING_'));
                $nodes[] = ['type' => 'heading', 'level' => $level, 'text' => $text,
                            'fmt' => $fmt, 'runs' => $runs, 'html' => $html,
                            'meta' => ['namedStyle' => $named]];
            } elseif ($named === 'TITLE') {
                $nodes[] = ['type' => 'heading', 'level' => 0, 'text' => $text,
                            'fmt' => $fmt, 'runs' => $runs, 'html' => $html,
                            'meta' => ['namedStyle' => $named]];
            } elseif ($bullet) {
                $nodes[] = ['type' => 'list_item', 'level' => null, 'text' => $text,
                            'fmt' => $fmt, 'runs' => $runs, 'html' => $html,
                            'meta' => ['listId' => $bullet->getListId()]];
            } elseif (trim($text) !== '') {
                $nodes[] = ['type' => 'paragraph', 'level' => null, 'text' => $text,
                            'fmt' => $fmt, 'runs' => $runs, 'html' => $html,
                            'meta' => ['namedStyle' => $named]];
            }
        }
    }

    return $nodes;
}

/**
 * Group nodes into sections keyed by the preceding heading. This is the
 * "semi-structured section" model the Elementor pipeline will map from.
 * Returns: [ ['heading' => string, 'level' => int, 'body' => array<node>], ... ]
 */
function nodes_to_sections(array $nodes): array
{
    $sections = [];
    $current = ['heading' => '(document start)', 'level' => 0, 'body' => []];
    foreach ($nodes as $node) {
        if ($node['type'] === 'heading') {
            if ($current['body'] || $current['heading'] !== '(document start)') {
                $sections[] = $current;
            }
            $current = ['heading' => $node['text'], 'level' => $node['level'], 'body' => []];
        } else {
            $current['body'][] = $node;
        }
    }
    $sections[] = $current;
    return $sections;
}

/**
 * Split a column-0 label cell into its field name and its guidance.
 *
 * In this template the label and its guidance are SEPARATE PARAGRAPHS in the cell,
 * so table_cells() joins them with a newline. (The " / " you see in the debug outline
 * is a display substitution for that newline, not a real slash in the document.)
 *
 * Rule, in order:
 *   1. If the cell has a newline: label = first line, guidance = the remaining lines.
 *   2. Else if it has a "/": split on the first "/" (handles genuinely inline guidance).
 *   3. Else: the whole thing is the label, no guidance.
 *
 *   "Page Title (H1)\n1-48 characters..." -> label "Page Title (H1)", guidance "1-48 characters..."
 */
function split_label_cell(string $text): array
{
    $text = trim($text);

    $nl = mb_strpos($text, "\n");
    if ($nl !== false) {
        return [
            'label' => trim(mb_substr($text, 0, $nl)),
            'guidance' => trim(mb_substr($text, $nl + 1)),
        ];
    }

    $slash = mb_strpos($text, '/');
    if ($slash !== false) {
        return [
            'label' => trim(mb_substr($text, 0, $slash)),
            'guidance' => trim(mb_substr($text, $slash + 1)),
        ];
    }

    return ['label' => $text, 'guidance' => ''];
}

/**
 * Turn a human label into a machine key: lowercase, alnum-only, underscore-separated.
 *   "Hero - Title & Buttons" -> "hero_title_buttons"
 *   "Question or Label"      -> "question_or_label"
 */
function slugify(string $label): string
{
    $s = mb_strtolower(trim($label));
    $s = preg_replace('/&/', ' and ', $s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

/**
 * Convert the walked nodes into the block model — the real shape the Elementor
 * pipeline consumes. Each content TABLE becomes one block:
 *
 *   [
 *     'index'     => int,             // order on the page
 *     'type'      => 'Hero - Title & Buttons',   // raw block-type label (row 0, col 0)
 *     'type_slug' => 'hero_title_buttons',
 *     'guidance'  => 'Required. Saltwater will add...',   // italic note on the type row
 *     'fields'    => [
 *        ['label'=>'Page Title (H1)', 'key'=>'page_title', 'guidance'=>'1-48 characters...',
 *         'value_text'=>'Text for testing', 'value_html'=>'<strong>Text for testing</strong>',
 *         'filled'=>true],
 *        ...
 *     ],
 *   ]
 *
 * Column 0 is treated purely as identifiers (block type + field names + guidance) and
 * NEVER appears in the content payload. Column 1 is the actual page content.
 * Fields are kept flat (no repeat-grouping) per current decision; empty fields are
 * retained with filled=false so the pipeline can see the full template shape.
 */
function tables_to_blocks(array $nodes): array
{
    $blocks = [];
    $i = 0;
    foreach ($nodes as $node) {
        if ($node['type'] !== 'table') continue;
        $cells = $node['meta']['cells'] ?? [];
        if (!$cells) continue;

        // Row 0, col 0 = block-type identifier + guidance.
        $typeCell = split_label_cell($cells[0][0]['text'] ?? '');
        $block = [
            'index' => $i++,
            'type' => $typeCell['label'],
            'type_slug' => slugify($typeCell['label']),
            'guidance' => $typeCell['guidance'],
            'rows' => ($node['meta']['rows'] ?? count($cells)),
            'fields' => [],
        ];

        // Remaining rows: col 0 = field name, col 1 = content value.
        for ($r = 1; $r < count($cells); $r++) {
            $labelText = $cells[$r][0]['text'] ?? '';
            $valueText = $cells[$r][1]['text'] ?? '';
            $valueHtml = $cells[$r][1]['html'] ?? '';

            // Skip fully blank rows (spacer rows appear in several templates).
            if (trim($labelText) === '' && trim($valueText) === '') continue;

            $parts = split_label_cell($labelText);
            $block['fields'][] = [
                'label' => $parts['label'],
                'key' => slugify($parts['label']),
                'guidance' => $parts['guidance'],
                'value_text' => trim($valueText),
                'value_html' => $valueHtml,
                'filled' => trim($valueText) !== '',
            ];
        }

        $blocks[] = $block;
    }
    return $blocks;
}

/**
 * Return only the nodes that appear AFTER the first node whose text contains $marker
 * (case-insensitive, whitespace-normalized). The marker node itself is excluded.
 * This template convention puts writer instructions above a divider line
 * ("Writing Begins Beneath This Line") and real page content below it.
 *
 * If the marker is never found, returns all nodes unchanged and sets $found = false
 * (via the by-reference param) so callers can warn instead of silently emptying output.
 */
function nodes_after_marker(array $nodes, string $marker, ?bool &$found = null): array
{
    $needle = mb_strtolower(preg_replace('/\s+/', ' ', trim($marker)));
    $found = false;
    $result = [];
    foreach ($nodes as $node) {
        if (!$found) {
            $hay = mb_strtolower(preg_replace('/\s+/', ' ', trim($node['text'])));
            if ($needle !== '' && str_contains($hay, $needle)) {
                $found = true; // start capturing from the NEXT node
            }
            continue;
        }
        $result[] = $node;
    }
    return $found ? $result : $nodes;
}

/**
 * A compact, order-sensitive fingerprint of a document's structure. Two docs with
 * the same fingerprint have the same heading outline, same block sequence, same text.
 * Used by roundtrip.php to detect what the docx round-trip changed.
 */
function structural_fingerprint(array $nodes): array
{
    $fp = [];
    foreach ($nodes as $n) {
        $fp[] = [
            'type' => $n['type'],
            'level' => $n['level'],
            'text' => preg_replace('/\s+/', ' ', trim($n['text'])),
            'bold' => $n['fmt']['bold'] ?? false,
            'italic' => $n['fmt']['italic'] ?? false,
            'link' => $n['fmt']['link'] ?? false,
        ];
    }
    return $fp;
}
