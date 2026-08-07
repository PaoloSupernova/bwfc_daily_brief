<?php
/**
 * GET/POST /api/people_stats.php
 * Body/query: { days?: int, role?: 'player'|'staff'|'exec'|'other' }
 *
 * People-in-the-news leaderboard over a window: mention counts + sentiment
 * split, across all sections. Excludes deleted briefs.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\PeopleRepository;

$input = api_input();
$days = (int)($input['days'] ?? ($_GET['days'] ?? 90));
$role = trim((string)($input['role'] ?? ($_GET['role'] ?? '')));
$role = in_array($role, ['player', 'staff', 'exec', 'other'], true) ? $role : null;

$toDate = date('Y-m-d');
$fromDate = ($days > 0) ? date('Y-m-d', strtotime("-{$days} days")) : '2000-01-01';

$rows = array_map(static function (array $r): array {
    $pos = (int)$r['positive'];
    $neu = (int)$r['neutral'];
    $neg = (int)$r['negative'];
    $tagged = $pos + $neu + $neg;
    return [
        'id'            => (int)$r['id'],
        'name'          => (string)$r['name'],
        'role'          => (string)$r['role'],
        'is_known'      => (int)$r['is_known'],
        'mention_count' => (int)$r['mention_count'],
        'positive'      => $pos,
        'neutral'       => $neu,
        'negative'      => $neg,
        'untagged'      => (int)$r['untagged'],
        'pct_positive'  => $tagged > 0 ? (int)round(($pos / $tagged) * 100) : null,
    ];
}, PeopleRepository::leaderboard($fromDate, $toDate, $role));

api_success([
    'days'   => $days,
    'role'   => $role,
    'from'   => $fromDate,
    'to'     => $toDate,
    'people' => $rows,
]);
