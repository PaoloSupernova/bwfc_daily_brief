<?php
/**
 * POST /api/export_data.php
 * Generates the full data export ZIP and stores it in the session for download.
 * Returns: { ok, summary, download_url }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Exporter;
use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\AuditLog;

Auth::requireRole('admin');

$exporter = new Exporter();
$result   = $exporter->generate();

// Store the ZIP path in the session under a one-time key
$key = bin2hex(random_bytes(16));
$_SESSION['export_keys'][$key] = [
    'path'      => $result['path'],
    'filename'  => $result['filename'],
    'expires'   => time() + 3600,   // valid for 1 hour
];

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$root     = preg_replace('#/api/?$#', '', $basePath);

AuditLog::record('data_exported', 'system', null, [
    'zip_size'   => $result['summary']['zip_size'],
    'briefs'     => $result['summary']['briefs_sent'] + $result['summary']['briefs_draft'],
    'articles'   => $result['summary']['articles'],
]);

api_success([
    'summary'      => $result['summary'],
    'mode'         => $result['mode'],
    'zip_available'=> \BWFC\DailyBrief\Exporter::zipAvailable(),
    'download_url' => $root . '/api/export_download.php?key=' . $key,
]);
