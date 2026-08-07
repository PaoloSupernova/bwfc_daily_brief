<?php
/**
 * BWFC Daily Brief - Byline Backfill (one-off, run manually)
 *
 * Re-fetches historical articles that have no journalist attribution yet,
 * extracts the byline, and links journalists. Safe to run repeatedly: it only
 * touches articles that currently have no linked journalist, and re-running
 * simply retries the ones that are still unattributed.
 *
 * By default it processes ONLY articles in sections flagged
 * counts_for_journalists (the ones the dashboard shows). Use --all to process
 * every section.
 *
 *   php bin/backfill_bylines.php                 # relevant sections, all history
 *   php bin/backfill_bylines.php --all           # every section
 *   php bin/backfill_bylines.php --limit=50       # cap how many are processed
 *   php bin/backfill_bylines.php --days=180       # only briefs in the last N days
 *   php bin/backfill_bylines.php --sleep=1500     # ms pause between fetches (default 1200)
 *
 * Google News redirect URLs and paywalled/blocked pages will often yield no
 * byline; those stay Unassigned and can be set by hand on the review page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\ArticleFetcher;
use BWFC\DailyBrief\JournalistRepository;

// --- Parse CLI flags -------------------------------------------------
$opts = getopt('', ['all', 'limit::', 'days::', 'sleep::']);
$onlyRelevant = !isset($opts['all']);
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;   // 0 = no cap
$days  = isset($opts['days']) ? max(1, (int)$opts['days']) : 0;      // 0 = all history
$sleepMs = isset($opts['sleep']) ? max(0, (int)$opts['sleep']) : 1200;

echo "BWFC byline backfill\n";
echo str_repeat('-', 40) . "\n";
echo 'Scope:    ' . ($onlyRelevant ? 'flagged sections only' : 'ALL sections') . "\n";
echo 'History:  ' . ($days > 0 ? "last {$days} days" : 'all time') . "\n";
echo 'Limit:    ' . ($limit > 0 ? $limit : 'none') . "\n";
echo 'Throttle: ' . $sleepMs . "ms between fetches\n\n";

// --- Select candidate articles --------------------------------------
$where = ['a.url <> ""', 'aj.article_id IS NULL'];
$params = [];

if ($onlyRelevant) {
    $where[] = 's.counts_for_journalists = 1';
}
if ($days > 0) {
    $where[] = 'b.brief_date >= :from';
    $params['from'] = date('Y-m-d', strtotime("-{$days} days"));
}

$sql = 'SELECT a.id, a.url, a.outlet_name
        FROM brief_articles a
        JOIN sections s ON s.id = a.section_id
        JOIN briefs b ON b.id = a.brief_id
        LEFT JOIN article_journalists aj ON aj.article_id = a.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.brief_date DESC, a.id DESC';
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$rows = Database::select($sql, $params);
$total = count($rows);

if ($total === 0) {
    echo "Nothing to backfill — every in-scope article already has a byline (or none are eligible).\n";
    exit(0);
}

echo "Processing {$total} article(s)...\n\n";

$fetcher = new ArticleFetcher();
$attributed = 0;
$unattributed = 0;
$failed = 0;
$i = 0;

foreach ($rows as $row) {
    $i++;
    $articleId = (int)$row['id'];
    $url = (string)$row['url'];
    $outlet = (string)$row['outlet_name'];

    $label = "[{$i}/{$total}] #{$articleId} " . parse_url($url, PHP_URL_HOST);

    try {
        $result = $fetcher->fetch($url);
        $byline = (string)($result['byline_raw'] ?? '');
        $names = JournalistRepository::syncArticleByline($articleId, $byline, $outlet);

        if (count($names) > 0) {
            $attributed++;
            echo $label . '  ->  ' . implode(', ', $names) . "\n";
        } else {
            $unattributed++;
            echo $label . "  ->  (no byline found)\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        echo $label . '  ->  ERROR: ' . $e->getMessage() . "\n";
    }

    if ($sleepMs > 0 && $i < $total) {
        usleep($sleepMs * 1000);
    }
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Done.\n";
echo "  Attributed:   {$attributed}\n";
echo "  No byline:     {$unattributed}\n";
echo "  Fetch errors:  {$failed}\n";
echo "\nUnattributed articles remain 'Unassigned' and can be set by hand on the review page.\n";
