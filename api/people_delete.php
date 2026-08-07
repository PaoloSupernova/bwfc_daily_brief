<?php
/**
 * POST /api/people_delete.php
 * Body: { id }
 *
 * Removes a person. Their article links cascade away; the articles themselves
 * are untouched. To keep history but stop tracking, deactivate via people_save
 * (active=false) instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\PeopleRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);

if ($id === 0) {
    api_error('id is required');
}

PeopleRepository::delete($id);
AuditLog::record('person_deleted', 'person', $id, []);

api_success(['id' => $id]);
