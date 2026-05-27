<?php
/**
 * POST /api/save_article.php
 * Body: { brief_id?, brief_date, url, outlet_name, headline, article_content,
 *         summary, summary_original, section_slug, was_paywall_fallback,
 *         parent_article_id? }
 * Returns: { brief_id, article_id, article }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\AuditLog;

$input = api_input();

$briefId = isset($input['brief_id']) ? (int)$input['brief_id'] : 0;
$briefDate = trim((string)($input['brief_date'] ?? date('Y-m-d')));
$parentArticleId = isset($input['parent_article_id']) && (int)$input['parent_article_id'] > 0
    ? (int)$input['parent_article_id']
    : null;

// Resolve section: related articles inherit the parent's section
if ($parentArticleId !== null) {
    $parentArticle = BriefRepository::getArticle($parentArticleId);
    if ($parentArticle === null) {
        api_error('Parent article not found', 404);
    }
    $section = ['id' => (int)$parentArticle['section_id']];
    $sectionSlug = (string)$parentArticle['section_slug'];
} else {
    $sectionSlug = trim((string)($input['section_slug'] ?? ''));
    if ($sectionSlug === '') {
        api_error('Section is required');
    }
    $section = BriefRepository::getSectionBySlug($sectionSlug);
    if ($section === null) {
        api_error('Unknown section: ' . $sectionSlug);
    }
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

$rawSentiment = strtolower(trim((string)($input['sentiment'] ?? '')));
$sentiment = in_array($rawSentiment, ['positive', 'neutral', 'negative'], true) ? $rawSentiment : null;

$articleId = BriefRepository::addArticle($briefId, [
    'section_id' => (int)$section['id'],
    'url' => (string)($input['url'] ?? ''),
    'outlet_name' => (string)($input['outlet_name'] ?? 'Unknown'),
    'headline' => (string)($input['headline'] ?? ''),
    'article_content' => (string)($input['article_content'] ?? ''),
    'summary' => (string)($input['summary'] ?? ''),
    'summary_original' => (string)($input['summary_original'] ?? ($input['summary'] ?? '')),
    'was_paywall_fallback' => !empty($input['was_paywall_fallback']),
    'parent_article_id' => $parentArticleId,
    'sentiment' => $sentiment,
]);

AuditLog::record('article_added', 'article', $articleId, [
    'brief_id' => $briefId,
    'section' => $sectionSlug,
    'outlet' => $input['outlet_name'] ?? null,
    'is_related' => $parentArticleId !== null,
    'parent_id' => $parentArticleId,
]);

$article = BriefRepository::getArticle($articleId);

api_success([
    'brief_id' => $briefId,
    'article_id' => $articleId,
    'article' => $article,
]);
