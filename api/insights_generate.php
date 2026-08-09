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
use BWFC\DailyBrief\JobLog;

$input = api_input();
$weekStart = isset($input['week_start']) ? trim((string)$input['week_start']) : null;
if ($weekStart !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
    api_error('Invalid week_start format (use YYYY-MM-DD)');
}

$jobId = JobLog::start('weekly_insights_manual');

try {
    $result = WeeklyInsights::generate($weekStart, 'manual');
    JobLog::finish($jobId, true, "Generated insights for week {$result['week_start']}", 1);
    api_success($result);
} catch (Throwable $e) {
    JobLog::finish($jobId, false, 'FAILED: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}
