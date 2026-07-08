<?php
/**
 * QUESTION 2 — Can we iterate one doc and access its contents like a DOM,
 * finding certain content?
 *
 * Fetches a single doc via the Docs API and prints the BLOCK MODEL:
 *   each content table => a typed block whose column-0 cells are identifiers
 *   (block type + field names) and column-1 cells are the fillable content.
 *   Column 0 NEVER becomes page content — it only names the block and its fields.
 *
 * Usage:
 *   php walk_doc.php <DOC_ID>              # print + dump the block model
 *   php walk_doc.php <DOC_ID> --outline    # also print the raw DOM outline (debug)
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doc_model.php';

use Google\Service\Docs;

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$docId = $positional[0] ?? '';
$showOutline = in_array('--outline', $flags, true);

if ($docId === '') {
    fwrite(STDERR, "Usage: php walk_doc.php <DOC_ID> [--outline]   (get an id from list_docs.php)\n");
    exit(1);
}

$docs = new Docs(make_client());
$doc = $docs->documents->get($docId);

section("Block model for \"{$doc->getTitle()}\"");

$nodes = walk_document($doc);

// Drop the writer-instructions preamble; keep only content after the divider marker.
$marker = $_ENV['CONTENT_START_MARKER'] ?? 'Writing Begins Beneath This Line';
if ($marker !== '') {
    $nodes = nodes_after_marker($nodes, $marker, $found);
    echo $found
        ? "\nTrimmed preamble: capturing only content after \"{$marker}\".\n"
        : "\nWARNING: marker \"{$marker}\" not found — using the whole document.\n";
}

// Optional raw DOM outline (debug only — includes column-0 identifier cells).
if ($showOutline) {
    print_outline($nodes);
}

// --- Block model: each content table => a typed block with fields ---
// Column 0 = identifiers (block type + field names). Column 1 = fillable content.
echo "\n--- Block model (content tables => blocks) ---\n";
$blocks = tables_to_blocks($nodes);
foreach ($blocks as $b) {
    $filled = count(array_filter($b['fields'], fn($f) => $f['filled']));
    printf("\n[%d] %s  (slug: %s)  — %d field(s), %d filled\n",
        $b['index'], $b['type'], $b['type_slug'], count($b['fields']), $filled);
    if ($b['guidance'] !== '') {
        printf("     guidance: %s\n", mb_substr($b['guidance'], 0, 80));
    }
    foreach ($b['fields'] as $f) {
        $mark = $f['filled'] ? '●' : '○';
        $val = $f['filled'] ? mb_substr(str_replace("\n", ' / ', $f['value_html']), 0, 70) : '(empty)';
        printf("     %s %-22s [%s] %s\n", $mark, $f['label'], $f['key'], $val);
    }
}

echo "\n\n=> Each content table is a typed block; column 0 gives the block type + field\n";
echo "   names, column 1 gives the fillable content (with inline HTML). ● = filled, ○ = empty.\n";

// Dump the block model JSON.
@mkdir(__DIR__ . '/out', 0777, true);
$base = __DIR__ . '/out/' . preg_replace('/[^a-z0-9]+/i', '_', $doc->getTitle());
file_put_contents($base . '.blocks.json', json_encode($blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "   Wrote block model to {$base}.blocks.json\n";


// ---------------------------------------------------------------------------

/** Debug: raw DOM-like dump of every node, including column-0 identifier cells. */
function print_outline(array $nodes): void
{
    echo "\n--- Raw DOM outline (debug; includes column-0 identifiers) ---\n";
    foreach ($nodes as $i => $n) {
        $tag = strtoupper($n['type']) . ($n['level'] !== null ? " L{$n['level']}" : '');
        $preview = mb_substr(str_replace("\n", ' / ', $n['text']), 0, 90);
        printf("%3d [%s] %s\n", $i, $tag, $preview);
        if ($n['type'] === 'table' && !empty($n['meta']['cells'])) {
            foreach ($n['meta']['cells'] as $r => $row) {
                foreach ($row as $c => $cell) {
                    $txt = mb_substr(str_replace("\n", ' / ', $cell['text']), 0, 80);
                    if (trim($txt) !== '') printf("        (r%d,c%d) %s\n", $r, $c, $txt);
                }
            }
        }
    }
}
