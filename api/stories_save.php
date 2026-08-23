<?php
/** POST /api/stories_save.php  Body: { id?, title, keywords, active? } */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\StoryTracker;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
$title = trim((string)($input['title'] ?? ''));
$keywords = trim((string)($input['keywords'] ?? ''));
$active = array_key_exists('active', $input) ? !empty($input['active']) : true;

if ($title === '' || $keywords === '') {
    api_error('Title and keywords are required');
}

if ($id > 0) {
    StoryTracker::update($id, $title, $keywords, $active);
    AuditLog::record('story_updated', 'story', $id, ['title' => $title]);
} else {
    $id = StoryTracker::create($title, $keywords);
    AuditLog::record('story_created', 'story', $id, ['title' => $title]);
}

api_success(['id' => $id]);
