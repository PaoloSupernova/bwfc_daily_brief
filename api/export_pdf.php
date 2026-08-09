<?php
/**
 * GET /api/export_pdf.php?brief_id=<id>
 * Returns: PDF file download
 *
 * This endpoint breaks the JSON convention because it streams a binary file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\PdfExporter;
use BWFC\DailyBrief\AuditLog;

Auth::requireLogin();

$briefId = (int)($_GET['brief_id'] ?? 0);
if ($briefId === 0) {
    http_response_code(400);
    echo 'brief_id is required';
    exit;
}

$brief = BriefRepository::findBrief($briefId);
if ($brief === null) {
    http_response_code(404);
    echo 'Brief not found';
    exit;
}

$articles = BriefRepository::articlesForBrief($briefId);

try {
    $pdf = PdfExporter::generate($brief, $articles);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'PDF generation failed: ' . $e->getMessage();
    exit;
}

AuditLog::record('pdf_exported', 'brief', $briefId, ['article_count' => count($articles)]);

$filename = PdfExporter::filename($brief);

// ?preview=1 opens the PDF inline in the browser's viewer instead of downloading.
$disposition = !empty($_GET['preview']) ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdf;
