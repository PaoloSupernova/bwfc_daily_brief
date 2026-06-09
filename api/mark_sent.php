<?php
/**
 * POST /api/mark_sent.php
 * Body: { brief_id }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$briefId = (int)($input['brief_id'] ?? 0);

if ($briefId === 0) {
    api_error('brief_id is required');
}

$brief = BriefRepository::findBrief($briefId);
if ($brief === null) {
    api_error('Brief not found', 404);
}

$articles = BriefRepository::articlesForBrief($briefId);
if (count($articles) === 0) {
    api_error('Cannot send an empty brief');
}

BriefRepository::markSent($briefId, AuditLog::currentUserId());

AuditLog::record('brief_sent', 'brief', $briefId, [
    'article_count' => count($articles),
]);

api_success(['brief_id' => $briefId]);
