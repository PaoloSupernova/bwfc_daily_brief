<?php
/**
 * POST /api/sections_delete.php
 * Body: { id, confirm?: bool }
 *
 * If articles exist and confirm is not true, returns a warning with count.
 * If confirm is true, soft-deletes the section (articles in past briefs retained).
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
$confirm = !empty($input['confirm']);

if ($id === 0) {
    api_error('id is required');
}

$section = BriefRepository::getSection($id);
if ($section === null) {
    api_error('Section not found', 404);
}

$articleCount = BriefRepository::countArticlesInSection($id);

if ($articleCount > 0 && !$confirm) {
    api_success([
        'needs_confirmation' => true,
        'article_count' => $articleCount,
        'message' => "This section has {$articleCount} article" . ($articleCount === 1 ? '' : 's') . " across past briefs. Deleting hides the section from new briefs, but past briefs will still show it. Confirm to proceed.",
    ]);
}

BriefRepository::deleteSection($id);

AuditLog::record('section_deleted', 'section', $id, [
    'name' => $section['name'],
    'article_count' => $articleCount,
]);

api_success(['deleted' => true]);
