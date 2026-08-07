<?php
/**
 * POST /api/sections_journalist_toggle.php
 * Body: { section_id: int, counts: bool }
 *
 * Sets whether a section's articles count toward the journalist dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\JournalistRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$sectionId = (int)($input['section_id'] ?? 0);
$counts = !empty($input['counts']);

if ($sectionId === 0) {
    api_error('section_id is required');
}

JournalistRepository::setSectionFlag($sectionId, $counts);

AuditLog::record('journalist_section_flag', 'section', $sectionId, ['counts' => $counts]);

api_success(['section_id' => $sectionId, 'counts' => $counts]);
