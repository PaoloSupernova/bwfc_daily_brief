<?php
/**
 * GET/POST /api/queue_list.php
 * Body: { hours?: int (default 48), status_filter?: 'all' | 'new' | 'ingested' | 'rejected' | 'duplicate' }
 *
 * Returns candidates from the last N hours, grouped by date (today/yesterday/older),
 * with source metadata and counts.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$input = api_input();
$hours = max(1, min(168, (int)($input['hours'] ?? 48)));
$statusFilter = trim((string)($input['status_filter'] ?? 'all'));

$where = ["c.discovered_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)"];
$params = ['hours' => $hours];

if (in_array($statusFilter, ['new', 'ingested', 'rejected', 'duplicate'], true)) {
    $where[] = 'c.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$rows = Database::select(
    "SELECT
        c.id,
        c.url,
        c.headline,
        c.description,
        c.outlet_name,
        c.published_at,
        c.discovered_at,
        c.status,
        c.ingested_article_id,
        c.rejected_reason,
        s.id AS source_id,
        s.name AS source_name,
        s.is_local AS source_is_local
     FROM discovery_candidates c
     JOIN discovery_sources s ON s.id = c.source_id
     WHERE {$whereSql}
     ORDER BY c.discovered_at DESC, c.id DESC
     LIMIT 500",
    $params
);

// Group by date bucket
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$buckets = [
    'today' => ['label' => 'Today', 'items' => []],
    'yesterday' => ['label' => 'Yesterday', 'items' => []],
    'older' => ['label' => 'Earlier', 'items' => []],
];

foreach ($rows as $row) {
    $discoveredDate = substr((string)$row['discovered_at'], 0, 10);
    $bucket = match (true) {
        $discoveredDate === $today => 'today',
        $discoveredDate === $yesterday => 'yesterday',
        default => 'older',
    };

    $buckets[$bucket]['items'][] = [
        'id' => (int)$row['id'],
        'url' => (string)$row['url'],
        'headline' => (string)$row['headline'],
        'description' => $row['description'] !== null ? (string)$row['description'] : null,
        'outlet_name' => (string)$row['outlet_name'],
        'published_at' => $row['published_at'],
        'discovered_at' => $row['discovered_at'],
        'status' => (string)$row['status'],
        'ingested_article_id' => $row['ingested_article_id'] !== null ? (int)$row['ingested_article_id'] : null,
        'rejected_reason' => $row['rejected_reason'],
        'source_name' => (string)$row['source_name'],
        'source_is_local' => (bool)$row['source_is_local'],
    ];
}

// Drop empty buckets so the UI doesn't render empty headers
$groups = [];
foreach ($buckets as $key => $bucket) {
    if (count($bucket['items']) > 0) {
        $groups[] = [
            'key' => $key,
            'label' => $bucket['label'],
            'count' => count($bucket['items']),
            'items' => $bucket['items'],
        ];
    }
}

// Status counts for the filter chips
$counts = Database::select(
    "SELECT status, COUNT(*) AS n
     FROM discovery_candidates
     WHERE discovered_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
     GROUP BY status",
    ['hours' => $hours]
);
$statusCounts = ['new' => 0, 'ingested' => 0, 'rejected' => 0, 'duplicate' => 0];
foreach ($counts as $c) {
    $statusCounts[(string)$c['status']] = (int)$c['n'];
}

api_success([
    'hours' => $hours,
    'groups' => $groups,
    'total' => count($rows),
    'status_counts' => $statusCounts,
]);
