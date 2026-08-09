<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Best-effort job-run logging to the job_runs table. Like AuditLog, a logging
 * failure (e.g. a read-only / crashed job_runs table) must never break the
 * actual job — discovery polling, insights generation, etc.
 */
final class JobLog
{
    /** Record the start of a job. Returns the row id, or 0 if logging failed. */
    public static function start(string $jobName): int
    {
        try {
            return Database::insertRow('job_runs', [
                'job_name'   => $jobName,
                'started_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('JobLog::start failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Record the outcome of a job. No-op if the start wasn't logged. */
    public static function finish(int $jobId, bool $success, string $output = '', int $itemsProcessed = 0): void
    {
        if ($jobId <= 0) {
            return;
        }
        try {
            Database::updateRow('job_runs', [
                'completed_at'    => date('Y-m-d H:i:s'),
                'success'         => $success ? 1 : 0,
                'items_processed' => $itemsProcessed,
                'output'          => $output,
            ], ['id' => $jobId]);
        } catch (\Throwable $e) {
            error_log('JobLog::finish failed: ' . $e->getMessage());
        }
    }
}
