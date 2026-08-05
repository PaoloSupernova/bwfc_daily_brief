<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use RuntimeException;

/**
 * Data access for briefs, articles, and sections.
 */
final class BriefRepository
{
    // ============================================================
    // BRIEFS
    // ============================================================

    public static function createBrief(string $briefDate, bool $weekendRollup = false): int
    {
        return Database::insert(
            'INSERT INTO briefs (brief_date, is_weekend_rollup, status) VALUES (:d, :w, "draft")',
            ['d' => $briefDate, 'w' => $weekendRollup ? 1 : 0]
        );
    }

    public static function findBrief(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM briefs WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public static function findBriefByDate(string $date): ?array
    {
        return Database::selectOne(
            'SELECT * FROM briefs WHERE brief_date = :d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            ['d' => $date]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recentBriefs(int $limit = 10): array
    {
        return Database::select(
            'SELECT id, brief_date, status, sent_at, created_at,
                    (SELECT COUNT(*) FROM brief_articles WHERE brief_id = briefs.id) AS article_count
             FROM briefs
             WHERE deleted_at IS NULL
             ORDER BY brief_date DESC, id DESC
             LIMIT ' . $limit
        );
    }

    public static function updateBrief(int $id, array $fields): void
    {
        if (self::isLocked($id)) {
            throw new RuntimeException('Brief is sent and locked. Edits not permitted.');
        }

        $allowed = ['title', 'executive_summary', 'is_weekend_rollup', 'header_style'];
        $sets = [];
        $params = ['id' => $id];

        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }

        if (count($sets) === 0) {
            return;
        }

        Database::execute(
            'UPDATE briefs SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }

    public static function markSent(int $id, ?int $userId = null): void
    {
        if (self::isLocked($id)) {
            throw new RuntimeException('Brief already sent');
        }

        Database::execute(
            'UPDATE briefs SET status = "sent", sent_at = NOW(), sent_by = :u WHERE id = :id',
            ['u' => $userId, 'id' => $id]
        );
    }

    public static function isLocked(int $briefId): bool
    {
        $row = Database::selectOne(
            'SELECT status FROM briefs WHERE id = :id',
            ['id' => $briefId]
        );
        return $row !== null && $row['status'] === 'sent';
    }

    // ============================================================
    // ARTICLES
    // ============================================================

    public static function addArticle(int $briefId, array $data): int
    {
        if (self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        $order = self::nextDisplayOrder($briefId, (int)$data['section_id']);
        $parentId = isset($data['parent_article_id']) && $data['parent_article_id'] > 0
            ? (int)$data['parent_article_id']
            : null;

        $sentiment = $data['sentiment'] ?? null;
        if (!in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
            $sentiment = null;
        }

        return Database::insert(
            'INSERT INTO brief_articles
                (brief_id, section_id, display_order, url, outlet_name, headline,
                 article_content, summary, summary_original, was_paywall_fallback,
                 parent_article_id, sentiment)
             VALUES
                (:brief_id, :section_id, :display_order, :url, :outlet_name, :headline,
                 :article_content, :summary, :summary_original, :was_paywall_fallback,
                 :parent_article_id, :sentiment)',
            [
                'brief_id' => $briefId,
                'section_id' => (int)$data['section_id'],
                'display_order' => $order,
                'url' => $data['url'] ?? '',
                'outlet_name' => $data['outlet_name'] ?? 'Unknown',
                'headline' => $data['headline'] ?? '',
                'article_content' => $data['article_content'] ?? null,
                'summary' => $data['summary'] ?? '',
                'summary_original' => $data['summary_original'] ?? ($data['summary'] ?? ''),
                'was_paywall_fallback' => !empty($data['was_paywall_fallback']) ? 1 : 0,
                'parent_article_id' => $parentId,
                'sentiment' => $sentiment,
            ]
        );
    }

    public static function updateArticleSummary(int $articleId, string $summary, bool $wasEdited = true): void
    {
        $briefId = self::getArticleBriefId($articleId);
        if ($briefId !== null && self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        Database::execute(
            'UPDATE brief_articles SET summary = :s, was_edited = :e WHERE id = :id',
            ['s' => $summary, 'e' => $wasEdited ? 1 : 0, 'id' => $articleId]
        );
    }

    /**
     * Update multiple fields of an article (headline, outlet, etc).
     * @param array<string, mixed> $fields
     */
    public static function updateArticleFields(int $articleId, array $fields): void
    {
        $briefId = self::getArticleBriefId($articleId);
        if ($briefId !== null && self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        $allowed = ['headline', 'outlet_name', 'summary', 'section_id'];
        $sets = [];
        $params = ['id' => $articleId];

        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }

        if (count($sets) === 0) return;

        Database::execute(
            'UPDATE brief_articles SET ' . implode(', ', $sets) . ', was_edited = 1 WHERE id = :id',
            $params
        );
    }

    public static function incrementRegenerateCount(int $articleId): void
    {
        Database::execute(
            'UPDATE brief_articles SET was_regenerated_count = was_regenerated_count + 1 WHERE id = :id',
            ['id' => $articleId]
        );
    }

    public static function deleteArticle(int $articleId): void
    {
        $briefId = self::getArticleBriefId($articleId);
        if ($briefId !== null && self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        Database::execute('DELETE FROM brief_articles WHERE id = :id', ['id' => $articleId]);
    }

    public static function getArticle(int $articleId): ?array
    {
        return Database::selectOne(
            'SELECT a.*, s.name AS section_name, s.slug AS section_slug
             FROM brief_articles a
             JOIN sections s ON s.id = a.section_id
             WHERE a.id = :id',
            ['id' => $articleId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articlesForBrief(int $briefId): array
    {
        return Database::select(
            'SELECT a.*, s.name AS section_name, s.slug AS section_slug, s.display_order AS section_order
             FROM brief_articles a
             JOIN sections s ON s.id = a.section_id
             WHERE a.brief_id = :id
             ORDER BY s.display_order ASC, a.display_order ASC, a.id ASC',
            ['id' => $briefId]
        );
    }

    /**
     * Returns only standalone (non-related) articles — used for duplicate detection context.
     * @return array<int, array<string, mixed>>
     */
    public static function standaloneArticlesForBrief(int $briefId): array
    {
        return Database::select(
            'SELECT a.id, a.headline, a.summary, s.name AS section_name
             FROM brief_articles a
             JOIN sections s ON s.id = a.section_id
             WHERE a.brief_id = :id AND a.parent_article_id IS NULL
             ORDER BY a.id ASC',
            ['id' => $briefId]
        );
    }

    /**
     * Check whether the exact same article URL has already appeared in an
     * earlier (non-deleted) brief, so the user can be warned before adding a
     * duplicate. The current brief is excluded so re-pasting within the same
     * editing session isn't flagged as prior coverage.
     *
     * URLs are compared in canonical form (scheme/www/trailing-slash and common
     * tracking params ignored) so trivially different links to the same article
     * still match.
     *
     * @return array{brief_id:int, brief_date:string, headline:string, status:string, url:string}|null
     *         The most recent matching brief, or null if never covered before.
     */
    public static function findPriorCoverage(string $url, int $excludeBriefId = 0): ?array
    {
        $canonical = self::canonicalUrl($url);
        if ($canonical === '') {
            return null;
        }

        // Narrow candidates with a LIKE on the host+path core (no query string),
        // then confirm an exact canonical match in PHP.
        $core = explode('?', $canonical, 2)[0];

        $rows = Database::select(
            "SELECT a.url, a.headline, a.brief_id, b.brief_date, b.status
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL
               AND a.brief_id <> :exclude
               AND a.url LIKE :like
             ORDER BY b.brief_date DESC, a.id DESC",
            ['exclude' => $excludeBriefId, 'like' => '%' . $core . '%']
        );

        foreach ($rows as $row) {
            if (self::canonicalUrl((string)$row['url']) === $canonical) {
                return [
                    'brief_id'   => (int)$row['brief_id'],
                    'brief_date' => (string)$row['brief_date'],
                    'headline'   => (string)$row['headline'],
                    'status'     => (string)$row['status'],
                    'url'        => (string)$row['url'],
                ];
            }
        }

        return null;
    }

    /**
     * Reduce a URL to a canonical comparison key: lowercased host without a
     * leading "www.", path without a trailing slash, and query string stripped
     * of common tracking params (utm_*, fbclid, gclid, etc.) then sorted.
     */
    private static function canonicalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            // Not a parseable URL — fall back to a loose normalisation.
            return rtrim(strtolower($url), '/');
        }

        $host = preg_replace('/^www\./', '', strtolower((string)$parts['host'])) ?? '';
        $path = rtrim((string)($parts['path'] ?? ''), '/');

        $query = '';
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $q);
            foreach (array_keys($q) as $k) {
                if (preg_match('/^(utm_|fbclid|gclid|mc_cid|mc_eid|igshid|ref|cmp|source)$/i', (string)$k)
                    || stripos((string)$k, 'utm_') === 0) {
                    unset($q[$k]);
                }
            }
            if (!empty($q)) {
                ksort($q);
                $query = '?' . http_build_query($q);
            }
        }

        return $host . $path . $query;
    }

    /**
     * Delete an article and cascade-delete any related coverage children.
     */
    public static function deleteArticleWithChildren(int $articleId): void
    {
        $briefId = self::getArticleBriefId($articleId);
        if ($briefId !== null && self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        Database::execute('DELETE FROM brief_articles WHERE parent_article_id = :id', ['id' => $articleId]);
        Database::execute('DELETE FROM brief_articles WHERE id = :id', ['id' => $articleId]);
    }

    /**
     * Move an article to a new position within its section or to a new section.
     * New order values are renumbered (10, 20, 30...) to keep spacing clean.
     *
     * @param array<int, int> $articleIdsInOrder Article IDs in the order they should appear
     * @param int|null $sectionId If provided, move all articles to this section
     */
    public static function reorderArticles(int $briefId, array $articleIdsInOrder, ?int $sectionId = null): void
    {
        if (self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        Database::beginTransaction();
        try {
            $order = 10;
            foreach ($articleIdsInOrder as $articleId) {
                $articleId = (int)$articleId;
                if ($sectionId !== null) {
                    Database::execute(
                        'UPDATE brief_articles SET section_id = :s, display_order = :o
                         WHERE id = :id AND brief_id = :b',
                        ['s' => $sectionId, 'o' => $order, 'id' => $articleId, 'b' => $briefId]
                    );
                } else {
                    Database::execute(
                        'UPDATE brief_articles SET display_order = :o
                         WHERE id = :id AND brief_id = :b',
                        ['o' => $order, 'id' => $articleId, 'b' => $briefId]
                    );
                }
                $order += 10;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private static function nextDisplayOrder(int $briefId, int $sectionId): int
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(display_order), 0) + 10 AS next_order
             FROM brief_articles
             WHERE brief_id = :b AND section_id = :s',
            ['b' => $briefId, 's' => $sectionId]
        );
        return (int)($row['next_order'] ?? 10);
    }

    private static function getArticleBriefId(int $articleId): ?int
    {
        $row = Database::selectOne(
            'SELECT brief_id FROM brief_articles WHERE id = :id',
            ['id' => $articleId]
        );
        return $row === null ? null : (int)$row['brief_id'];
    }

    // ============================================================
    // SECTIONS
    // ============================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM sections WHERE deleted_at IS NULL';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC';
        return Database::select($sql);
    }

    public static function getSectionBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM sections WHERE slug = :s AND deleted_at IS NULL LIMIT 1',
            ['s' => $slug]
        );
    }

    public static function getSection(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM sections WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public static function createSection(string $name, string $routingDescription = ''): int
    {
        $slug = self::generateSlug($name);
        $order = (int)(Database::selectOne('SELECT COALESCE(MAX(display_order), 0) + 10 AS n FROM sections')['n'] ?? 10);

        return Database::insert(
            'INSERT INTO sections (slug, name, routing_description, display_order, is_active)
             VALUES (:s, :n, :r, :o, 1)',
            ['s' => $slug, 'n' => $name, 'r' => $routingDescription, 'o' => $order]
        );
    }

    public static function updateSection(int $id, string $name, string $routingDescription): void
    {
        Database::execute(
            'UPDATE sections SET name = :n, routing_description = :r WHERE id = :id',
            ['n' => $name, 'r' => $routingDescription, 'id' => $id]
        );
    }

    public static function countArticlesInSection(int $sectionId): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n FROM brief_articles WHERE section_id = :s',
            ['s' => $sectionId]
        );
        return (int)($row['n'] ?? 0);
    }

    /**
     * Soft-delete a section. If articles exist, they're left in the now-hidden section
     * (past briefs still render them); only new article creation to this section is blocked.
     */
    public static function deleteSection(int $id): void
    {
        Database::execute(
            'UPDATE sections SET deleted_at = NOW(), is_active = 0 WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * @param array<int, int> $sectionIdsInOrder Section IDs in desired order
     */
    public static function reorderSections(array $sectionIdsInOrder): void
    {
        Database::beginTransaction();
        try {
            $order = 10;
            foreach ($sectionIdsInOrder as $id) {
                Database::execute(
                    'UPDATE sections SET display_order = :o WHERE id = :id',
                    ['o' => $order, 'id' => (int)$id]
                );
                $order += 10;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private static function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'section_' . time();
        }

        // Ensure uniqueness
        $base = $slug;
        $i = 2;
        while (Database::selectOne('SELECT id FROM sections WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $base . '_' . $i++;
        }

        return $slug;
    }
}
