<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

$sections = BriefRepository::sections(true);
$today = date('Y-m-d');
$brief = ['id' => 0, 'brief_date' => $today, 'executive_summary' => '', 'status' => 'draft', 'is_weekend_rollup' => 0];
$articles = [];
$isLocked = false;

require __DIR__ . '/_editor.php';
