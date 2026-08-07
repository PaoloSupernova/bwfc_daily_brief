<?php
/**
 * GET /api/people_admin_list.php
 * Returns every person for the squad-management admin screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\PeopleRepository;

api_success(['people' => PeopleRepository::all(false)]);
