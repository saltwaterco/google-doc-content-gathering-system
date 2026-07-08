<?php
/**
 * QUESTION 1 — Can we programmatically access a group of gdocs in a Drive folder?
 *
 * Lists every Google Doc in DRIVE_FOLDER_ID (handles pagination), printing each
 * doc's id, name, and modifiedTime. The id is what you feed to walk_doc.php / roundtrip.php.
 *
 * Usage: php list_docs.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Google\Service\Drive;

$folderId = env_required('DRIVE_FOLDER_ID');
$drive = new Drive(make_client());

section("Question 1: docs in folder {$folderId}");

$query = sprintf(
    "'%s' in parents and mimeType = 'application/vnd.google-apps.document' and trashed = false",
    $folderId
);

$pageToken = null;
$count = 0;
do {
    $resp = $drive->files->listFiles([
        'q' => $query,
        'fields' => 'nextPageToken, files(id, name, modifiedTime)',
        'pageSize' => 100,
        // These two let us see docs in Shared Drives too, not just My Drive.
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ]);

    foreach ($resp->getFiles() as $file) {
        $count++;
        printf("%2d. %-45s  %s  (modified %s)\n",
            $count, $file->getName(), $file->getId(), $file->getModifiedTime());
    }
    $pageToken = $resp->getNextPageToken();
} while ($pageToken);

if ($count === 0) {
    echo "No Google Docs found. Check: (a) the folder ID, (b) that you shared the folder\n";
    echo "with the service account email, (c) the docs are Google Docs (not uploaded .docx).\n";
} else {
    echo "\nFound {$count} doc(s). Copy an id above and run:\n  php walk_doc.php <DOC_ID>\n";
}
