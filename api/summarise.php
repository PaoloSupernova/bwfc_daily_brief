<?php
/**
 * POST /api/summarise.php
 * Body: { headline, outlet, content }
 * Returns: { summary, suggested_section, violations }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$headline = trim((string)($input['headline'] ?? ''));
$outlet = trim((string)($input['outlet'] ?? 'Unknown'));
$content = trim((string)($input['content'] ?? ''));

if ($headline === '' || $content === '') {
    api_error('Headline and content are required');
}

$summariser = new Summariser();

$summary = $summariser->summariseArticle($headline, $outlet, $content);
$section = $summariser->suggestSection($headline, $outlet, $content);

$violations = StyleGuard::check($summary);

AuditLog::record('article_summary_generated', 'article', null, [
    'outlet' => $outlet,
    'headline' => $headline,
    'suggested_section' => $section,
    'style_violations' => count($violations['violations']),
]);

api_success([
    'summary' => $summary,
    'suggested_section' => $section,
    'style_check' => $violations,
]);
