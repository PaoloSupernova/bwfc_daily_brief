<?php
/**
 * POST /api/archive_search.php
 *
 * Body: {
 *   query?: string,
 *   sections?: [slug, ...],
 *   outlets?: [name, ...],
 *   date_from?: 'YYYY-MM-DD',
 *   date_to?: 'YYYY-MM-DD',
 *   status?: 'sent' | 'draft' | null,
 *   page?: int (1-based, default 1),
 *   per_page?: int (default 20, max 100)
 * }
 *
 * Returns:
 *   - When query is empty: brief list grouped by month
 *   - When query is set: search hits with snippet highlighting
 *
 * Plus aggregations: facet counts for the filter sidebar.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\Database;

$input = api_input();
$query = trim((string)($input['query'] ?? ''));
$sections = is_array($input['sections'] ?? null) ? array_values(array_filter(array_map('strval', $input['sections']))) : [];
$outlets = is_array($input['outlets'] ?? null) ? array_values(array_filter(array_map('strval', $input['outlets']))) : [];
$dateFrom = trim((string)($input['date_from'] ?? ''));
$dateTo = trim((string)($input['date_to'] ?? ''));
$status = trim((string)($input['status'] ?? ''));
$page = max(1, (int)($input['page'] ?? 1));
$perPage = min(100, max(1, (int)($input['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

// Validate dates (skip silently if malformed)
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';
if ($status !== '' && !in_array($status, ['sent', 'draft'], true)) $status = '';

// ============================================================
// Build the WHERE clause for both search and list modes
// ============================================================

$where = ['b.deleted_at IS NULL'];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'b.brief_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'b.brief_date <= :date_to';
    $params['date_to'] = $dateTo;
}
if ($status !== '') {
    $where[] = 'b.status = :status';
    $params['status'] = $status;
}

// Section/outlet filtering: requires joining brief_articles
$articleJoinNeeded = count($sections) > 0 || count($outlets) > 0 || $query !== '';

if (count($sections) > 0) {
    $placeholders = [];
    foreach ($sections as $i => $slug) {
        $placeholders[] = ':section_' . $i;
        $params['section_' . $i] = $slug;
    }
    $where[] = 'EXISTS (SELECT 1 FROM brief_articles ba JOIN sections s ON s.id = ba.section_id WHERE ba.brief_id = b.id AND s.slug IN (' . implode(',', $placeholders) . '))';
}

if (count($outlets) > 0) {
    $placeholders = [];
    foreach ($outlets as $i => $name) {
        $placeholders[] = ':outlet_' . $i;
        $params['outlet_' . $i] = $name;
    }
    $where[] = 'EXISTS (SELECT 1 FROM brief_articles ba WHERE ba.brief_id = b.id AND ba.outlet_name IN (' . implode(',', $placeholders) . '))';
}

$whereSql = implode(' AND ', $where);

// ============================================================
// MODE 1: Search query is set → return matching articles with snippets
// ============================================================

if ($query !== '') {
    $searchParams = $params;
    $searchParams['q'] = $query;
    $searchParams['q_like'] = '%' . $query . '%';

    // Use FULLTEXT for relevance ranking; LIKE as fallback for short queries (FULLTEXT needs 4+ chars)
    $useFulltext = strlen($query) >= 4;

    $matchSql = $useFulltext
        ? "(MATCH(a.headline, a.summary, a.article_content) AGAINST (:q IN NATURAL LANGUAGE MODE)
           OR a.headline LIKE :q_like
           OR a.summary LIKE :q_like
           OR a.outlet_name LIKE :q_like)"
        : "(a.headline LIKE :q_like OR a.summary LIKE :q_like OR a.outlet_name LIKE :q_like)";

    // Total count of matching articles
    $countSql = "
        SELECT COUNT(*) AS total
        FROM brief_articles a
        JOIN briefs b ON b.id = a.brief_id
        WHERE {$whereSql} AND {$matchSql}
    ";
    $totalRow = Database::selectOne($countSql, $searchParams);
    $total = (int)($totalRow['total'] ?? 0);

    // Result rows: article + brief metadata, ordered by brief date desc
    $listSql = "
        SELECT
            a.id AS article_id,
            a.brief_id,
            a.headline,
            a.summary,
            a.url,
            a.outlet_name,
            s.name AS section_name,
            s.slug AS section_slug,
            b.brief_date,
            b.status AS brief_status,
            b.sent_at
        FROM brief_articles a
        JOIN briefs b ON b.id = a.brief_id
        JOIN sections s ON s.id = a.section_id
        WHERE {$whereSql} AND {$matchSql}
        ORDER BY b.brief_date DESC, a.display_order ASC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $hits = Database::select($listSql, $searchParams);

    // Format hits: include a snippet centred on the search term
    $results = array_map(function ($row) use ($query) {
        $row['snippet'] = build_snippet((string)$row['summary'], $query, 240);
        $row['headline_match'] = stripos((string)$row['headline'], $query) !== false;
        $row['article_id'] = (int)$row['article_id'];
        $row['brief_id'] = (int)$row['brief_id'];
        return $row;
    }, $hits);

    api_success([
        'mode' => 'search',
        'query' => $query,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
        'results' => $results,
        'facets' => archive_facets($where, $params),
    ]);
}

// ============================================================
// MODE 2: No search → brief list grouped by month
// ============================================================

$totalRow = Database::selectOne("SELECT COUNT(*) AS total FROM briefs b WHERE {$whereSql}", $params);
$total = (int)($totalRow['total'] ?? 0);

$listSql = "
    SELECT
        b.id,
        b.brief_date,
        b.status,
        b.sent_at,
        b.created_at,
        (SELECT COUNT(*) FROM brief_articles WHERE brief_id = b.id) AS article_count,
        (SELECT GROUP_CONCAT(DISTINCT outlet_name ORDER BY outlet_name SEPARATOR '|')
         FROM brief_articles WHERE brief_id = b.id) AS outlets
    FROM briefs b
    WHERE {$whereSql}
    ORDER BY b.brief_date DESC, b.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$briefs = Database::select($listSql, $params);

// Format briefs: split outlet pipe-list, parse dates
foreach ($briefs as &$brief) {
    $outletList = array_filter(explode('|', (string)($brief['outlets'] ?? '')));
    $brief['outlet_list'] = array_values(array_slice($outletList, 0, 3));
    $brief['outlet_total'] = count($outletList);
    $brief['id'] = (int)$brief['id'];
    $brief['article_count'] = (int)$brief['article_count'];
    unset($brief['outlets']);
}
unset($brief);

// Group by year-month
$grouped = [];
foreach ($briefs as $brief) {
    $month = substr((string)$brief['brief_date'], 0, 7); // YYYY-MM
    $grouped[$month] = $grouped[$month] ?? [];
    $grouped[$month][] = $brief;
}

$groups = [];
foreach ($grouped as $month => $items) {
    $dt = \DateTime::createFromFormat('Y-m', $month);
    $groups[] = [
        'month_key' => $month,
        'month_label' => $dt ? $dt->format('F Y') : $month,
        'count' => count($items),
        'briefs' => $items,
    ];
}

api_success([
    'mode' => 'list',
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / $perPage)),
    'groups' => $groups,
    'facets' => archive_facets($where, $params),
]);

// ============================================================
// HELPERS
// ============================================================

/**
 * Build a snippet of $text centred on $query, max $maxLen characters.
 * Marks the matched substring with <<<...>>> sentinels so the JS layer
 * can render highlights without HTML injection.
 */
function build_snippet(string $text, string $query, int $maxLen): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    $pos = stripos($text, $query);

    if ($pos === false) {
        // No direct match (FULLTEXT might match stems); return start of text
        return strlen($text) > $maxLen ? substr($text, 0, $maxLen) . '...' : $text;
    }

    $contextLen = (int)(($maxLen - strlen($query)) / 2);
    $start = max(0, $pos - $contextLen);
    $end = min(strlen($text), $pos + strlen($query) + $contextLen);

    $snippet = substr($text, $start, $end - $start);
    if ($start > 0) $snippet = '...' . $snippet;
    if ($end < strlen($text)) $snippet .= '...';

    // Mark match positions with sentinels - case-preserving
    $snippet = preg_replace('/(' . preg_quote($query, '/') . ')/i', '<<<$1>>>', $snippet) ?? $snippet;

    return $snippet;
}

/**
 * Compute facet counts for the sidebar: section counts, outlet counts, status counts.
 * Reuses the current WHERE filters (so counts reflect what's actually visible
 * given other active filters, like a faceted search).
 */
function archive_facets(array $where, array $params): array
{
    $whereSql = implode(' AND ', $where);

    // Section counts (number of briefs containing at least one article in each section)
    $sectionRows = Database::select(
        "SELECT s.slug, s.name, COUNT(DISTINCT b.id) AS n
         FROM sections s
         JOIN brief_articles a ON a.section_id = s.id
         JOIN briefs b ON b.id = a.brief_id
         WHERE {$whereSql} AND s.deleted_at IS NULL
         GROUP BY s.id, s.slug, s.name
         ORDER BY n DESC, s.name",
        $params
    );

    // Outlet counts
    $outletRows = Database::select(
        "SELECT a.outlet_name AS name, COUNT(DISTINCT b.id) AS n
         FROM brief_articles a
         JOIN briefs b ON b.id = a.brief_id
         WHERE {$whereSql}
         GROUP BY a.outlet_name
         ORDER BY n DESC, name",
        $params
    );

    // Status counts (run two simple queries, no joins)
    $sentRow = Database::selectOne(
        "SELECT COUNT(*) AS n FROM briefs b WHERE {$whereSql} AND b.status = 'sent'",
        $params
    );
    $draftRow = Database::selectOne(
        "SELECT COUNT(*) AS n FROM briefs b WHERE {$whereSql} AND b.status = 'draft'",
        $params
    );

    return [
        'sections' => array_map(fn($r) => [
            'slug' => $r['slug'],
            'name' => $r['name'],
            'count' => (int)$r['n'],
        ], $sectionRows),
        'outlets' => array_map(fn($r) => [
            'name' => $r['name'],
            'count' => (int)$r['n'],
        ], $outletRows),
        'status' => [
            'sent' => (int)($sentRow['n'] ?? 0),
            'draft' => (int)($draftRow['n'] ?? 0),
        ],
    ];
}
