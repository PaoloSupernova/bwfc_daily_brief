<?php
/**
 * BWFC Daily Brief - People Backfill (one-off, run manually)
 *
 * Scans historical articles for known squad/staff/exec mentions and links them,
 * so the People dashboard reflects your archive. Unlike the byline backfill this
 * does NOT re-fetch the web — it scans the article text already stored in the
 * database (headline + summary + article_content), so it's fast and offline.
 *
 * Only touches articles that currently have no people linked, so it's safe to
 * re-run (e.g. after adding new squad members).
 *
 *   php bin/backfill_people.php                 # all history
 *   php bin/backfill_people.php --days=365       # only the last N days
 *   php bin/backfill_people.php --limit=500       # cap how many are processed
 *   php bin/backfill_people.php --relink          # re-scan ALL articles, even
 *                                                   ones already linked (use after
 *                                                   a big squad-list change)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\PeopleRepository;

$opts = getopt('', ['days::', 'limit::', 'relink']);
$days   = isset($opts['days']) ? max(1, (int)$opts['days']) : 0;
$limit  = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$relink = isset($opts['relink']);

echo "BWFC people backfill\n";
echo str_repeat('-', 40) . "\n";
echo 'History: ' . ($days > 0 ? "last {$days} days" : 'all time') . "\n";
echo 'Mode:    ' . ($relink ? 're-scan ALL articles' : 'only articles with no people yet') . "\n";
echo 'Limit:   ' . ($limit > 0 ? $limit : 'none') . "\n\n";

$where = [];
$params = [];
if (!$relink) {
    $where[] = 'ap.article_id IS NULL';
}
if ($days > 0) {
    $where[] = 'b.brief_date >= :from';
    $params['from'] = date('Y-m-d', strtotime("-{$days} days"));
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT a.id, a.headline, a.summary, a.article_content
        FROM brief_articles a
        JOIN briefs b ON b.id = a.brief_id
        LEFT JOIN article_people ap ON ap.article_id = a.id
        {$whereSql}
        GROUP BY a.id, a.headline, a.summary, a.article_content
        ORDER BY b.brief_date DESC, a.id DESC";
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$rows = Database::select($sql, $params);
$total = count($rows);

if ($total === 0) {
    echo "Nothing to process.\n";
    exit(0);
}

echo "Scanning {$total} article(s)...\n\n";

$withPeople = 0;
$withNone = 0;
$i = 0;

foreach ($rows as $row) {
    $i++;
    $articleId = (int)$row['id'];
    $text = trim(
        (string)$row['headline'] . ' ' .
        (string)($row['summary'] ?? '') . ' ' .
        (string)($row['article_content'] ?? '')
    );

    $names = PeopleRepository::autoDetectForArticle($articleId, $text);

    if (count($names) > 0) {
        $withPeople++;
        echo "[{$i}/{$total}] #{$articleId}  ->  " . implode(', ', $names) . "\n";
    } else {
        $withNone++;
    }
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Done.\n";
echo "  Articles with people: {$withPeople}\n";
echo "  No known person:      {$withNone}\n";
