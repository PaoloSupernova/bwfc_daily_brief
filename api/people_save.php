<?php
/**
 * POST /api/people_save.php
 * Body: { id?, name, role, aliases?, active? }
 *
 * Create a new known person or update an existing one. Used by Admin → Squad.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\PeopleRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$role = trim((string)($input['role'] ?? 'player'));
$aliases = trim((string)($input['aliases'] ?? ''));
$active = array_key_exists('active', $input) ? !empty($input['active']) : true;

if ($name === '') {
    api_error('Name is required');
}
if (!in_array($role, ['player', 'staff', 'exec', 'other'], true)) {
    api_error('Invalid role');
}

if ($id > 0) {
    PeopleRepository::update($id, $name, $role, $aliases, $active, true);
    AuditLog::record('person_updated', 'person', $id, ['name' => $name, 'role' => $role]);
} else {
    $id = PeopleRepository::create($name, $role, $aliases);
    AuditLog::record('person_created', 'person', $id, ['name' => $name, 'role' => $role]);
}

api_success(['id' => $id]);
