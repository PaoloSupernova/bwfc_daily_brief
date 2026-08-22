<?php
/**
 * POST /api/knowledge_save.php
 * Body: { id?, category, title, content, active? }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\KnowledgeBase;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
$category = trim((string)($input['category'] ?? 'general'));
$title = trim((string)($input['title'] ?? ''));
$content = trim((string)($input['content'] ?? ''));
$active = array_key_exists('active', $input) ? !empty($input['active']) : true;

if ($title === '' || $content === '') {
    api_error('Title and content are required');
}

if ($id > 0) {
    KnowledgeBase::update($id, $category, $title, $content, $active);
    AuditLog::record('knowledge_updated', 'knowledge', $id, ['title' => $title]);
} else {
    $id = KnowledgeBase::create($category, $title, $content, $active);
    AuditLog::record('knowledge_created', 'knowledge', $id, ['title' => $title]);
}

api_success(['id' => $id]);
