<?php
/**
 * POST /api/update_executive_summary.php
 * Body: { brief_id, executive_summary }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$briefId = (int)($input['brief_id'] ?? 0);
$summary = trim((string)($input['executive_summary'] ?? ''));

if ($briefId === 0) {
    api_error('brief_id is required');
}

BriefRepository::updateBrief($briefId, ['executive_summary' => $summary]);

AuditLog::record('executive_summary_edited', 'brief', $briefId);

$violations = StyleGuard::check($summary);

api_success([
    'style_check' => $violations,
]);
