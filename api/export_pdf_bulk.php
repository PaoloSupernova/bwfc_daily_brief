<?php
/**
 * POST /api/export_pdf_bulk.php
 *
 * Body: { brief_ids: [int, ...] }   (max 30)
 *
 * Generates a PDF for each requested brief and streams them as a ZIP file.
 * Falls back to a single PDF if only one brief is requested (no ZIP needed).
 */

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\PdfExporter;
use BWFC\DailyBrief\AuditLog;

Auth::requireLogin();

// Parse body
$raw      = file_get_contents('php://input') ?: '{}';
$input    = json_decode($raw, true) ?? [];
$briefIds = array_values(array_filter(array_map('intval', (array)($input['brief_ids'] ?? []))));

if (count($briefIds) === 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No brief IDs provided']);
    exit;
}

const MAX_BULK = 30;
if (count($briefIds) > MAX_BULK) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Maximum ' . MAX_BULK . ' PDFs per download']);
    exit;
}

if (!class_exists('\\ZipArchive')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'PHP ZipArchive extension is not enabled. Enable it in php.ini and restart Apache.']);
    exit;
}

$zipPath = sys_get_temp_dir() . '/bwfc-pdfs-' . date('Y-m-d-His') . '-' . uniqid('', true) . '.zip';
$zip = new \ZipArchive();
if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Could not create ZIP file']);
    exit;
}

$generated = 0;
$errors    = [];

foreach ($briefIds as $briefId) {
    $brief = BriefRepository::findBrief($briefId);
    if ($brief === null) {
        $errors[] = "Brief #{$briefId} not found";
        continue;
    }

    try {
        $articles = BriefRepository::articlesForBrief($briefId);
        $pdf      = PdfExporter::generate($brief, $articles);
        $filename = PdfExporter::filename($brief);
        $zip->addFromString($filename, $pdf);
        AuditLog::record('pdf_exported', 'brief', $briefId, ['source' => 'bulk', 'article_count' => count($articles)]);
        $generated++;
    } catch (\Throwable $e) {
        $errors[] = "Brief #{$briefId}: " . $e->getMessage();
    }
}

$zip->close();

if ($generated === 0) {
    @unlink($zipPath);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No PDFs could be generated. ' . implode('; ', $errors)]);
    exit;
}

$zipSize = filesize($zipPath);
$zipFilename = 'bwfc-briefs-' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . $zipSize);
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($zipPath);
@unlink($zipPath);
