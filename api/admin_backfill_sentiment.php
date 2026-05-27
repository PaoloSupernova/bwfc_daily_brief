<?php
/**
 * POST /api/admin_backfill_sentiment.php
 *
 * Admin-only. Classifies sentiment for all existing brief_articles where
 * sentiment IS NULL and parent_article_id IS NULL (standalone articles only).
 *
 * Returns: { processed, skipped, total, errors }
 */

declare(strict_types=1);

// Allow long execution for large archives — this calls Claude once per article
set_time_limit(0);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\Summariser;

// Require admin role
Auth::requireRole('admin');

$articles = Database::select(
    "SELECT id, headline, summary
     FROM brief_articles
     WHERE sentiment IS NULL
       AND parent_article_id IS NULL
       AND headline != ''
       AND summary != ''
     ORDER BY id ASC"
);

$total     = count($articles);
$processed = 0;
$skipped   = 0;
$errors    = [];

if ($total === 0) {
    api_success([
        'processed' => 0,
        'skipped'   => 0,
        'total'     => 0,
        'errors'    => [],
        'message'   => 'All articles already have sentiment tags.',
    ]);
}

$summariser = new Summariser();

foreach ($articles as $article) {
    $id       = (int)$article['id'];
    $headline = trim((string)$article['headline']);
    $summary  = trim((string)$article['summary']);

    if ($headline === '' || $summary === '') {
        $skipped++;
        continue;
    }

    try {
        $sentiment = $summariser->classifySentiment($headline, $summary);

        Database::execute(
            "UPDATE brief_articles SET sentiment = :s WHERE id = :id",
            ['s' => $sentiment, 'id' => $id]
        );

        $processed++;
    } catch (\Throwable $e) {
        $errors[] = "Article #{$id}: " . $e->getMessage();
        $skipped++;
    }
}

api_success([
    'processed' => $processed,
    'skipped'   => $skipped,
    'total'     => $total,
    'errors'    => $errors,
    'message'   => "Tagged {$processed} of {$total} articles.",
]);
