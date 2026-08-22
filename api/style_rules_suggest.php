<?php
/**
 * POST /api/style_rules_suggest.php
 * Distils reusable style rules from the team's recent edits (not saved — the
 * admin reviews them and keeps the ones they want).
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\KnowledgeBase;

try {
    $rules = KnowledgeBase::deriveStyleRules();
} catch (\Throwable $e) {
    api_error('Could not analyse edits: ' . $e->getMessage(), 500);
}

api_success([
    'rules' => $rules,
    'note'  => count($rules) === 0
        ? 'Not enough edited summaries yet to distil style rules — edit a few more AI summaries first.'
        : '',
]);
