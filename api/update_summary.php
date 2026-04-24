<?php
/**
 * POST /api/update_summary.php
 * Manual edit of an existing article's summary.
 * Body: { article_id, summary }
 * Returns: { article, violations }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$articleId = (int)($input['article_id'] ?? 0);
$summary = trim((string)($input['summary'] ?? ''));

if ($articleId === 0 || $summary === '') {
    api_error('article_id and summary are required');
}

BriefRepository::updateArticleSummary($articleId, $summary, true);
$violations = StyleGuard::check($summary);

AuditLog::record('article_edited', 'article', $articleId, [
    'length' => strlen($summary),
]);

$article = BriefRepository::getArticle($articleId);

api_success([
    'article' => $article,
    'style_check' => $violations,
]);
