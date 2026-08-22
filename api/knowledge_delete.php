<?php
/** POST /api/knowledge_delete.php  Body: { id } */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\KnowledgeBase;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$id = (int)($input['id'] ?? 0);
if ($id === 0) {
    api_error('id is required');
}

KnowledgeBase::delete($id);
AuditLog::record('knowledge_deleted', 'knowledge', $id, []);

api_success(['id' => $id]);
