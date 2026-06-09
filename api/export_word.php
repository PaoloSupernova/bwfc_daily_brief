<?php
/**
 * GET /api/export_word.php?brief_id=<int>
 *
 * Streams a single brief as a Word (.docx) file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\WordExporter;
use BWFC\DailyBrief\AuditLog;

Auth::requireLogin();

$briefId = (int)($_GET['brief_id'] ?? 0);
if ($briefId === 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'brief_id is required']);
    exit;
}

$brief = BriefRepository::findBrief($briefId);
if ($brief === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Brief not found']);
    exit;
}

$articles  = BriefRepository::articlesForBrief($briefId);
$docx      = WordExporter::generate($brief, $articles);
$filename  = WordExporter::filename($brief);

AuditLog::record('word_exported', 'brief', $briefId, ['article_count' => count($articles)]);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($docx));
header('Cache-Control: private, max-age=0, must-revalidate');

echo $docx;
