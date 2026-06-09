<?php
/**
 * POST /api/queue_reject.php
 * Body: { candidate_id, reason? }
 *
 * Marks a candidate as 'rejected' so it disappears from the default view.
 * Reason is optional but helps for later analysis ("not relevant", "duplicate of X").
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$candidateId = (int)($input['candidate_id'] ?? 0);
$reason = trim((string)($input['reason'] ?? ''));

if ($candidateId === 0) {
    api_error('candidate_id is required');
}

$candidate = Database::selectOne(
    "SELECT id, status FROM discovery_candidates WHERE id = :id",
    ['id' => $candidateId]
);
if ($candidate === null) {
    api_error('Candidate not found', 404);
}

Database::updateRow('discovery_candidates', [
    'status' => 'rejected',
    'rejected_reason' => $reason !== '' ? $reason : null,
], ['id' => $candidateId]);

AuditLog::record('queue_reject', 'candidate', $candidateId, ['reason' => $reason]);

api_success(['rejected' => true]);
