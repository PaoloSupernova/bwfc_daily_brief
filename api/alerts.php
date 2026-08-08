<?php
/**
 * GET /api/alerts.php
 * Returns the current reputation alerts (negative spikes, per-person, topics).
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\AlertService;

api_success(['alerts' => AlertService::current()]);
