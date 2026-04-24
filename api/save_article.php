<?php
/**
 * POST /api/save_article.php
 * Commit an article to a brief. Creates the brief if it does not exist yet for the date.
 * Body: { brief_id?, brief_date, url, outlet_name, headline, article_content, summary, summary_original, section_slug, was_paywall_fallback }
 * Returns: { brief_id, article_id, article }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();

$briefId = isset($input['brief_id']) ? (int)$input['brief_id'] : 0;
$briefDate = trim((string)($input['brief_date'] ?? date('Y-m-d')));
$sectionSlug = trim((string)($input['section_slug'] ?? ''));

if ($sectionSlug === '') {
    api_error('Section is required');
}

$section = BriefRepository::getSectionBySlug($sectionSlug);
if ($section === null) {
    api_error('Unknown section: ' . $sectionSlug);
}

// Create brief if needed
if ($briefId === 0) {
    $existing = BriefRepository::findBriefByDate($briefDate);
    if ($existing !== null) {
        $briefId = (int)$existing['id'];
    } else {
        $dayOfWeek = (int)date('N', strtotime($briefDate));
        $isMonday = $dayOfWeek === 1;
        $briefId = BriefRepository::createBrief($briefDate, $isMonday);
        AuditLog::record('brief_created', 'brief', $briefId, ['date' => $briefDate]);
    }
}

if (BriefRepository::isLocked($briefId)) {
    api_error('Brief is sent and locked', 423);
}

$articleId = BriefRepository::addArticle($briefId, [
    'section_id' => (int)$section['id'],
    'url' => (string)($input['url'] ?? ''),
    'outlet_name' => (string)($input['outlet_name'] ?? 'Unknown'),
    'headline' => (string)($input['headline'] ?? ''),
    'article_content' => (string)($input['article_content'] ?? ''),
    'summary' => (string)($input['summary'] ?? ''),
    'summary_original' => (string)($input['summary_original'] ?? ($input['summary'] ?? '')),
    'was_paywall_fallback' => !empty($input['was_paywall_fallback']),
]);

AuditLog::record('article_added', 'article', $articleId, [
    'brief_id' => $briefId,
    'section' => $sectionSlug,
    'outlet' => $input['outlet_name'] ?? null,
    'was_edited' => !empty($input['was_edited']),
]);

$article = BriefRepository::getArticle($articleId);

api_success([
    'brief_id' => $briefId,
    'article_id' => $articleId,
    'article' => $article,
]);
