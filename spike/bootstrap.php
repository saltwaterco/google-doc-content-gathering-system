<?php
/**
 * Shared setup for all spike scripts: load .env, build an authenticated Google_Client
 * scoped for Docs (read) + Drive (read/write for the round-trip).
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as GoogleClient;

Dotenv::createImmutable(__DIR__)->safeLoad();

function env_required(string $key): string
{
    $val = $_ENV[$key] ?? getenv($key) ?: '';
    if ($val === '') {
        fwrite(STDERR, "Missing required env var: {$key}. Copy .env.example to .env and fill it in.\n");
        exit(1);
    }
    return $val;
}

function make_client(): GoogleClient
{
    $keyPath = env_required('GOOGLE_APPLICATION_CREDENTIALS');
    if (!is_file($keyPath)) {
        fwrite(STDERR, "Service account key not found at: {$keyPath}\n");
        exit(1);
    }

    $client = new GoogleClient();
    $client->setAuthConfig($keyPath);
    // Read-only is all Q1/Q2 need: list the folder + read each doc's structure.
    $client->setScopes([
        \Google\Service\Docs::DOCUMENTS_READONLY,
        \Google\Service\Drive::DRIVE_READONLY,
    ]);
    return $client;
}

/** Small helper: pretty-print a labeled section header to stdout. */
function section(string $title): void
{
    echo "\n" . str_repeat('=', 70) . "\n{$title}\n" . str_repeat('=', 70) . "\n";
}
