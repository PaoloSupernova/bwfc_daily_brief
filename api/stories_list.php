<?php
/** GET /api/stories_list.php — tracked stories with computed stats. */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\StoryTracker;

$out = [];
foreach (StoryTracker::all() as $s) {
    $stats = StoryTracker::stats((string)$s['keywords']);
    $out[] = [
        'id'       => (int)$s['id'],
        'title'    => (string)$s['title'],
        'keywords' => (string)$s['keywords'],
        'is_active' => (int)$s['is_active'],
        'stats'    => $stats,
    ];
}

api_success(['stories' => $out]);
