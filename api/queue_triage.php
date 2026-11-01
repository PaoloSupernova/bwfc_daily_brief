<?php
/**
 * POST /api/queue_triage.php  Body: { hours? }
 * Auto-triages the current 'new' queue candidates (priority, section, cluster).
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\QueueTriage;
use BWFC\DailyBrief\JobLog;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$hours = max(1, min(168, (int)($input['hours'] ?? 48)));

$jobId = JobLog::start('queue_triage');
try {
    $result = QueueTriage::run($hours);
} catch (\Throwable $e) {
    JobLog::finish($jobId, false, 'FAILED: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}
JobLog::finish($jobId, true, "Triaged {$result['triaged']} candidates, {$result['clusters']} story groups", $result['triaged']);
AuditLog::record('queue_triaged', 'queue', null, $result);

api_success($result);
