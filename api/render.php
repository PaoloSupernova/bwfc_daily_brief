<?php
/**
 * POST /api/render.php
 * Body: { brief_id, format: 'html' | 'text' | 'text_with_links' }
 * Returns: { content, subject_line }
 *
 * Unified renderer endpoint replacing the older render_html.php,
 * which is kept for backwards compatibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\BriefRenderer;

$input = api_input();
$briefId = (int)($input['brief_id'] ?? 0);
$format = (string)($input['format'] ?? 'html');

if ($briefId === 0) {
    api_error('brief_id is required');
}

$brief = BriefRepository::findBrief($briefId);
if ($brief === null) {
    api_error('Brief not found', 404);
}

$articles = BriefRepository::articlesForBrief($briefId);

$content = match ($format) {
    'html' => BriefRenderer::renderHtml($brief, $articles),
    'text' => BriefRenderer::renderPlainText($brief, $articles),
    'text_with_links' => BriefRenderer::renderPlainTextWithLinks($brief, $articles),
    default => throw new RuntimeException('Unknown format: ' . $format),
};

api_success([
    'content' => $content,
    'subject_line' => BriefRenderer::formatSubjectLine((string)$brief['brief_date']),
    'article_count' => count($articles),
]);
