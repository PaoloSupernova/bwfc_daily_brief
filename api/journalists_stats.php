<?php
/**
 * GET/POST /api/journalists_stats.php
 * Body/query: { days?: int }   (7 | 30 | 90 | 365 | 0 = all-time; default 90)
 *
 * Returns the journalist leaderboard (article counts + sentiment split) over
 * the window, counting only articles in sections flagged counts_for_journalists,
 * plus the Unassigned bucket and the current per-section flags.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\JournalistRepository;

$input = api_input();
$days = (int)($input['days'] ?? ($_GET['days'] ?? 90));

$toDate = date('Y-m-d');
$fromDate = ($days > 0) ? date('Y-m-d', strtotime("-{$days} days")) : '2000-01-01';

$leaderboard = JournalistRepository::leaderboard($fromDate, $toDate);

// Cast the aggregate columns to ints for clean JSON.
$rows = array_map(static function (array $r): array {
    $pos = (int)$r['positive'];
    $neu = (int)$r['neutral'];
    $neg = (int)$r['negative'];
    $count = (int)$r['article_count'];
    $tagged = $pos + $neu + $neg;
    return [
        'id'            => (int)$r['id'],
        'name'          => (string)$r['name'],
        'outlet'        => (string)($r['last_outlet'] ?? ''),
        'article_count' => $count,
        'positive'      => $pos,
        'neutral'       => $neu,
        'negative'      => $neg,
        'untagged'      => (int)$r['untagged'],
        'pct_positive'  => $tagged > 0 ? (int)round(($pos / $tagged) * 100) : null,
    ];
}, $leaderboard);

$unassigned = JournalistRepository::unassignedTally($fromDate, $toDate);

api_success([
    'days'        => $days,
    'from'        => $fromDate,
    'to'          => $toDate,
    'journalists' => $rows,
    'unassigned'  => $unassigned,
    'sections'    => JournalistRepository::sectionFlags(),
]);
