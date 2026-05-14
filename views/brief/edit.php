<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

// $brief and $articles come from index.php router
$sections = BriefRepository::sections(true);
$isLocked = false;

require __DIR__ . '/_editor.php';
