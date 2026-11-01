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

// discovered_at: respects the selected window
// published_at cap: exclude articles older than 7 days to prevent first-poll backfill flooding
$where = [
    "c.discovered_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)",
    "(c.published_at IS NULL OR c.published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))",
];
$params = ['hours' => $hours];

if (in_array($statusFilter, ['new', 'ingested', 'rejected', 'duplicate'], true)) {
    $where[] = 'c.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

// Triage columns are added by migration_014. Degrade gracefully if that
// migration hasn't been run yet, so the core queue never breaks.
$hasTriage = false;
try {
    $hasTriage = Database::selectOne("SHOW COLUMNS FROM discovery_candidates LIKE 'triage_priority'") !== null;
} catch (\Throwable $e) {
    $hasTriage = false;
}
$triageSelect = $hasTriage
    ? 'c.triage_priority, c.triage_section, c.triage_cluster,'
    : 'NULL AS triage_priority, NULL AS triage_section, NULL AS triage_cluster,';

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
        {$triageSelect}
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
        'triage_priority' => $row['triage_priority'] !== null ? (string)$row['triage_priority'] : null,
        'triage_section' => $row['triage_section'] !== null ? (string)$row['triage_section'] : null,
        'triage_cluster' => $row['triage_cluster'] !== null ? (int)$row['triage_cluster'] : null,
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
       AND (published_at IS NULL OR published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
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
