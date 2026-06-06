<?php
/**
 * BWFC Daily Brief - Scheduled Job Runner
 *
 * Run from command line OR Windows Task Scheduler. Both jobs run every time
 * the script is invoked - they're idempotent and skip work that's not due
 * (sources too recently polled, insights already generated for the week).
 *
 *   php bin/run_jobs.php          # runs both jobs, normal cadence
 *   php bin/run_jobs.php --force  # forces both regardless of last-run times
 *   php bin/run_jobs.php discovery     # only the discovery poll
 *   php bin/run_jobs.php insights      # only the weekly insights generation
 *
 * Recommended Windows Task Scheduler setup:
 *   Trigger: Daily at 06:30
 *   Action:  C:\xampp\php\php.exe
 *   Args:    C:\xampp\htdocs\bwfc-daily-brief\bin\run_jobs.php
 *
 * Output goes to STDOUT and to job_runs table for the admin/jobs page.
 */

declare(strict_types=1);

// Bootstrap the app
require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\DiscoveryService;
use BWFC\DailyBrief\WeeklyInsights;

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$jobFilter = null;
foreach ($args as $arg) {
    if (in_array($arg, ['discovery', 'insights'], true)) {
        $jobFilter = $arg;
    }
}

$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] BWFC Daily Brief - Job Runner\n";
echo str_repeat('-', 60) . "\n";

// ------------------------------------------------------------
// JOB 1: Discovery polling
// ------------------------------------------------------------

if ($jobFilter === null || $jobFilter === 'discovery') {
    echo "\n[discovery] Polling sources...\n";
    $jobId = log_job_start('discovery');
    try {
        $result = DiscoveryService::pollAllDue($force);
        $output = sprintf(
            "Polled %d sources (%d skipped). Found %d items, %d new candidates.",
            $result['sources_polled'],
            $result['sources_skipped'],
            $result['items_found'],
            $result['items_new']
        );
        echo "  {$output}\n";
        if (count($result['errors']) > 0) {
            echo "  Errors:\n";
            foreach ($result['errors'] as $err) {
                echo "    - {$err['source']}: {$err['error']}\n";
            }
        }
        log_job_finish($jobId, true, $result['items_new'], $output);
    } catch (Throwable $e) {
        $msg = "FAILED: " . $e->getMessage();
        echo "  {$msg}\n";
        log_job_finish($jobId, false, 0, $msg);
    }
}

// ------------------------------------------------------------
// JOB 2: Weekly insights (Mondays only, unless --force)
// ------------------------------------------------------------

if ($jobFilter === null || $jobFilter === 'insights') {
    $isMonday = (int)date('N') === 1;
    if (!$isMonday && !$force) {
        echo "\n[insights] Skipping (only runs on Mondays; use --force to override)\n";
    } else {
        echo "\n[insights] Generating weekly insights...\n";
        $jobId = log_job_start('weekly_insights');
        try {
            $result = WeeklyInsights::generate(null, 'scheduled');
            $output = "Generated insights for week {$result['week_start']} to {$result['week_end']}";
            echo "  {$output}\n";
            log_job_finish($jobId, true, 1, $output);
        } catch (Throwable $e) {
            $msg = "FAILED: " . $e->getMessage();
            echo "  {$msg}\n";
            log_job_finish($jobId, false, 0, $msg);
        }
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done.\n";

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

function log_job_start(string $jobName): int
{
    return Database::insertRow('job_runs', [
        'job_name' => $jobName,
        'started_at' => date('Y-m-d H:i:s'),
    ]);
}

function log_job_finish(int $jobId, bool $success, int $items, string $output): void
{
    Database::updateRow('job_runs', [
        'completed_at' => date('Y-m-d H:i:s'),
        'success' => $success ? 1 : 0,
        'items_processed' => $items,
        'output' => substr($output, 0, 2000),
    ], ['id' => $jobId]);
}
