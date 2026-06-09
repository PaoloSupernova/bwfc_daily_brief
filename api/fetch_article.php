<?php
/**
 * POST /api/fetch_article.php
 * Body: { url: string }
 * Returns: { headline, content, outlet, domain, paywalled, success, error }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\ArticleFetcher;

$input = api_input();
$url = trim((string)($input['url'] ?? ''));

if ($url === '') {
    api_error('URL is required');
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    api_error('Invalid URL format');
}

$fetcher = new ArticleFetcher();
$result = $fetcher->fetch($url);

api_success([
    'article' => $result,
]);
