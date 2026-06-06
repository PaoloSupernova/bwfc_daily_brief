<?php
/**
 * GET /api/insights_latest.php
 * Returns the most recent weekly_insights row, or null if none exists.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\WeeklyInsights;

$latest = WeeklyInsights::latest();
api_success(['insights' => $latest]);
