<?php
/**
 * POST /api/update_article.php
 * Body: { article_id, headline?, outlet_name?, summary? }
 *
 * Updates any combination of headline, outlet, and summary on an existing
 * article. Used by the review screen for inline edits.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$articleId = (int)($input['article_id'] ?? 0);

if ($articleId === 0) {
    api_error('article_id is required');
}

$fields = [];
if (isset($input['headline'])) {
    $fields['headline'] = trim((string)$input['headline']);
}
if (isset($input['outlet_name'])) {
    $fields['outlet_name'] = trim((string)$input['outlet_name']);
}
if (isset($input['summary'])) {
    $fields['summary'] = trim((string)$input['summary']);
}

if (count($fields) === 0) {
    api_error('No fields to update');
}

BriefRepository::updateArticleFields($articleId, $fields);

$violations = isset($fields['summary']) ? StyleGuard::check($fields['summary']) : ['clean' => true, 'violations' => []];

AuditLog::record('article_field_edit', 'article', $articleId, [
    'fields_changed' => array_keys($fields),
]);

$article = BriefRepository::getArticle($articleId);

api_success([
    'article' => $article,
    'style_check' => $violations,
]);
