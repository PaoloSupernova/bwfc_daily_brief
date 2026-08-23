<?php
/**
 * BWFC Daily Brief - Database backup (run from Task Scheduler / cron)
 *
 * Dumps the database to a dated .sql file and prunes old ones. Safe to run any
 * time. Schedule it daily so a crash or bad import is a quick restore.
 *
 *   php bin/backup_db.php
 *
 * Windows Task Scheduler:
 *   Program:   C:\xampp_new\php\php.exe
 *   Arguments: C:\xampp_new\htdocs\bwfc-daily-brief\bin\backup_db.php
 *   Trigger:   Daily, e.g. 05:30
 *
 * Config (optional, in .env): BACKUP_DIR, BACKUP_KEEP, MYSQLDUMP_PATH
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Backup;
use BWFC\DailyBrief\JobLog;

echo "BWFC database backup\n";
echo str_repeat('-', 40) . "\n";

$jobId = JobLog::start('db_backup');
$result = Backup::run();
JobLog::finish($jobId, $result['success'], $result['message'], $result['pruned']);

if ($result['success']) {
    echo "OK: " . $result['message'] . "\n";
    echo "File: " . $result['file'] . "\n";
    exit(0);
}

fwrite(STDERR, "FAILED: " . $result['message'] . "\n");
exit(1);
