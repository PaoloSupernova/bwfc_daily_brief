<?php
/**
 * GET /api/trends.php
 * Returns trend alerts — movement in coverage (person/topic spikes, rising
 * negative streaks, overall volume shifts). Items flagged banner=true are the
 * strongest one or two, for the morning alert banner.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\TrendService;

api_success(['trends' => TrendService::current()]);
