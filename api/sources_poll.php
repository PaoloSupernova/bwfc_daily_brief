<?php
/**
 * POST /api/sources_poll.php
 * Body: { source_id?, force? }
 *
 * If source_id is given, polls that single source. Otherwise polls all due
 * (or all if force=true). Returns the poll result.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\DiscoveryService;
use BWFC\DailyBrief\JobLog;

$input = api_input();
$sourceId = isset($input['source_id']) ? (int)$input['source_id'] : 0;
$force = !empty($input['force']);

$jobId = JobLog::start('discovery_manual');

try {
    if ($sourceId > 0) {
        $result = DiscoveryService::pollSource($sourceId);
        $output = "Polled source {$sourceId}: {$result['items_found']} found, {$result['items_new']} new";
        JobLog::finish($jobId, true, $output, (int)$result['items_new']);
        api_success([
            'mode' => 'single',
            'items_found' => $result['items_found'],
            'items_new' => $result['items_new'],
        ]);
    } else {
        $result = DiscoveryService::pollAllDue($force);
        $output = "Polled {$result['sources_polled']} sources ({$result['sources_skipped']} skipped). {$result['items_new']} new candidates.";
        JobLog::finish($jobId, count($result['errors']) === 0, $output, (int)$result['items_new']);
        api_success([
            'mode' => 'all',
            'sources_polled' => $result['sources_polled'],
            'sources_skipped' => $result['sources_skipped'],
            'items_found' => $result['items_found'],
            'items_new' => $result['items_new'],
            'errors' => $result['errors'],
        ]);
    }
} catch (Throwable $e) {
    JobLog::finish($jobId, false, 'FAILED: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}
