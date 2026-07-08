<?php
/**
 * QUESTION 3 — Does the block model survive an export to .docx and re-import to Google Docs?
 *
 * Compares two block-model JSON files (produced by walk_doc.php, in out/*.blocks.json):
 * a BEFORE (original Google Doc) and an AFTER (round-tripped through Word .docx).
 * Reports, at the level our pipeline actually depends on, whether they match:
 *   - same number of blocks?
 *   - same block type_slugs, in the same order?
 *   - within each block, same field keys?
 *   - within each field, same content HTML (col-1) and filled state?
 *
 * "Survives" = the two produce the SAME block model. Visual fidelity is irrelevant here.
 *
 * Usage: php compare_blocks.php out/Before.blocks.json out/After.blocks.json
 * Exit code: 0 if identical for our purposes, 1 if any difference found.
 */

declare(strict_types=1);

$beforePath = $argv[1] ?? '';
$afterPath  = $argv[2] ?? '';
if ($beforePath === '' || $afterPath === '') {
    fwrite(STDERR, "Usage: php compare_blocks.php <before.blocks.json> <after.blocks.json>\n");
    exit(2);
}

$before = load_blocks($beforePath);
$after  = load_blocks($afterPath);

echo str_repeat('=', 70) . "\n";
echo "Round-trip comparison\n";
echo "  before: {$beforePath}\n";
echo "  after:  {$afterPath}\n";
echo str_repeat('=', 70) . "\n\n";

$diffs = [];

// 1. Block count
if (count($before) !== count($after)) {
    $diffs[] = sprintf("Block COUNT differs: before=%d, after=%d", count($before), count($after));
}

// 2/3/4. Walk block-by-block (by position, since order is meaningful on the page).
$max = max(count($before), count($after));
for ($i = 0; $i < $max; $i++) {
    $b = $before[$i] ?? null;
    $a = $after[$i] ?? null;

    if ($b === null) { $diffs[] = "Block [$i]: present in AFTER only (type: {$a['type_slug']})"; continue; }
    if ($a === null) { $diffs[] = "Block [$i]: present in BEFORE only (type: {$b['type_slug']})"; continue; }

    $label = "Block [$i] ({$b['type_slug']})";

    // 2. type_slug
    if ($b['type_slug'] !== $a['type_slug']) {
        $diffs[] = "$label: type_slug changed: '{$b['type_slug']}' -> '{$a['type_slug']}'";
        continue; // different block type; field comparison would be noise
    }

    // 3/4. Field keys + content
    $bf = fields_by_key($b);
    $af = fields_by_key($a);

    foreach ($bf as $key => $bv) {
        if (!isset($af[$key])) {
            $diffs[] = "$label: field '{$key}' MISSING after round-trip";
            continue;
        }
        $av = $af[$key];
        if (normalize_html($bv['html']) !== normalize_html($av['html'])) {
            $diffs[] = "$label: field '{$key}' CONTENT changed:\n"
                     . "        before: " . short($bv['html']) . "\n"
                     . "        after:  " . short($av['html']);
        }
        if (($bv['filled'] ?? false) !== ($av['filled'] ?? false)) {
            $diffs[] = "$label: field '{$key}' filled-state changed: "
                     . var_export($bv['filled'] ?? false, true) . " -> "
                     . var_export($av['filled'] ?? false, true);
        }
    }
    foreach ($af as $key => $av) {
        if (!isset($bf[$key])) {
            $diffs[] = "$label: field '{$key}' NEW after round-trip (not in before)";
        }
    }
}

// Report
if (!$diffs) {
    echo "RESULT: IDENTICAL for our purposes.\n";
    echo "The block model survives the Word .docx round-trip. \n";
    echo "  blocks: " . count($before) . " (same order, same type_slugs, same field keys, same content)\n";
    exit(0);
}

echo "RESULT: " . count($diffs) . " difference(s) found — the round-trip ALTERED the model:\n\n";
foreach ($diffs as $d) {
    echo "  • $d\n";
}
echo "\nEach difference above is a place the pipeline would break or drift after a Word edit.\n";
exit(1);


// ---------------------------------------------------------------------------

function load_blocks(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(2);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Not valid block-model JSON: {$path}\n");
        exit(2);
    }
    return $data;
}

/**
 * Flatten a block's content to key => ['html','filled'], covering both simple blocks
 * ('fields' with value_html) and any nested shape. Uses the raw tables_to_blocks output
 * shape (fields[] with 'key','value_html','filled').
 */
function fields_by_key(array $block): array
{
    $out = [];
    foreach (($block['fields'] ?? []) as $f) {
        $out[$f['key']] = ['html' => $f['value_html'] ?? '', 'filled' => $f['filled'] ?? false];
    }
    return $out;
}

/** Collapse whitespace so trivial spacing changes aren't reported as content changes. */
function normalize_html(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', $html));
}

function short(string $s): string
{
    $s = str_replace("\n", ' / ', $s);
    return mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
}
