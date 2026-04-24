<?php
/**
 * POST /api/render_html.php
 * Body: { brief_id }
 * Returns: { html, subject_line }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\BriefRenderer;

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
$html = BriefRenderer::renderHtml($brief, $articles);
$subject = BriefRenderer::formatSubjectLine((string)$brief['brief_date']);

api_success([
    'html' => $html,
    'subject_line' => $subject,
    'article_count' => count($articles),
]);
