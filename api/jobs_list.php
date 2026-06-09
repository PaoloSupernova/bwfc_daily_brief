<?php
/**
 * GET /api/jobs_list.php
 * Returns recent job_runs with summary plus the latest run per job.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

// Latest runs (last 30)
$recent = Database::select(
    "SELECT id, job_name, started_at, completed_at, success, items_processed, output
     FROM job_runs
     ORDER BY started_at DESC LIMIT 30"
);

// Per-job latest
$latestByJob = Database::select(
    "SELECT j.job_name, j.started_at, j.completed_at, j.success, j.items_processed, j.output
     FROM job_runs j
     INNER JOIN (
         SELECT job_name, MAX(started_at) AS max_started
         FROM job_runs GROUP BY job_name
     ) m ON m.job_name = j.job_name AND m.max_started = j.started_at"
);

api_success([
    'recent' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'job_name' => (string)$r['job_name'],
        'started_at' => $r['started_at'],
        'completed_at' => $r['completed_at'],
        'success' => (bool)$r['success'],
        'items_processed' => (int)$r['items_processed'],
        'output' => (string)$r['output'],
    ], $recent),
    'latest_by_job' => array_map(fn($r) => [
        'job_name' => (string)$r['job_name'],
        'started_at' => $r['started_at'],
        'completed_at' => $r['completed_at'],
        'success' => (bool)$r['success'],
        'items_processed' => (int)$r['items_processed'],
        'output' => (string)$r['output'],
    ], $latestByJob),
]);
