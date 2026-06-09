<?php
/**
 * GET /api/sources_list.php
 * Returns all discovery sources with last poll metadata.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$rows = Database::select(
    "SELECT s.*,
            (SELECT COUNT(*) FROM discovery_candidates WHERE source_id = s.id AND status = 'new') AS new_candidates,
            (SELECT COUNT(*) FROM discovery_candidates WHERE source_id = s.id) AS total_candidates
     FROM discovery_sources s
     WHERE s.deleted_at IS NULL
     ORDER BY s.is_active DESC, s.name"
);

$sources = array_map(fn($r) => [
    'id' => (int)$r['id'],
    'name' => (string)$r['name'],
    'url' => (string)$r['url'],
    'source_type' => (string)$r['source_type'],
    'is_local' => (bool)$r['is_local'],
    'is_active' => (bool)$r['is_active'],
    'poll_frequency_minutes' => (int)$r['poll_frequency_minutes'],
    'last_polled_at' => $r['last_polled_at'],
    'last_success_at' => $r['last_success_at'],
    'last_error' => $r['last_error'],
    'new_candidates' => (int)$r['new_candidates'],
    'total_candidates' => (int)$r['total_candidates'],
], $rows);

api_success(['sources' => $sources]);
