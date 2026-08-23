<?php
/**
 * POST /api/regenerate.php
 * Regenerate an existing article's summary, or regenerate a pending one.
 * Body: { headline, outlet, content, article_id? }
 * Returns: { summary, violations }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$headline = trim((string)($input['headline'] ?? ''));
$outlet = trim((string)($input['outlet'] ?? 'Unknown'));
$content = trim((string)($input['content'] ?? ''));
$articleId = isset($input['article_id']) ? (int)$input['article_id'] : null;
$ignoreRelevance = !empty($input['ignore_relevance']);

if ($headline === '' || $content === '') {
    api_error('Headline and content are required');
}

$summariser = new Summariser();
$summary = $summariser->summariseArticle($headline, $outlet, $content, $ignoreRelevance);
$violations = StyleGuard::check($summary);

if ($articleId !== null && $articleId > 0) {
    BriefRepository::updateArticleSummary($articleId, $summary, false);
    BriefRepository::incrementRegenerateCount($articleId);
    AuditLog::record('article_regenerated', 'article', $articleId, [
        'outlet' => $outlet,
        'headline' => $headline,
    ]);
} else {
    AuditLog::record('article_summary_regenerated_pending', 'article', null, [
        'outlet' => $outlet,
        'headline' => $headline,
    ]);
}

api_success([
    'summary' => $summary,
    'style_check' => $violations,
]);
