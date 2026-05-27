<?php
/**
 * GET /api/export_download.php?key=<token>
 * Streams the previously generated export ZIP and removes the temp file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;

Auth::requireLogin();

$key = trim((string)($_GET['key'] ?? ''));

if ($key === '' || !isset($_SESSION['export_keys'][$key])) {
    http_response_code(404);
    echo 'Export not found or link has expired. Please generate a new export.';
    exit;
}

$entry = $_SESSION['export_keys'][$key];

if (time() > $entry['expires']) {
    unset($_SESSION['export_keys'][$key]);
    http_response_code(410);
    echo 'This export link has expired. Please generate a new export.';
    exit;
}

$path     = $entry['path'];
$filename = $entry['filename'];

if (!file_exists($path)) {
    http_response_code(404);
    echo 'Export file not found. Please generate a new export.';
    exit;
}

// Consume the key — one download only
unset($_SESSION['export_keys'][$key]);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');

readfile($path);

// Clean up the temp file after serving
@unlink($path);
exit;
