<?php
/**
 * POST /api/sections_update.php
 * Body: { id, name, routing_description }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$routing = trim((string)($input['routing_description'] ?? ''));

if ($id === 0 || $name === '') {
    api_error('id and name are required');
}

BriefRepository::updateSection($id, $name, $routing);

AuditLog::record('section_updated', 'section', $id, ['name' => $name]);

api_success(['section' => BriefRepository::getSection($id)]);
