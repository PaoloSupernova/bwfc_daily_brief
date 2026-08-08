<?php
/**
 * POST /api/summarise.php
 * Body: { headline, outlet, content, brief_id?, skip_duplicate_check? }
 * Returns: { summary, suggested_section, violations }
 *       OR { duplicate: true, parent_id, parent_headline } when a related story detected
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;
use BWFC\DailyBrief\BriefRepository;

$input = api_input();
$headline = trim((string)($input['headline'] ?? ''));
$outlet = trim((string)($input['outlet'] ?? 'Unknown'));
$content = trim((string)($input['content'] ?? ''));
$briefId = (int)($input['brief_id'] ?? 0);
$skipDuplicateCheck = !empty($input['skip_duplicate_check']);

if ($headline === '' || $content === '') {
    api_error('Headline and content are required');
}

$summariser = new Summariser();

// Duplicate detection: if a brief_id is supplied and we're not forcing a full summary,
// check whether this article covers a story already in the brief.
if ($briefId > 0 && !$skipDuplicateCheck) {
    $existing = BriefRepository::standaloneArticlesForBrief($briefId);
    if (count($existing) > 0) {
        $duplicate = $summariser->detectDuplicate($headline, $content, $existing);
        if ($duplicate !== null) {
            AuditLog::record('duplicate_detected', 'article', null, [
                'brief_id' => $briefId,
                'headline' => $headline,
                'parent_id' => $duplicate['article_id'],
            ]);
            api_success([
                'duplicate' => true,
                'parent_id' => $duplicate['article_id'],
                'parent_headline' => $duplicate['headline'],
            ]);
        }
    }
}

$summary   = $summariser->summariseArticle($headline, $outlet, $content);
$section   = $summariser->suggestSection($headline, $outlet, $content);
$sentiment = $summariser->classifySentiment($headline, $summary);
$topic     = $summariser->suggestTopic($headline, $outlet, $content);

$violations = StyleGuard::check($summary);

AuditLog::record('article_summary_generated', 'article', null, [
    'outlet'            => $outlet,
    'headline'          => $headline,
    'suggested_section' => $section,
    'sentiment'         => $sentiment,
    'style_violations'  => count($violations['violations']),
]);

api_success([
    'summary'          => $summary,
    'suggested_section'=> $section,
    'sentiment'        => $sentiment,
    'suggested_topic'  => $topic,
    'style_check'      => $violations,
]);
