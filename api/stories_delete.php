<?php
/** POST /api/stories_delete.php  Body: { id } */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\StoryTracker;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
if ($id === 0) {
    api_error('id is required');
}

StoryTracker::delete($id);
AuditLog::record('story_deleted', 'story', $id, []);

api_success(['id' => $id]);
