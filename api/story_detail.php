<?php
/**
 * GET/POST /api/story_detail.php  Body/query: { id }
 * Full timeline of matching articles for one tracked story.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\StoryTracker;

$input = api_input();
$id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
if ($id === 0) {
    api_error('id is required');
}

$story = StoryTracker::get($id);
if ($story === null) {
    api_error('Story not found', 404);
}

$rows = StoryTracker::matches((string)$story['keywords'], 200);
$articles = array_map(static fn($r) => [
    'id'         => (int)$r['id'],
    'brief_id'   => (int)$r['brief_id'],
    'brief_date' => (string)$r['brief_date'],
    'outlet'     => (string)$r['outlet_name'],
    'headline'   => (string)$r['headline'],
    'url'        => (string)$r['url'],
    'sentiment'  => (string)($r['sentiment'] ?? ''),
    'topic'      => (string)($r['topic'] ?? ''),
], $rows);

api_success([
    'story'    => ['id' => (int)$story['id'], 'title' => (string)$story['title'], 'keywords' => (string)$story['keywords']],
    'stats'    => StoryTracker::stats((string)$story['keywords']),
    'articles' => $articles,
]);
