<?php
/**
 * POST /api/summarise.php
 * Body: { headline, outlet, content, brief_id?, skip_duplicate_check? }
 * Returns: { summary, suggested_section, violations }
 *       OR { duplicate: true, parent_id, parent_headline } when a related story detected
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Summariser;
use BWFC\DailyBrief\Translator;
use BWFC\DailyBrief\StyleGuard;
use BWFC\DailyBrief\AuditLog;
use BWFC\DailyBrief\BriefRepository;

$input = api_input();
$headline = trim((string)($input['headline'] ?? ''));
$outlet = trim((string)($input['outlet'] ?? 'Unknown'));
$content = trim((string)($input['content'] ?? ''));
$briefId = (int)($input['brief_id'] ?? 0);
$skipDuplicateCheck = !empty($input['skip_duplicate_check']);
$ignoreRelevance = !empty($input['ignore_relevance']);

if ($headline === '' || $content === '') {
    api_error('Headline and content are required');
}

$summariser = new Summariser();

$tStart = microtime(true);

// Translate non-English content to English (via Sonnet) so the analysis below
// runs on English and stays consistent. English text is detected locally and
// passes through untouched (no API call).
$translation = Translator::toEnglish($content, $headline);
$content = $translation['text'];
$sourceLanguage = $translation['is_english'] ? null : $translation['language'];
$tAfterTranslate = microtime(true);

// Summary, section/sentiment/topic classification and duplicate detection all
// run CONCURRENTLY inside processArticle(), so the AI wait is the slowest single
// call, not their sum. Duplicate detection only runs when the brief already has
// articles.
$existing = ($briefId > 0 && !$skipDuplicateCheck)
    ? BriefRepository::standaloneArticlesForBrief($briefId)
    : [];

$result = $summariser->processArticle($headline, $outlet, $content, $ignoreRelevance, $existing);
$tAfterProcess = microtime(true);

$timing = [
    'translate_ms' => (int)round(($tAfterTranslate - $tStart) * 1000),
    'process_ms'   => (int)round(($tAfterProcess - $tAfterTranslate) * 1000),
    'total_ms'     => (int)round((microtime(true) - $tStart) * 1000),
];

// Article duplicates one already in the brief — report that and stop.
if (isset($result['duplicate'])) {
    AuditLog::record('duplicate_detected', 'article', null, [
        'brief_id' => $briefId,
        'headline' => $headline,
        'parent_id' => $result['duplicate']['article_id'],
    ]);
    api_success([
        'duplicate' => true,
        'parent_id' => $result['duplicate']['article_id'],
        'parent_headline' => $result['duplicate']['headline'],
        '_timing' => $timing,
    ]);
}

$summary   = $result['summary'];
$section   = $result['section'];
$sentiment = $result['sentiment'];
$topic     = $result['topic'];

$violations = StyleGuard::check($summary);

AuditLog::record('article_summary_generated', 'article', null, [
    'outlet'            => $outlet,
    'headline'          => $headline,
    'suggested_section' => $section,
    'sentiment'         => $sentiment,
    'style_violations'  => count($violations['violations']),
    'process_ms'        => $timing['process_ms'],
]);

api_success([
    'summary'          => $summary,
    'suggested_section'=> $section,
    'sentiment'        => $sentiment,
    'suggested_topic'  => $topic,
    'source_language'  => $sourceLanguage,
    'style_check'      => $violations,
    '_timing'          => $timing,
]);
