<?php
/**
 * POST /api/fetch_article.php
 * Body: { url: string, brief_id?: int }
 * Returns: { article: {headline, content, byline_raw, people_detected, outlet,
 *                       domain, paywalled, success, error},
 *            prior_coverage: {brief_id, brief_date, headline, status, url}|null }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\ArticleFetcher;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\PeopleRepository;

$input = api_input();
$url = trim((string)($input['url'] ?? ''));
$briefId = isset($input['brief_id']) ? (int)$input['brief_id'] : 0;

if ($url === '') {
    api_error('URL is required');
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    api_error('Invalid URL format');
}

// Warn if this exact article was already covered in an earlier brief. The
// current brief is excluded so re-pasting within this session isn't flagged.
$priorCoverage = BriefRepository::findPriorCoverage($url, $briefId);

$fetcher = new ArticleFetcher();
$result = $fetcher->fetch($url);

// Detect known BWFC people named in the article, to prefill the editor field.
$detectText = trim((string)($result['headline'] ?? '') . ' ' . (string)($result['content'] ?? ''));
$result['people_detected'] = $detectText !== '' ? PeopleRepository::detectNames($detectText) : '';

api_success([
    'article'        => $result,
    'prior_coverage' => $priorCoverage,
]);
