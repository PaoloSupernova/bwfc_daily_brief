<?php
/**
 * GET /api/journalists_all.php
 * Every journalist with their all-time article count, for the merge picker.
 * (Ignores the date window and counted-sections flag so duplicates are visible.)
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\JournalistRepository;

$rows = JournalistRepository::allWithCounts();

api_success([
    'journalists' => array_map(static fn($r) => [
        'id'            => (int)$r['id'],
        'name'          => (string)$r['name'],
        'last_outlet'   => $r['last_outlet'] !== null ? (string)$r['last_outlet'] : null,
        'article_count' => (int)$r['article_count'],
    ], $rows),
]);
