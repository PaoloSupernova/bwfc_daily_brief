<?php
/**
 * POST /api/queue_ingest.php
 * Body: { candidate_id }
 *
 * Workflow:
 *  1. Look up the candidate
 *  2. Get or create today's draft brief
 *  3. Fetch the article body via ArticleFetcher (fall back to description if it fails)
 *  4. Generate summary + section suggestion via Summariser
 *  5. Resolve the suggested section to a section_id
 *  6. Save the article into the brief
 *  7. Mark candidate as 'ingested'
 *  8. Return everything to the UI for the side panel
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\ArticleFetcher;
use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;

$input = api_input();
$candidateId = (int)($input['candidate_id'] ?? 0);
if ($candidateId === 0) {
    api_error('candidate_id is required');
}

$candidate = Database::selectOne(
    "SELECT * FROM discovery_candidates WHERE id = :id",
    ['id' => $candidateId]
);
if ($candidate === null) {
    api_error('Candidate not found', 404);
}

if ((string)$candidate['status'] === 'ingested') {
    api_error('This candidate has already been added to a brief', 409);
}

// Get or create today's draft brief
$today = date('Y-m-d');
$brief = BriefRepository::findBriefByDate($today);
if ($brief === null) {
    $briefId = BriefRepository::createBrief($today);
    $brief = BriefRepository::findBrief($briefId);
}
if ($brief === null) {
    api_error('Could not create or find today\'s brief', 500);
}
if ((string)$brief['status'] === 'sent') {
    api_error('Today\'s brief has already been sent and is locked.', 409);
}

$briefId = (int)$brief['id'];

// Fetch the article body
$url = (string)$candidate['url'];
$fetchResult = null;
$wasPaywallFallback = false;
try {
    $fetchResult = (new ArticleFetcher())->fetch($url);
} catch (Throwable $e) {
    $fetchResult = ['success' => false, 'error' => $e->getMessage()];
}

$articleContent = '';
$headline = (string)$candidate['headline'];
$outletName = (string)$candidate['outlet_name'];

if (!empty($fetchResult['success'])) {
    $articleContent = (string)($fetchResult['content'] ?? '');
    if (!empty($fetchResult['headline'])) {
        $headline = (string)$fetchResult['headline'];
    }
    if (!empty($fetchResult['outlet'])) {
        $outletName = (string)$fetchResult['outlet'];
    }
} else {
    $articleContent = (string)($candidate['description'] ?? '');
    $wasPaywallFallback = true;
}

// Summarise
$summary = '';
$suggestedSection = 'BWFC';
$violations = [];

if (trim($articleContent) !== '') {
    try {
        $summariser = new Summariser();
        $summary = $summariser->summariseArticle($headline, $outletName, $articleContent);
        $suggestedSection = $summariser->suggestSection($headline, $outletName, $articleContent);
        $violations = StyleGuard::check($summary)['violations'] ?? [];
    } catch (Throwable $e) {
        $summary = '[Summary generation failed: ' . $e->getMessage() . '] Edit in the editor.';
    }
}

// Resolve section_id
$sectionSlugMap = [
    'BWFC' => 'bwfc',
    'EFL' => 'efl',
    'LOCAL_COMMUNITY' => 'local_community',
    'WOMENS_GAME' => 'womens_game',
    'GENERAL_FOOTBALL' => 'general_football',
    'LOCAL_BUSINESS' => 'local_business',
    'OTHER_SPORT' => 'other_sport',
];
$sectionSlug = $sectionSlugMap[strtoupper($suggestedSection)] ?? 'bwfc';

$section = BriefRepository::getSectionBySlug($sectionSlug);
if ($section === null) {
    $section = BriefRepository::getSectionBySlug('bwfc');
}
if ($section === null) {
    api_error('No sections configured in the database', 500);
}

// Save article
$articleId = BriefRepository::addArticle($briefId, [
    'section_id' => (int)$section['id'],
    'url' => $url,
    'outlet_name' => $outletName,
    'headline' => $headline,
    'article_content' => $articleContent,
    'summary' => $summary,
    'summary_original' => $summary,
    'was_paywall_fallback' => $wasPaywallFallback,
]);

// Mark candidate as ingested
Database::updateRow('discovery_candidates', [
    'status' => 'ingested',
    'ingested_article_id' => $articleId,
], ['id' => $candidateId]);

AuditLog::record('queue_ingest', 'candidate', $candidateId, [
    'article_id' => $articleId,
    'brief_id' => $briefId,
    'fetch_success' => !empty($fetchResult['success']),
]);

// Return for the side panel
$article = BriefRepository::getArticle($articleId);

api_success([
    'article' => $article,
    'brief_id' => $briefId,
    'suggested_section' => $suggestedSection,
    'violations' => $violations,
    'was_paywall_fallback' => $wasPaywallFallback,
    'fetch_error' => $wasPaywallFallback ? ($fetchResult['error'] ?? 'Article fetch failed') : null,
]);
