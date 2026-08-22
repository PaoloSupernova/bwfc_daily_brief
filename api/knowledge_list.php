<?php
/** GET /api/knowledge_list.php — all knowledge entries for the admin screen. */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\KnowledgeBase;

api_success([
    'entries'    => KnowledgeBase::all(false),
    'categories' => KnowledgeBase::CATEGORIES,
]);
