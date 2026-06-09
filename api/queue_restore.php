<?php
/**
 * POST /api/queue_restore.php
 * Body: { candidate_id }
 *
 * Restores a rejected/duplicate candidate back to 'new' so it appears in the queue again.
 * Useful when you reject something by mistake or want to revisit a duplicate.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$candidateId = (int)($input['candidate_id'] ?? 0);
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

if ((string)$candidate['status'] === 'ingested') {
    api_error('Cannot restore an ingested candidate. Remove the article from its brief first.', 409);
}

Database::updateRow('discovery_candidates', [
    'status' => 'new',
    'rejected_reason' => null,
], ['id' => $candidateId]);

AuditLog::record('queue_restore', 'candidate', $candidateId, []);

api_success(['restored' => true]);
