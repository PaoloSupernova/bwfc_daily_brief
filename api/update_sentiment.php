<?php
/**
 * POST /api/update_sentiment.php
 *
 * Body: { article_id: int, sentiment: 'positive'|'neutral'|'negative' }
 *
 * Updates the sentiment tag on a single article. Intentionally allowed on
 * sent/locked briefs — sentiment correction is a metadata edit, not a
 * content edit.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\AuditLog;

$input     = api_input();
$articleId = (int)($input['article_id'] ?? 0);
$sentiment = trim((string)($input['sentiment'] ?? ''));

if ($articleId <= 0) {
    api_error('article_id is required');
}
if (!in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
    api_error('Invalid sentiment value');
}

$row = Database::selectOne(
    'SELECT id, sentiment FROM brief_articles WHERE id = :id',
    ['id' => $articleId]
);
if ($row === null) {
    api_error('Article not found', 404);
}

$previous = $row['sentiment'];

Database::execute(
    'UPDATE brief_articles SET sentiment = :s WHERE id = :id',
    ['s' => $sentiment, 'id' => $articleId]
);

AuditLog::record('sentiment_updated', 'article', $articleId, [
    'from' => $previous,
    'to'   => $sentiment,
]);

api_success(['article_id' => $articleId, 'sentiment' => $sentiment]);
