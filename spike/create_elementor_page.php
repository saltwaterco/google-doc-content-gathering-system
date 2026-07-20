<?php
/**
 * STEP — create a blank, Elementor-EDITABLE page programmatically (no plugin).
 *
 * An "Elementor page" is just a normal WP page with special post meta:
 *   _elementor_edit_mode = 'builder'   -> render/edit with Elementor, not the classic editor
 *   _elementor_data      = '[]'        -> the widget tree (empty = blank Elementor page)
 *   _elementor_version   = <version>   -> which Elementor built it (for data migrations)
 *   _wp_page_template    = 'elementor_canvas' -> blank canvas (no theme header/footer)
 *
 * This proves the mechanic before we place any real blocks: it creates one empty page
 * and prints the URLs to confirm it opens in the Elementor editor.
 *
 * Usage:
 *   php create_elementor_page.php "My Page Title"
 * Env:
 *   WP_CLI_DB_HOST=127.0.0.1:3306  (override DB host if not MAMP)
 */

declare(strict_types=1);

require __DIR__ . '/wp_bootstrap.php';
boot_wordpress();

$title = $argv[1] ?? 'Untitled Elementor Page (spike)';

// 1. Create the page itself.
$postId = wp_insert_post([
    'post_title'   => $title,
    'post_type'    => 'page',
    'post_status'  => 'publish',
    'post_content' => '', // Elementor stores content in meta, not post_content
], true);

if (is_wp_error($postId)) {
    fwrite(STDERR, "Failed to create page: " . $postId->get_error_message() . "\n");
    exit(1);
}

// 2. Make it an Elementor page.
$elementorVersion = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0';
update_post_meta($postId, '_elementor_edit_mode', 'builder');
update_post_meta($postId, '_elementor_version', $elementorVersion);
update_post_meta($postId, '_elementor_data', '[]');            // empty widget tree
update_post_meta($postId, '_wp_page_template', 'elementor_canvas');

// 3. Report + verify by reading the meta back.
$editMode = get_post_meta($postId, '_elementor_edit_mode', true);
$data     = get_post_meta($postId, '_elementor_data', true);
$template = get_post_meta($postId, '_wp_page_template', true);

$adminUrl = admin_url("post.php?post={$postId}&action=elementor");
$viewUrl  = get_permalink($postId);

echo "\n";
echo "Created Elementor page\n";
echo "  ID:            {$postId}\n";
echo "  Title:         {$title}\n";
echo "  edit_mode:     {$editMode}\n";
echo "  data:          {$data}  (empty tree = blank Elementor page)\n";
echo "  page_template: {$template}\n";
echo "\n";
echo "  Edit in Elementor:  {$adminUrl}\n";
echo "  View page:          {$viewUrl}\n";
echo "\n";
echo "=> Open the 'Edit in Elementor' URL. If it loads the Elementor editor with an\n";
echo "   empty canvas, the mechanic works and we're ready to place real blocks.\n";
