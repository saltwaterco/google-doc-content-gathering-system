<?php
/**
 * STEP — create/update WP pages from Google Docs, with title + sitemap placement.
 *
 * For each Google Doc in DRIVE_FOLDER_ID:
 *   1. Read its parent Drive FOLDER NAME.
 *   2. Extract the "Hero - Title & Buttons" block -> "Page Title (H1)" field (plain text).
 *   3. Create/update a WP page whose:
 *        - post_title  = that H1 text
 *        - post_parent = the existing WP page whose title matches the folder name
 *        - _gdoc_id meta = the Doc ID (so re-runs UPDATE instead of duplicating)
 *      Pages are Elementor-ready (builder meta) but with an EMPTY widget tree — this
 *      step verifies titling + placement only, not block rendering.
 *
 * Design decisions:
 *   - Parent match: case-insensitive + trimmed (folder name <-> page title).
 *   - Missing parent: STOP before creating anything (all-or-nothing pre-flight).
 *   - Re-run: idempotent via _gdoc_id meta.
 *
 * Usage:
 *   php create_pages_from_docs.php --dry-run   # preview, write nothing
 *   php create_pages_from_docs.php             # create/update for real
 */

declare(strict_types=1);

// --- Load the Google side FIRST (Composer autoload + .env + auth), then WordPress. ---
require __DIR__ . '/bootstrap.php';      // make_client(), env_required(), Composer autoload
require __DIR__ . '/doc_model.php';      // walk_document(), nodes_after_marker(), tables_to_blocks()
require __DIR__ . '/wp_bootstrap.php';   // boot_wordpress()

use Google\Service\Drive;
use Google\Service\Docs;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$folderId = env_required('DRIVE_FOLDER_ID');

// Which block/field supplies the page title. The template has multiple Hero variants
// (e.g. "Hero - Title & Buttons", "Hero - Style 3"), and ALL of them carry the same
// "Page Title (H1)" field. So we match the FIELD key inside ANY block whose type starts
// with "hero", rather than pinning to one Hero variant.
const TITLE_BLOCK_PREFIX = 'hero';
const TITLE_FIELD_KEY    = 'page_title_h1';

$client = make_client();
$drive  = new Drive($client);
$docs   = new Docs($client);

echo "\n" . str_repeat('=', 70) . "\n";
echo ($dryRun ? "DRY RUN — no pages will be written\n" : "LIVE RUN — pages will be created/updated\n");
echo str_repeat('=', 70) . "\n";

// --- 1. Gather docs + build a plan (no writes yet) ---
$folderNameCache = [];
$plan = []; // each: ['doc_id','doc_name','folder_name','title','error']

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

if (!$plan) {
    fwrite(STDERR, "No Google Docs found in folder {$folderId}.\n");
    exit(1);
}

// --- 2. Pre-flight: every needed parent folder name must match an existing WP page ---
boot_wordpress();

$neededParents = array_unique(array_filter(array_map(fn($p) => $p['folder_name'], $plan)));
$parentPageIds = [];   // folder_name(lower) => WP page ID
$missingParents = [];
foreach ($neededParents as $folderName) {
    $pageId = find_page_by_title($folderName);
    if ($pageId === null) {
        $missingParents[] = $folderName;
    } else {
        $parentPageIds[mb_strtolower(trim($folderName))] = $pageId;
    }
}
if ($missingParents) {
    fwrite(STDERR, "\nSTOP: no WP page matches these parent folder name(s):\n");
    foreach ($missingParents as $m) fwrite(STDERR, "  - \"{$m}\"\n");
    fwrite(STDERR, "\nCreate matching page(s) first, then re-run. Nothing was written.\n");
    exit(2);
}

// --- 3. Report the plan, then execute (unless dry run) ---
printf("\n%d doc(s) to process:\n\n", count($plan));
$exit = 0;
foreach ($plan as $p) {
    if ($p['error']) {
        printf("  [SKIP] %s\n         reason: %s\n", $p['doc_name'], $p['error']);
        $exit = 1;
        continue;
    }
    $parentId = $parentPageIds[mb_strtolower(trim($p['folder_name']))];
    $existing = find_page_by_gdoc_id($p['doc_id']);
    $action = $existing ? "UPDATE (page #{$existing})" : "CREATE";

    printf("  [%s] title: \"%s\"\n        under: \"%s\" (page #%d)\n        doc:   %s\n",
        $dryRun ? "PLAN {$action}" : $action, $p['title'], $p['folder_name'], $parentId, $p['doc_id']);

    if ($dryRun) continue;

    $pageId = upsert_page($p['title'], $parentId, $p['doc_id'], $existing);
    printf("        => page #%d  %s\n", $pageId, get_permalink($pageId));
}

echo "\n" . ($dryRun
    ? "Dry run complete. Re-run without --dry-run to write these pages.\n"
    : "Done.\n");
exit($exit);


// ---------------------------------------------------------------------------

function build_plan_entry(object $file, Drive $drive, Docs $docs, array &$cache): array
{
    $entry = ['doc_id' => $file->getId(), 'doc_name' => $file->getName(),
              'folder_name' => null, 'title' => null, 'error' => null];

    // Parent folder name.
    $parents = $file->getParents() ?? [];
    if (!$parents) { $entry['error'] = "doc has no parent folder"; return $entry; }
    $folderId = $parents[0];
    if (!isset($cache[$folderId])) {
        $cache[$folderId] = $drive->files->get($folderId, ['fields' => 'name', 'supportsAllDrives' => true])->getName();
    }
    $entry['folder_name'] = $cache[$folderId];

    // Extract the Hero block's Page Title (H1) as plain text.
    try {
        $doc = $docs->documents->get($file->getId());
        $nodes = walk_document($doc);
        $marker = $_ENV['CONTENT_START_MARKER'] ?? 'Writing Begins Beneath This Line';
        if ($marker !== '') $nodes = nodes_after_marker($nodes, $marker);
        $blocks = tables_to_blocks($nodes);

        $title = extract_title($blocks);
        if ($title === null) {
            $entry['error'] = "no '" . TITLE_FIELD_KEY . "' value in any '" . TITLE_BLOCK_PREFIX . "*' block";
        } else {
            $entry['title'] = $title;
        }
    } catch (\Throwable $e) {
        $entry['error'] = "read failed: " . $e->getMessage();
    }
    return $entry;
}

/** Pull the plain-text Page Title (H1) value from any Hero-variant block. */
function extract_title(array $blocks): ?string
{
    foreach ($blocks as $b) {
        if (!str_starts_with($b['type_slug'], TITLE_BLOCK_PREFIX)) continue;
        foreach ($b['fields'] as $f) {
            if ($f['key'] === TITLE_FIELD_KEY && $f['filled']) {
                return trim($f['value_text']);   // titles are plain text, not HTML
            }
        }
    }
    return null;
}

/** Case-insensitive + trimmed page-title lookup. Returns page ID or null. */
function find_page_by_title(string $title): ?int
{
    $needle = mb_strtolower(trim($title));
    foreach (get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]) as $p) {
        if (mb_strtolower(trim($p->post_title)) === $needle) return (int) $p->ID;
    }
    return null;
}

/** Find a page previously created for this Doc ID (idempotency). */
function find_page_by_gdoc_id(string $docId): ?int
{
    $q = get_posts([
        'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1,
        'meta_key' => '_gdoc_id', 'meta_value' => $docId,
    ]);
    return $q ? (int) $q[0]->ID : null;
}

/** Create or update the page; returns its ID. */
function upsert_page(string $title, int $parentId, string $docId, ?int $existing): int
{
    $postarr = [
        'post_title'  => $title,
        'post_type'   => 'page',
        'post_status' => 'publish',
        'post_parent' => $parentId,
    ];
    if ($existing) {
        $postarr['ID'] = $existing;
        $pageId = wp_update_post($postarr, true);
    } else {
        $pageId = wp_insert_post($postarr, true);
    }
    if (is_wp_error($pageId)) {
        fwrite(STDERR, "  ! failed to save '{$title}': " . $pageId->get_error_message() . "\n");
        return 0;
    }

    // Link to the doc + make Elementor-ready (empty tree for now).
    update_post_meta($pageId, '_gdoc_id', $docId);
    update_post_meta($pageId, '_elementor_edit_mode', 'builder');
    update_post_meta($pageId, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
    if (get_post_meta($pageId, '_elementor_data', true) === '') {
        update_post_meta($pageId, '_elementor_data', '[]');
    }
    update_post_meta($pageId, '_wp_page_template', 'elementor_canvas');
    return (int) $pageId;
}
