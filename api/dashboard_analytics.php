<?php
/**
 * GET/POST /api/dashboard_analytics.php
 *
 * Body: { window?: 7 | 30 | 90 }  (defaults to 30)
 *
 * Returns aggregated metrics for the dashboard.
 *
 * Notable change in v1.6.1: the "local vs national" comparison and the
 * "national pickup" stat are filtered to BWFC-section articles only. This is
 * the genuinely useful question — of stories about Bolton Wanderers, who's
 * covering them? — rather than counting national coverage of unrelated EFL
 * and General Football stories that happen to be in the brief.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;
use BWFC\DailyBrief\Topics;

$input = api_input();
$window = (int)($input['window'] ?? 30);
if (!in_array($window, [7, 30, 90], true)) {
    $window = 30;
}

$now = date('Y-m-d');
$rangeStart = date('Y-m-d', strtotime("-{$window} days"));
$priorStart = date('Y-m-d', strtotime("-" . ($window * 2) . " days"));

// ============================================================
// Local outlet classification
// ============================================================

$localOutletPatterns = [
    'bwfc.co.uk',
    'Bolton News',
    'Lancashire Evening Post',
    'Manchester Evening News',
    'BWitC',
    'Bolton Stadium Hotel',
];

/**
 * Decide whether an outlet name matches the local list.
 */
$isLocal = function (string $outletName) use ($localOutletPatterns): bool {
    foreach ($localOutletPatterns as $pattern) {
        if (stripos($outletName, $pattern) !== false) return true;
    }
    return false;
};

// ============================================================
// HEADLINE STATS
// ============================================================

$totalBriefs = (int)(Database::selectOne(
    "SELECT COUNT(*) AS n FROM briefs WHERE deleted_at IS NULL AND status = 'sent'"
)['n'] ?? 0);

$articlesThisWindow = (int)(Database::selectOne(
    "SELECT COUNT(*) AS n FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start",
    ['start' => $rangeStart]
)['n'] ?? 0);

$articlesPriorWindow = (int)(Database::selectOne(
    "SELECT COUNT(*) AS n FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :pstart AND b.brief_date < :start",
    ['pstart' => $priorStart, 'start' => $rangeStart]
)['n'] ?? 0);

$delta = $articlesPriorWindow > 0
    ? round((($articlesThisWindow - $articlesPriorWindow) / $articlesPriorWindow) * 100)
    : null;

$topOutletRow = Database::selectOne(
    "SELECT a.outlet_name, COUNT(*) AS n
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start
     GROUP BY a.outlet_name
     ORDER BY n DESC, a.outlet_name
     LIMIT 1",
    ['start' => $rangeStart]
);

$busiestSectionRow = Database::selectOne(
    "SELECT s.name, COUNT(*) AS n
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     JOIN sections s ON s.id = a.section_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start AND s.deleted_at IS NULL
     GROUP BY s.id, s.name
     ORDER BY n DESC
     LIMIT 1",
    ['start' => $rangeStart]
);

// ============================================================
// BWFC-section articles: pull these once, derive local/national + pickup
// ============================================================

$bwfcArticles = Database::select(
    "SELECT a.outlet_name
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     JOIN sections s ON s.id = a.section_id
     WHERE b.deleted_at IS NULL
       AND b.brief_date >= :start
       AND s.slug = 'bwfc'",
    ['start' => $rangeStart]
);

$bwfcLocalCount = 0;
$bwfcNationalCount = 0;
foreach ($bwfcArticles as $row) {
    if ($isLocal((string)$row['outlet_name'])) {
        $bwfcLocalCount++;
    } else {
        $bwfcNationalCount++;
    }
}
$bwfcTotal = $bwfcLocalCount + $bwfcNationalCount;
$nationalPickupPct = $bwfcTotal > 0 ? round(($bwfcNationalCount / $bwfcTotal) * 100) : null;

$headlineStats = [
    'total_briefs' => $totalBriefs,
    'articles_this_window' => $articlesThisWindow,
    'articles_prior_window' => $articlesPriorWindow,
    'articles_delta_pct' => $delta,
    'top_outlet' => $topOutletRow !== null ? [
        'name' => (string)$topOutletRow['outlet_name'],
        'count' => (int)$topOutletRow['n'],
    ] : null,
    'busiest_section' => $busiestSectionRow !== null ? [
        'name' => (string)$busiestSectionRow['name'],
        'count' => (int)$busiestSectionRow['n'],
    ] : null,
    'national_pickup' => [
        'pct' => $nationalPickupPct,
        'national_count' => $bwfcNationalCount,
        'total_bwfc' => $bwfcTotal,
    ],
];

// ============================================================
// SECTION SHARE (donut)
// ============================================================

$sectionShare = Database::select(
    "SELECT s.name AS label, s.slug, COUNT(*) AS value
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     JOIN sections s ON s.id = a.section_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start AND s.deleted_at IS NULL
     GROUP BY s.id, s.name, s.slug
     ORDER BY value DESC",
    ['start' => $rangeStart]
);
$sectionShare = array_map(fn($r) => [
    'label' => (string)$r['label'],
    'slug' => (string)$r['slug'],
    'value' => (int)$r['value'],
], $sectionShare);

// ============================================================
// TOP OUTLETS (horizontal bar) — top 10 across all sections
// ============================================================

$topOutlets = Database::select(
    "SELECT a.outlet_name AS label, COUNT(*) AS value
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start
     GROUP BY a.outlet_name
     ORDER BY value DESC, a.outlet_name
     LIMIT 10",
    ['start' => $rangeStart]
);
$topOutlets = array_map(fn($r) => [
    'label' => (string)$r['label'],
    'value' => (int)$r['value'],
], $topOutlets);

// ============================================================
// LOCAL VS NATIONAL — BWFC SECTION ONLY
// (already counted above when computing national pickup)
// ============================================================

$localVsNational = [
    'local' => $bwfcLocalCount,
    'national' => $bwfcNationalCount,
];

// ============================================================
// WORD CLOUD
// ============================================================

$summaries = Database::select(
    "SELECT a.summary
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start AND a.summary IS NOT NULL",
    ['start' => $rangeStart]
);

$wordFreq = compute_word_frequencies(
    array_map(fn($r) => (string)$r['summary'], $summaries),
    50
);

// ============================================================
// SENTIMENT BREAKDOWN (standalone articles only)
// ============================================================

$sentimentRows = Database::select(
    "SELECT a.sentiment, COUNT(*) AS n
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start
       AND a.sentiment IS NOT NULL AND a.parent_article_id IS NULL
     GROUP BY a.sentiment",
    ['start' => $rangeStart]
);
$sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
foreach ($sentimentRows as $r) {
    if (isset($sentimentCounts[$r['sentiment']])) {
        $sentimentCounts[$r['sentiment']] = (int)$r['n'];
    }
}

// ============================================================
// TOPIC BREAKDOWN — what coverage is about
// ============================================================

$topicRows = Database::select(
    "SELECT a.topic, COUNT(*) AS n,
            SUM(a.sentiment = 'negative') AS negative
     FROM brief_articles a
     JOIN briefs b ON b.id = a.brief_id
     WHERE b.deleted_at IS NULL AND b.brief_date >= :start
       AND a.topic IS NOT NULL AND a.parent_article_id IS NULL
     GROUP BY a.topic
     ORDER BY n DESC",
    ['start' => $rangeStart]
);
$topicBreakdown = array_map(fn($r) => [
    'slug'     => (string)$r['topic'],
    'label'    => Topics::label((string)$r['topic']),
    'value'    => (int)$r['n'],
    'negative' => (int)$r['negative'],
], $topicRows);

// ============================================================
// RECENT BRIEFS (sidebar)
// ============================================================

$recent = Database::select(
    "SELECT b.id, b.brief_date, b.status,
            (SELECT COUNT(*) FROM brief_articles WHERE brief_id = b.id) AS article_count
     FROM briefs b
     WHERE b.deleted_at IS NULL
     ORDER BY b.brief_date DESC, b.id DESC
     LIMIT 8"
);
$recent = array_map(fn($r) => [
    'id' => (int)$r['id'],
    'brief_date' => (string)$r['brief_date'],
    'status' => (string)$r['status'],
    'article_count' => (int)$r['article_count'],
], $recent);

api_success([
    'window' => $window,
    'range_start' => $rangeStart,
    'range_end' => $now,
    'headline_stats' => $headlineStats,
    'section_share' => $sectionShare,
    'top_outlets' => $topOutlets,
    'local_vs_national' => $localVsNational,
    'word_cloud' => $wordFreq,
    'recent_briefs' => $recent,
    'sentiment' => $sentimentCounts,
    'topic_breakdown' => $topicBreakdown,
]);

// ============================================================
// HELPERS
// ============================================================

/**
 * @param array<int, string> $texts
 * @return array<int, array{text: string, value: int}>
 */
function compute_word_frequencies(array $texts, int $topN): array
{
    $stopWords = array_flip([
        'the','a','an','and','or','but','of','to','in','on','at','for','with','from','by','as','is','was','are','were','be','been','being','has','have','had','do','does','did','will','would','should','could','may','might','must','can','this','that','these','those','it','its','their','his','her','our','your','their','they','them','we','us','i','me','he','she','him','his',
        'who','what','when','where','why','how','which','than','then','so','if','because','while','during','also','just','only','very','more','most','some','any','all','each','every','both','few','many','much','one','two','three',
        'said','says','told','says','add','added','reported','according',
        'after','before','about','above','below','over','under','between','among','through','into','onto','out','off','up','down',
        'football','match','game','team','squad','player','players','manager','coach','play','playing','played','season','league','fixture','goal','goals',
        'bolton','wanderers','wanderer','bwfc','club','clubs',
    ]);

    $counts = [];
    foreach ($texts as $text) {
        $clean = strtolower($text);
        $clean = preg_replace('/[^a-z0-9\-\s\']/', ' ', $clean) ?? '';
        $tokens = preg_split('/\s+/', $clean) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token, "-' ");
            if (strlen($token) < 4) continue;
            if (is_numeric($token)) continue;
            if (isset($stopWords[$token])) continue;
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }
    }

    arsort($counts);
    $counts = array_slice($counts, 0, $topN, true);

    $out = [];
    foreach ($counts as $word => $n) {
        $out[] = ['text' => (string)$word, 'value' => (int)$n];
    }
    return $out;
}