<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="admin" x-data="jobsAdmin()" x-init="loadJobs()">

    <header class="admin__header">
        <h1 class="heading-display">Scheduled Jobs</h1>
        <p class="lede">Discovery polling and weekly insights generation. Set up a Windows Task Scheduler entry to run these automatically; or trigger them on demand below.</p>
        <div class="admin__nav">
            <a href="?admin=sections" class="site-nav__link">Sections</a>
            <a href="?admin=sources" class="site-nav__link">Sources</a>
            <a href="?admin=jobs" class="site-nav__link site-nav__link--active">Jobs</a>
            <a href="?admin=squad" class="site-nav__link">Squad</a>
            <a href="?admin=knowledge" class="site-nav__link">Knowledge</a>
        </div>
    </header>

    <!-- Quick actions -->
    <div class="jobs-actions">
        <div class="job-action-card">
            <h3 class="job-action-card__name">Discovery polling</h3>
            <p class="job-action-card__desc">Poll all active sources for new candidates. Skips sources polled within their frequency window.</p>
            <div class="job-action-card__last">
                <strong>Last run:</strong>
                <span x-text="lastRunFor('discovery') || lastRunFor('discovery_manual') || 'never'"></span>
            </div>
            <button type="button" class="btn btn--primary" @click="runDiscovery()" :disabled="running">
                <span x-show="!runningJob">Run now</span>
                <span x-show="runningJob === 'discovery'" x-cloak>Polling...</span>
            </button>
        </div>
        <div class="job-action-card">
            <h3 class="job-action-card__name">Weekly insights</h3>
            <p class="job-action-card__desc">Generate the editorial summary for last week. Normally runs Mondays; you can trigger manually here.</p>
            <div class="job-action-card__last">
                <strong>Last run:</strong>
                <span x-text="lastRunFor('weekly_insights') || lastRunFor('weekly_insights_manual') || 'never'"></span>
            </div>
            <button type="button" class="btn btn--primary" @click="runInsights()" :disabled="running">
                <span x-show="runningJob !== 'insights'">Generate now</span>
                <span x-show="runningJob === 'insights'" x-cloak>Generating...</span>
            </button>
        </div>
        <div class="job-action-card">
            <h3 class="job-action-card__name">Database backup</h3>
            <p class="job-action-card__desc">Save a full copy of the database to a dated file. Old backups are pruned automatically. Schedule this daily for safety.</p>
            <div class="job-action-card__last">
                <strong>Last run:</strong>
                <span x-text="lastRunFor('db_backup') || lastRunFor('db_backup_manual') || 'never'"></span>
            </div>
            <button type="button" class="btn btn--primary" @click="runBackup()" :disabled="running">
                <span x-show="runningJob !== 'backup'">Back up now</span>
                <span x-show="runningJob === 'backup'" x-cloak>Backing up...</span>
            </button>
        </div>
    </div>

    <!-- Setup instructions -->
    <details class="jobs-setup">
        <summary>How to schedule these on Windows</summary>
        <div class="jobs-setup__body">
            <p>Open Windows Task Scheduler (search "Task Scheduler" in the Start menu). Create a new Basic Task with these settings:</p>
            <ul>
                <li><strong>Name:</strong> BWFC Daily Brief Jobs</li>
                <li><strong>Trigger:</strong> Daily at 06:30</li>
                <li><strong>Action:</strong> Start a program</li>
                <li><strong>Program:</strong> <code>C:\xampp_new\php\php.exe</code></li>
                <li><strong>Arguments:</strong> <code>C:\xampp_new\htdocs\bwfc-daily-brief\bin\run_jobs.php</code></li>
            </ul>
            <p>The script runs both jobs each time but skips work that's not due. Insights only generate on Mondays unless forced.</p>
            <p><strong>Daily backup</strong> — add a second Basic Task:</p>
            <ul>
                <li><strong>Name:</strong> BWFC Daily Brief Backup</li>
                <li><strong>Trigger:</strong> Daily at 05:30</li>
                <li><strong>Program:</strong> <code>C:\xampp_new\php\php.exe</code></li>
                <li><strong>Arguments:</strong> <code>C:\xampp_new\htdocs\bwfc-daily-brief\bin\backup_db.php</code></li>
            </ul>
            <p>Backups are written to the <code>backups</code> folder (or <code>BACKUP_DIR</code> in .env) and the last 14 are kept.</p>
        </div>
    </details>

    <!-- Recent runs -->
    <section class="editor__section">
        <h2 class="heading-section">Recent runs</h2>
        <div class="empty-state" x-show="recent.length === 0" x-cloak>
            No job runs yet. Trigger one above or wait for the schedule.
        </div>
        <table class="archive-table" x-show="recent.length > 0" x-cloak>
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Started</th>
                    <th>Duration</th>
                    <th>Result</th>
                    <th>Output</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="run in recent" :key="run.id">
                    <tr>
                        <td><span class="job-run__name" x-text="run.job_name"></span></td>
                        <td x-text="formatDate(run.started_at)"></td>
                        <td x-text="duration(run.started_at, run.completed_at)"></td>
                        <td>
                            <span class="status-pill" :class="run.success ? 'status-pill--sent' : 'status-pill--draft'"
                                  x-text="run.success ? 'Success' : (run.completed_at ? 'Failed' : 'Running')"></span>
                        </td>
                        <td class="job-run__output" x-text="run.output || '—'"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </section>
</div>
