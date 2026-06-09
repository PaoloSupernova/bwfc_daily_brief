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

// Decide whether we have enough real article text to safely summarise.
// Hallucination happens when the model is handed little more than a headline
// (Google News / RSS descriptions are often just the headline echoed back), so
// we never ask the AI to summarise thin content — we flag for manual editing instead.
$contentForSummary = trim($articleContent);
$headlineTrim = trim($headline);
// Strip a leading echo of the headline from the description if present
if ($headlineTrim !== '' && stripos($contentForSummary, $headlineTrim) === 0) {
    $contentForSummary = trim(substr($contentForSummary, strlen($headlineTrim)));
}
$hasEnoughContent = mb_strlen($contentForSummary) >= 250;

$summary = '';
$suggestedSection = 'BWFC';
$violations = [];
$summariser = new Summariser();

if ($hasEnoughContent) {
    try {
        $summary = $summariser->summariseArticle($headline, $outletName, $articleContent);
        $violations = StyleGuard::check($summary)['violations'] ?? [];
    } catch (Throwable $e) {
        $summary = '[Summary generation failed: ' . $e->getMessage() . '] Edit in the editor.';
    }
} else {
    // Not enough source text — do not let the AI invent a summary. Store the
    // honest feed text (if any) or a clear instruction, and flag for manual review.
    $summary = ($contentForSummary !== '' && mb_strlen($contentForSummary) >= 30)
        ? $contentForSummary
        : '[Full article body could not be retrieved. Open the article, paste the text into the editor, and regenerate the summary.]';
    $wasPaywallFallback = true;
}

// Section suggestion returns a validated category label, so it is low risk even
// from a headline alone. Run it either way, using the body when we have it.
try {
    $suggestedSection = $summariser->suggestSection(
        $headline,
        $outletName,
        $hasEnoughContent ? $articleContent : $headline
    );
} catch (Throwable $e) {
    $suggestedSection = 'BWFC';
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
