<?php
/**
 * Boots this repo's WordPress from the CLI, for the page-building spike scripts.
 *
 * Why this exists: wp-config.php sets DB_HOST='localhost', which PHP-CLI resolves to
 * the WRONG default mysql socket (/tmp/mysql.sock), so WP can't reach the DB from the
 * command line even though the browser works fine. We fix this WITHOUT editing
 * wp-config.php (it's shared with the working web setup) by defining DB_HOST to the
 * MAMP socket *before* wp-load runs — the first define() wins.
 *
 * Override the DB host if your stack differs:
 *   WP_CLI_DB_HOST='127.0.0.1:3306' php create_elementor_page.php ...
 */

declare(strict_types=1);

function boot_wordpress(): void
{
    // Default to the confirmed MAMP socket; allow env override.
    $dbHost = getenv('WP_CLI_DB_HOST') ?: 'localhost:/Applications/MAMP/tmp/mysql/mysql.sock';

    if (!defined('DB_HOST')) {
        define('DB_HOST', $dbHost);   // must be defined before wp-config runs
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    // WP expects a host header even on CLI; harmless placeholder.
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $wpLoad = dirname(__DIR__) . '/wp-load.php';
    if (!is_file($wpLoad)) {
        fwrite(STDERR, "Cannot find wp-load.php at {$wpLoad}\n");
        exit(1);
    }

    // wp-config.php re-defines DB_HOST with a bare define(), which emits a benign
    // "Constant DB_HOST already defined" warning (our earlier define wins, so the DB
    // connection still uses our value). Filter out ONLY that one warning with a
    // temporary handler, and let everything else through normally.
    set_error_handler(function ($errno, $errstr) {
        if (str_contains($errstr, 'Constant DB_HOST already defined')) {
            return true; // swallow just this one
        }
        return false; // defer to PHP's normal handler
    }, E_WARNING);
    require $wpLoad;
    restore_error_handler();

    if (!function_exists('wp_insert_post')) {
        fwrite(STDERR, "WordPress did not load correctly.\n");
        exit(1);
    }
    if (!defined('ELEMENTOR_VERSION')) {
        fwrite(STDERR, "WARNING: Elementor does not appear to be active.\n");
    }
}
