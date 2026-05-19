<?php
/**
 * POST /api/delete_article.php
 * Body: { article_id }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$articleId = (int)($input['article_id'] ?? 0);

if ($articleId === 0) {
    api_error('article_id is required');
}

$article = BriefRepository::getArticle($articleId);
if ($article === null) {
    api_error('Article not found', 404);
}

BriefRepository::deleteArticleWithChildren($articleId);

AuditLog::record('article_deleted', 'article', $articleId, [
    'brief_id' => $article['brief_id'],
    'headline' => $article['headline'],
]);

api_success();
