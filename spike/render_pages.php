<?php
/**
 * STEP — create/update WP pages from Google Docs AND render a real Elementor block.
 *
 * Builds on create_pages_from_docs.php (title + sitemap placement) and adds block
 * rendering: it fills an exported Elementor template ("Hero Test" flexbox: an e-heading
 * + an e-paragraph) with doc content and writes it into the page's _elementor_data.
 *
 * Field mapping:
 *   e-heading   <- page_title_h1  from ANY "hero*" block  (the page title)
 *   e-paragraph <- body_content   from the "text_introduction" block
 *
 * Content is injected as PLAIN TEXT (the atomic html-v3 format stores it at
 * settings.<slot>.value.content.value as a string). Inline bold/italic is NOT yet
 * supported for atomic widgets — that needs a verified filled-with-formatting example.
 *
 * Skeleton source: templates/hero_intro.json (an Elementor template export; we use its
 * inner "content" array). Each page gets FRESH element IDs so no two pages share IDs.
 *
 * Usage:
 *   php render_pages.php --dry-run
 *   php render_pages.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doc_model.php';
require __DIR__ . '/wp_bootstrap.php';

use Google\Service\Drive;
use Google\Service\Docs;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$folderId = env_required('DRIVE_FOLDER_ID');

const TITLE_BLOCK_PREFIX = 'hero';
const TITLE_FIELD_KEY    = 'page_title_h1';
const INTRO_BLOCK_SLUG   = 'text_introduction';
const INTRO_FIELD_KEY    = 'body_content';
const TEMPLATE_PATH      = __DIR__ . '/templates/hero_texteditor.json';

// Load + validate the skeleton once.
$skeleton = load_skeleton(TEMPLATE_PATH);

$client = make_client();
$drive  = new Drive($client);
$docs   = new Docs($client);

echo "\n" . str_repeat('=', 70) . "\n";
echo ($dryRun ? "DRY RUN — no pages will be written\n" : "LIVE RUN — pages will be created/updated\n");
echo str_repeat('=', 70) . "\n";

// --- 1. Build the plan from Drive/Docs ---
$folderNameCache = [];
$plan = [];
$query = sprintf(
    "'%s' in parents and mimeType = 'application/vnd.google-apps.document' and trashed = false",
    $folderId
);
$pageToken = null;
do {
    $resp = $drive->files->listFiles([
        'q' => $query,
        'fields' => 'nextPageToken, files(id, name, parents)',
        'pageSize' => 100,
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ]);
    foreach ($resp->getFiles() as $file) {
        $plan[] = build_plan_entry($file, $drive, $docs, $folderNameCache);
    }
    $pageToken = $resp->getNextPageToken();
} while ($pageToken);

if (!$plan) { fwrite(STDERR, "No Google Docs found in folder {$folderId}.\n"); exit(1); }

// --- 2. Pre-flight parent hierarchy (all-or-nothing) ---
boot_wordpress();
$parentPageIds = [];
$missing = [];
foreach (array_unique(array_filter(array_map(fn($p) => $p['folder_name'], $plan))) as $folderName) {
    $pid = find_page_by_title($folderName);
    if ($pid === null) $missing[] = $folderName;
    else $parentPageIds[mb_strtolower(trim($folderName))] = $pid;
}
if ($missing) {
    fwrite(STDERR, "\nSTOP: no WP page matches parent folder name(s):\n");
    foreach ($missing as $m) fwrite(STDERR, "  - \"{$m}\"\n");
    fwrite(STDERR, "\nNothing was written.\n");
    exit(2);
}

// --- 3. Render + upsert ---
printf("\n%d doc(s) to process:\n\n", count($plan));
$exit = 0;
foreach ($plan as $p) {
    if ($p['error']) {
        printf("  [SKIP] %s\n         %s\n", $p['doc_name'], $p['error']);
        $exit = 1;
        continue;
    }
    $parentId = $parentPageIds[mb_strtolower(trim($p['folder_name']))];
    $existing = find_page_by_gdoc_id($p['doc_id']);
    $action = $existing ? "UPDATE #{$existing}" : "CREATE";

    printf("  [%s] \"%s\"  under \"%s\" (#%d)\n", $dryRun ? "PLAN {$action}" : $action, $p['title'], $p['folder_name'], $parentId);
    printf("        heading  <- %s\n", short($p['title']));
    printf("        paragraph<- %s\n", $p['intro'] !== '' ? short($p['intro']) : '(no Text-Introduction body)');

    if ($dryRun) continue;

    $elementorData = render_block_data($p['title'], $p['intro']);
    $pageId = upsert_page($p['title'], $parentId, $p['doc_id'], $existing, $elementorData);
    printf("        => page #%d  %s\n", $pageId, get_permalink($pageId));
}

echo "\n" . ($dryRun ? "Dry run complete.\n" : "Done.\n");
exit($exit);


// ---------------------------------------------------------------------------

function load_skeleton(string $path): array
{
    if (!is_file($path)) { fwrite(STDERR, "Template not found: {$path}\n"); exit(1); }
    $j = json_decode((string) file_get_contents($path), true);
    if (!isset($j['content']) || !is_array($j['content'])) {
        fwrite(STDERR, "Template has no 'content' array: {$path}\n"); exit(1);
    }
    return $j['content']; // the inner element tree = what _elementor_data holds
}

function build_plan_entry(object $file, Drive $drive, Docs $docs, array &$cache): array
{
    $e = ['doc_id' => $file->getId(), 'doc_name' => $file->getName(),
          'folder_name' => null, 'title' => null, 'intro' => '', 'error' => null];

    $parents = $file->getParents() ?? [];
    if (!$parents) { $e['error'] = "doc has no parent folder"; return $e; }
    $fid = $parents[0];
    if (!isset($cache[$fid])) {
        $cache[$fid] = $drive->files->get($fid, ['fields' => 'name', 'supportsAllDrives' => true])->getName();
    }
    $e['folder_name'] = $cache[$fid];

    try {
        $doc = $docs->documents->get($file->getId());
        $nodes = walk_document($doc);
        $marker = $_ENV['CONTENT_START_MARKER'] ?? 'Writing Begins Beneath This Line';
        if ($marker !== '') $nodes = nodes_after_marker($nodes, $marker);
        $blocks = tables_to_blocks($nodes);

        // Title -> heading widget: PLAIN TEXT (titles aren't styled HTML).
        $title = field_value($blocks, fn($b) => str_starts_with($b['type_slug'], TITLE_BLOCK_PREFIX), TITLE_FIELD_KEY, false);
        if ($title === null) { $e['error'] = "no '" . TITLE_FIELD_KEY . "' in any 'hero*' block"; return $e; }
        $e['title'] = $title;

        // Intro -> Text Editor widget: HTML, so bold/italic/links survive.
        $introHtml = field_value($blocks, fn($b) => $b['type_slug'] === INTRO_BLOCK_SLUG, INTRO_FIELD_KEY, true);
        $e['intro'] = $introHtml !== null ? clean_link_underline($introHtml) : '';   // optional
    } catch (\Throwable $ex) {
        $e['error'] = "read failed: " . $ex->getMessage();
    }
    return $e;
}

/** First filled field matching a block predicate + field key. $asHtml picks value_html vs value_text. */
function field_value(array $blocks, callable $blockMatch, string $fieldKey, bool $asHtml = false): ?string
{
    foreach ($blocks as $b) {
        if (!$blockMatch($b)) continue;
        foreach ($b['fields'] as $f) {
            if ($f['key'] === $fieldKey && $f['filled']) {
                return trim($asHtml ? $f['value_html'] : $f['value_text']);
            }
        }
    }
    return null;
}

/**
 * Google wraps links as <a href="..."><u>text</u></a> — the <u> is just its link
 * styling, not real emphasis. Strip <u>/</u> that sit directly inside an <a>, keeping
 * the link and its text. Leaves standalone underlines (rare) alone.
 */
function clean_link_underline(string $html): string
{
    // <a ...><u>...</u></a>  ->  <a ...>...</a>
    return preg_replace('#(<a\b[^>]*>)\s*<u>(.*?)</u>\s*(</a>)#is', '$1$2$3', $html);
}

/**
 * Clone the skeleton and inject content, fresh IDs. Returns JSON string.
 *   e-heading    (atomic)  <- heading text, PLAIN
 *   text-editor  (classic) <- intro HTML (bold/italic/links preserved)
 * (e-paragraph still handled for older templates.)
 */
function render_block_data(string $headingText, string $introHtml): string
{
    $tree = json_decode(json_encode($GLOBALS['skeleton']), true); // deep clone
    walk_elements($tree, function (array &$el) use ($headingText, $introHtml) {
        $wt = $el['widgetType'] ?? '';
        if ($wt === 'e-heading')      set_atomic_text($el, 'title', $headingText);
        elseif ($wt === 'e-paragraph') set_atomic_text($el, 'paragraph', strip_tags($introHtml));
        elseif ($wt === 'text-editor') set_texteditor_html($el, $introHtml);
        // fresh element id so pages don't share IDs
        if (isset($el['id'])) $el['id'] = new_elementor_id();
    });
    return json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Set a classic Text Editor widget's content. Its content lives in settings.editor as
 * an HTML string (wrapped in <p> the way the WYSIWYG stores it). We wrap the intro HTML
 * in <p> if it isn't already block-wrapped, so bold/italic/links render correctly.
 */
function set_texteditor_html(array &$el, string $html): void
{
    if ($html === '') return;
    if (!preg_match('#^\s*<(p|div|ul|ol|h[1-6])\b#i', $html)) {
        $html = '<p>' . $html . '</p>';
    }
    // settings may be [] (empty) on an untouched widget; make it an object.
    if (!is_array($el['settings']) || array_is_list($el['settings'])) {
        $el['settings'] = [];
    }
    $el['settings']['editor'] = $html;
}

/** Set an atomic widget's html-v3 slot to plain text (settings.<slot>.value.content.value). */
function set_atomic_text(array &$el, string $slot, string $text): void
{
    if ($text === '') return;
    $el['settings'][$slot] = [
        '$$type' => 'html-v3',
        'value' => [
            'content' => ['$$type' => 'string', 'value' => $text],
            'children' => [],
        ],
    ];
}

/** Recursively walk element tree, applying $fn by reference to each element. */
function walk_elements(array &$elements, callable $fn): void
{
    foreach ($elements as &$el) {
        $fn($el);
        if (!empty($el['elements']) && is_array($el['elements'])) {
            walk_elements($el['elements'], $fn);
        }
    }
    unset($el);
}

/** Elementor element IDs are 7-8 hex chars. Vary by a global counter (no rand in this env). */
function new_elementor_id(): string
{
    static $n = 0;
    $n++;
    return substr(md5('gdoc-spike-' . $n . '-' . microtime(false)), 0, 8);
}

function find_page_by_title(string $title): ?int
{
    $needle = mb_strtolower(trim($title));
    foreach (get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]) as $p) {
        if (mb_strtolower(trim($p->post_title)) === $needle) return (int) $p->ID;
    }
    return null;
}

function find_page_by_gdoc_id(string $docId): ?int
{
    $q = get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1,
                    'meta_key' => '_gdoc_id', 'meta_value' => $docId]);
    return $q ? (int) $q[0]->ID : null;
}

function upsert_page(string $title, int $parentId, string $docId, ?int $existing, string $elementorData): int
{
    $postarr = ['post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parentId];
    if ($existing) { $postarr['ID'] = $existing; $pageId = wp_update_post($postarr, true); }
    else { $pageId = wp_insert_post($postarr, true); }
    if (is_wp_error($pageId)) { fwrite(STDERR, "  ! save failed '{$title}': " . $pageId->get_error_message() . "\n"); return 0; }

    update_post_meta($pageId, '_gdoc_id', $docId);
    update_post_meta($pageId, '_elementor_edit_mode', 'builder');
    update_post_meta($pageId, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
    // CRITICAL: _elementor_data is a JSON string. update_post_meta() runs wp_unslash()
    // on the value, which would strip json_encode's \" escapes and corrupt the JSON
    // (e.g. an <a href="..."> breaks it). Pre-slash so the stored value stays valid JSON.
    // This is how Elementor itself saves this meta.
    update_post_meta($pageId, '_elementor_data', wp_slash($elementorData));
    update_post_meta($pageId, '_wp_page_template', 'elementor_canvas');
    return (int) $pageId;
}

function short(string $s): string
{
    $s = str_replace("\n", ' / ', $s);
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
}
