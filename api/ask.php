<?php
/**
 * POST /api/ask.php
 * Body: { question, history?: [{role, content}] }
 * Returns: { answer, sources: [{brief_id, brief_date, outlet, headline, url}] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\ArchiveAssistant;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$question = trim((string)($input['question'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($question === '') {
    api_error('A question is required');
}

try {
    $result = ArchiveAssistant::answer($question, $history);
} catch (\Throwable $e) {
    api_error($e->getMessage(), 500);
}

AuditLog::record('archive_question', 'ask', null, ['q' => mb_substr($question, 0, 200)]);

api_success($result);
