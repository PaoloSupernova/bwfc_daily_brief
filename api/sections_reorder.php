<?php
/**
 * POST /api/sections_reorder.php
 * Body: { ids: [id1, id2, id3, ...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$ids = $input['ids'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
    api_error('ids array is required');
}

BriefRepository::reorderSections(array_map('intval', $ids));

AuditLog::record('sections_reordered', null, null, ['count' => count($ids)]);

api_success();
