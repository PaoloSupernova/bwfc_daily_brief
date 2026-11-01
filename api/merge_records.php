<?php
/**
 * POST /api/merge_records.php
 * Body: { type: 'journalist' | 'person', target_id: int, source_ids: int[] }
 *
 * Collapses duplicate journalist or person records into a single target,
 * moving all article links across. For people, the losers' names/aliases are
 * folded into the target so detection keeps catching the old spellings.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\JournalistRepository;
use BWFC\DailyBrief\PeopleRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$type = trim((string)($input['type'] ?? ''));
$targetId = (int)($input['target_id'] ?? 0);

$sourceIds = $input['source_ids'] ?? [];
if (!is_array($sourceIds)) {
    $sourceIds = [$sourceIds];
}
$sourceIds = array_map('intval', $sourceIds);

if ($targetId <= 0) {
    api_error('A target record to merge into is required.');
}
if ($sourceIds === [] || array_filter($sourceIds, static fn($id) => $id > 0 && $id !== $targetId) === []) {
    api_error('Select at least one other record to merge into the target.');
}

try {
    if ($type === 'journalist') {
        $result = JournalistRepository::merge($targetId, $sourceIds);
    } elseif ($type === 'person') {
        $result = PeopleRepository::merge($targetId, $sourceIds);
    } else {
        api_error('Unknown record type. Expected "journalist" or "person".');
    }
} catch (\Throwable $e) {
    api_error('Merge failed: ' . $e->getMessage(), 500);
}

AuditLog::record('records_merged', $type, $targetId, [
    'source_ids'  => $sourceIds,
    'merged'      => $result['merged'] ?? 0,
    'links_moved' => $result['links_moved'] ?? 0,
]);

api_success(['result' => $result]);
