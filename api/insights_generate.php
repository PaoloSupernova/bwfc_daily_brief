<?php
/**
 * POST /api/insights_generate.php
 * Body: { week_start? }   (defaults to last completed week)
 *
 * Generates a weekly insights summary on demand.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\WeeklyInsights;
use BWFC\DailyBrief\Database;

$input = api_input();
$weekStart = isset($input['week_start']) ? trim((string)$input['week_start']) : null;
if ($weekStart !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
    api_error('Invalid week_start format (use YYYY-MM-DD)');
}

$jobId = Database::insertRow('job_runs', [
    'job_name' => 'weekly_insights_manual',
    'started_at' => date('Y-m-d H:i:s'),
]);

try {
    $result = WeeklyInsights::generate($weekStart, 'manual');
    Database::updateRow('job_runs', [
        'completed_at' => date('Y-m-d H:i:s'),
        'success' => 1,
        'items_processed' => 1,
        'output' => "Generated insights for week {$result['week_start']}",
    ], ['id' => $jobId]);
    api_success($result);
} catch (Throwable $e) {
    Database::updateRow('job_runs', [
        'completed_at' => date('Y-m-d H:i:s'),
        'success' => 0,
        'output' => 'FAILED: ' . $e->getMessage(),
    ], ['id' => $jobId]);
    api_error($e->getMessage(), 500);
}
