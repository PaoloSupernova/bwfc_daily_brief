<?php
/**
 * POST /api/move_article.php
 * Body: { article_id, section_slug }
 * Moves a single article to a new section.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$articleId = (int)($input['article_id'] ?? 0);
$sectionSlug = trim((string)($input['section_slug'] ?? ''));

if ($articleId === 0 || $sectionSlug === '') {
    api_error('article_id and section_slug are required');
}

$section = BriefRepository::getSectionBySlug($sectionSlug);
if ($section === null) {
    api_error('Section not found: ' . $sectionSlug);
}

BriefRepository::updateArticleFields($articleId, ['section_id' => (int)$section['id']]);

AuditLog::record('article_moved', 'article', $articleId, [
    'to_section' => $sectionSlug,
]);

$article = BriefRepository::getArticle($articleId);

api_success(['article' => $article]);
