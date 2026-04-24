<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use RuntimeException;

/**
 * Data access for briefs and their articles.
 */
final class BriefRepository
{
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

    public static function addArticle(int $briefId, array $data): int
    {
        if (self::isLocked($briefId)) {
            throw new RuntimeException('Brief is sent and locked');
        }

        $order = self::nextDisplayOrder($briefId, (int)$data['section_id']);

        return Database::insert(
            'INSERT INTO brief_articles
                (brief_id, section_id, display_order, url, outlet_name, headline,
                 article_content, summary, summary_original, was_paywall_fallback)
             VALUES
                (:brief_id, :section_id, :display_order, :url, :outlet_name, :headline,
                 :article_content, :summary, :summary_original, :was_paywall_fallback)',
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM sections';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC';
        return Database::select($sql);
    }

    public static function getSectionBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM sections WHERE slug = :s LIMIT 1',
            ['s' => $slug]
        );
    }
}
