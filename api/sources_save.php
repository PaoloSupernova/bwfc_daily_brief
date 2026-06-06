<?php
/**
 * POST /api/sources_save.php
 * Body: { id?, name, url, source_type, is_local, is_active, poll_frequency_minutes }
 * Creates a new source if id is missing, updates if present.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$input = api_input();
$id = isset($input['id']) ? (int)$input['id'] : 0;
$name = trim((string)($input['name'] ?? ''));
$url = trim((string)($input['url'] ?? ''));
$sourceType = (string)($input['source_type'] ?? 'rss');
$isLocal = !empty($input['is_local']) ? 1 : 0;
$isActive = !empty($input['is_active']) ? 1 : 0;
$freq = max(15, (int)($input['poll_frequency_minutes'] ?? 60));

if ($name === '' || $url === '') {
    api_error('Name and URL are required');
}
if (!in_array($sourceType, ['rss', 'google_news'], true)) {
    api_error('Invalid source type');
}

$payload = [
    'name' => $name,
    'url' => $url,
    'source_type' => $sourceType,
    'is_local' => $isLocal,
    'is_active' => $isActive,
    'poll_frequency_minutes' => $freq,
];

if ($id > 0) {
    Database::updateRow('discovery_sources', $payload, ['id' => $id]);
    $sourceId = $id;
} else {
    $sourceId = Database::insertRow('discovery_sources', $payload);
}

$saved = Database::selectOne("SELECT * FROM discovery_sources WHERE id = :id", ['id' => $sourceId]);
api_success(['source' => $saved]);
