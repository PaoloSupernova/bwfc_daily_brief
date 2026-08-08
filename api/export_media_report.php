<?php
/**
 * GET /api/export_media_report.php?days=<int>
 * Returns: PDF download of the Media Intelligence report for the window.
 *
 * Streams a binary file, so it breaks the JSON convention.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\MediaReportExporter;
use BWFC\DailyBrief\AuditLog;

Auth::requireLogin();

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) {
    $days = 30;
}

try {
    $pdf = MediaReportExporter::generate($days);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Report generation failed: ' . $e->getMessage();
    exit;
}

AuditLog::record('media_report_exported', 'report', null, ['days' => $days]);

$filename = MediaReportExporter::filename($days);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf;
