<?php
/**
 * POST /api/backup_run.php — run a database backup on demand (Admin -> Jobs).
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Backup;
use BWFC\DailyBrief\JobLog;
use BWFC\DailyBrief\AuditLog;

$jobId = JobLog::start('db_backup_manual');
$result = Backup::run();
JobLog::finish($jobId, $result['success'], $result['message'], $result['pruned']);

AuditLog::record('db_backup', 'backup', null, [
    'success' => $result['success'],
    'bytes'   => $result['bytes'],
]);

if (!$result['success']) {
    api_error($result['message'], 500);
}

api_success([
    'message' => $result['message'],
    'file'    => basename((string)$result['file']),
    'bytes'   => $result['bytes'],
    'kept'    => $result['kept'],
]);
