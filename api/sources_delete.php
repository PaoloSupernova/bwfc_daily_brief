<?php
/**
 * POST /api/sources_delete.php
 * Body: { id }
 * Soft-deletes a discovery source (sets deleted_at).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$input = api_input();
$id = (int)($input['id'] ?? 0);
if ($id === 0) api_error('id is required');

Database::updateRow('discovery_sources', [
    'deleted_at' => date('Y-m-d H:i:s'),
    'is_active' => 0,
], ['id' => $id]);

api_success(['deleted' => true]);
