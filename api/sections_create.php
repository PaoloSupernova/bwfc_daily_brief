<?php
/**
 * POST /api/sections_create.php
 * Body: { name, routing_description }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$name = trim((string)($input['name'] ?? ''));
$routing = trim((string)($input['routing_description'] ?? ''));

if ($name === '') {
    api_error('Section name is required');
}

$id = BriefRepository::createSection($name, $routing);

AuditLog::record('section_created', 'section', $id, ['name' => $name]);

api_success(['section' => BriefRepository::getSection($id)]);
