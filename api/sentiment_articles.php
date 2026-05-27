<?php
/**
 * POST /api/sentiment_articles.php
 *
 * Body: { sentiment: 'positive'|'neutral'|'negative', window?: 7|30|90 }
 *
 * Returns standalone articles with the given sentiment tag within the
 * specified day window, ordered newest first.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$input     = api_input();
$sentiment = trim((string)($input['sentiment'] ?? ''));
$window    = (int)($input['window'] ?? 30);

if (!in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
    api_error('Invalid sentiment value');
}
if (!in_array($window, [7, 30, 90], true)) {
    $window = 30;
}

$rangeStart = date('Y-m-d', strtotime("-{$window} days"));

$articles = Database::select(
    "SELECT
        a.id,
        a.headline,
        a.outlet_name,
        a.url,
        a.summary,
        s.name  AS section_name,
        s.slug  AS section_slug,
        b.id    AS brief_id,
        b.brief_date,
        b.status AS brief_status
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     JOIN sections s ON s.id = a.section_id
     WHERE b.deleted_at IS NULL
       AND b.brief_date >= :start
       AND a.sentiment = :sentiment
       AND a.parent_article_id IS NULL
     ORDER BY b.brief_date DESC, s.display_order ASC, a.display_order ASC",
    ['start' => $rangeStart, 'sentiment' => $sentiment]
);

$articles = array_map(fn($r) => [
    'id'           => (int)$r['id'],
    'headline'     => (string)$r['headline'],
    'outlet_name'  => (string)$r['outlet_name'],
    'url'          => (string)$r['url'],
    'summary'      => (string)$r['summary'],
    'section_name' => (string)$r['section_name'],
    'section_slug' => (string)$r['section_slug'],
    'brief_id'     => (int)$r['brief_id'],
    'brief_date'   => (string)$r['brief_date'],
    'brief_status' => (string)$r['brief_status'],
], $articles);

api_success([
    'sentiment' => $sentiment,
    'window'    => $window,
    'total'     => count($articles),
    'articles'  => $articles,
]);
