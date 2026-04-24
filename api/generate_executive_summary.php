<?php
/**
 * POST /api/generate_executive_summary.php
 * Body: { brief_id }
 * Returns: { executive_summary, violations }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$briefId = (int)($input['brief_id'] ?? 0);

if ($briefId === 0) {
    api_error('brief_id is required');
}

$brief = BriefRepository::findBrief($briefId);
if ($brief === null) {
    api_error('Brief not found', 404);
}

if (BriefRepository::isLocked($briefId)) {
    api_error('Brief is sent and locked', 423);
}

$articles = BriefRepository::articlesForBrief($briefId);
if (count($articles) === 0) {
    api_error('No articles in brief. Add articles before generating executive summary.');
}

$formatted = array_map(fn($a) => [
    'headline' => (string)$a['headline'],
    'outlet' => (string)$a['outlet_name'],
    'summary' => (string)$a['summary'],
    'section' => (string)$a['section_name'],
], $articles);

$summariser = new Summariser();
$executiveSummary = $summariser->executiveSummary($formatted);

BriefRepository::updateBrief($briefId, ['executive_summary' => $executiveSummary]);

$violations = StyleGuard::check($executiveSummary);

AuditLog::record('executive_summary_generated', 'brief', $briefId, [
    'article_count' => count($articles),
    'violation_count' => count($violations['violations']),
]);

api_success([
    'executive_summary' => $executiveSummary,
    'style_check' => $violations,
]);
