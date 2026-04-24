<?php
/**
 * POST /api/reorder_articles.php
 * Body: { brief_id, article_ids: [id1, id2, ...], section_id?: int }
 * If section_id provided, all articles move to that section in given order.
 * Otherwise, articles are reordered within their existing sections.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$briefId = (int)($input['brief_id'] ?? 0);
$articleIds = $input['article_ids'] ?? [];
$sectionId = isset($input['section_id']) ? (int)$input['section_id'] : null;

if ($briefId === 0) {
    api_error('brief_id is required');
}

if (!is_array($articleIds) || count($articleIds) === 0) {
    api_error('article_ids array is required');
}

BriefRepository::reorderArticles($briefId, array_map('intval', $articleIds), $sectionId);

AuditLog::record('articles_reordered', 'brief', $briefId, [
    'count' => count($articleIds),
    'target_section' => $sectionId,
]);

api_success();
