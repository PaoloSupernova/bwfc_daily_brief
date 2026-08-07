<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Journalist byline tracking.
 *
 * Identity is name-only (per the team's choice): the same person is one
 * record regardless of which outlet they write for. An article can credit
 * several journalists (many-to-many via article_journalists).
 *
 * Bylines are messy in the wild, so parseByline() is deliberately forgiving:
 * it strips "By " prefixes and trailing job titles, splits multiple authors,
 * and drops wire-service credits (PA, Reuters, …) which are not people.
 */
final class JournalistRepository
{
    /** Words that mark a comma-part as a job title rather than a name. */
    private const ROLE_WORDS = [
        'writer', 'editor', 'correspondent', 'reporter', 'journalist',
        'columnist', 'chief', 'senior', 'staff', 'sports', 'sport', 'news',
        'football', 'digital', 'content', 'analyst', 'presenter', 'head',
        'deputy', 'assistant', 'associate', 'contributor', 'pa', 'agency',
    ];

    /** Credits that are wire services / agencies, not individual authors. */
    private const WIRE_SERVICES = [
        'pa', 'pa media', 'press association', 'pa sport', 'reuters',
        'afp', 'associated press', 'ap', 'newsquest', 'staff reporter',
        'staff writer', 'newsdesk', 'sports desk', 'our reporter',
    ];

    // ──────────────────────────────────────────────────────────────
    // Parsing
    // ──────────────────────────────────────────────────────────────

    /**
     * Turn a raw byline string into a list of clean journalist names.
     * Returns [] when there is no usable human name (unattributed / wire copy).
     *
     * @return array<int, string>
     */
    public static function parseByline(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $s = trim($raw);
        if ($s === '') {
            return [];
        }

        // Drop a leading "By " / "Words by " / "Report by " etc.
        $s = preg_replace('/^\s*(words\s+|report\s+|written\s+|analysis\s+)?by[:\s]+/i', '', $s) ?? $s;

        // Split on the strong author separators first.
        $parts = preg_split('/\s*(?:&|\band\b|\bwith\b|;|\/|\|)\s*/i', $s) ?: [$s];

        // Commas are ambiguous (co-author vs job title). Split on them too, but
        // role-like fragments are filtered out below.
        $candidates = [];
        foreach ($parts as $part) {
            foreach (explode(',', $part) as $frag) {
                $candidates[] = $frag;
            }
        }

        $names = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $name = self::cleanName($candidate);
            if ($name === '') {
                continue;
            }
            if (self::looksLikeRole($name) || self::isWireService($name)) {
                continue;
            }
            if (!self::looksLikePersonName($name)) {
                continue;
            }
            $key = self::nameKey($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    private static function cleanName(string $s): string
    {
        $s = trim($s);
        // Strip surrounding quotes/brackets and trailing punctuation.
        $s = trim($s, " \t\n\r\0\x0B\"'()[]{}.-–—•|");
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private static function looksLikeRole(string $name): bool
    {
        $lower = mb_strtolower($name);
        foreach (self::ROLE_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $lower)) {
                return true;
            }
        }
        return false;
    }

    private static function isWireService(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), self::WIRE_SERVICES, true);
    }

    /**
     * A plausible person name: 1–4 words, letters/hyphens/apostrophes/dots only,
     * no digits, and at least a couple of characters.
     */
    private static function looksLikePersonName(string $name): bool
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return false;
        }
        if (preg_match('/\d/', $name)) {
            return false;
        }
        if (!preg_match("/^[\p{L}][\p{L}\s.'\-]+$/u", $name)) {
            return false;
        }
        $words = preg_split('/\s+/', $name) ?: [];
        return count($words) >= 1 && count($words) <= 4;
    }

    /** Normalised identity key: lowercased, punctuation stripped, spaces collapsed. */
    public static function nameKey(string $name): string
    {
        $k = mb_strtolower(trim($name));
        $k = preg_replace("/[.'\-]/u", ' ', $k) ?? $k;   // treat O'Neil / O-Neil / O.Neil alike
        $k = preg_replace('/[^\p{L}\s]/u', '', $k) ?? $k;
        $k = preg_replace('/\s+/', ' ', $k) ?? $k;
        return trim($k);
    }

    // ──────────────────────────────────────────────────────────────
    // Persistence
    // ──────────────────────────────────────────────────────────────

    /**
     * Find or create a journalist by name, updating their most-recent outlet.
     * Returns the journalist id.
     */
    public static function upsertByName(string $name, ?string $outlet = null): int
    {
        $key = self::nameKey($name);
        if ($key === '') {
            return 0;
        }

        $existing = Database::selectOne(
            'SELECT id FROM journalists WHERE name_key = :k LIMIT 1',
            ['k' => $key]
        );

        if ($existing !== null) {
            $id = (int)$existing['id'];
            if ($outlet !== null && $outlet !== '') {
                Database::execute(
                    'UPDATE journalists SET last_outlet = :o WHERE id = :id',
                    ['o' => $outlet, 'id' => $id]
                );
            }
            return $id;
        }

        return Database::insert(
            'INSERT INTO journalists (name, name_key, last_outlet) VALUES (:n, :k, :o)',
            ['n' => $name, 'k' => $key, 'o' => ($outlet !== '' ? $outlet : null)]
        );
    }

    /**
     * Re-derive an article's journalist links from a raw byline string.
     * Clears any existing links for the article, then re-creates them.
     * Also stores the raw byline on the article for reference.
     *
     * @return array<int, string> the clean names that were linked
     */
    public static function syncArticleByline(int $articleId, ?string $rawByline, ?string $outlet = null): array
    {
        if ($articleId <= 0) {
            return [];
        }

        // Persist the raw byline (or null) on the article.
        Database::execute(
            'UPDATE brief_articles SET byline_raw = :b WHERE id = :id',
            ['b' => ($rawByline !== null && trim($rawByline) !== '') ? trim($rawByline) : null, 'id' => $articleId]
        );

        // Reset existing links so edits are idempotent.
        Database::execute('DELETE FROM article_journalists WHERE article_id = :id', ['id' => $articleId]);

        $names = self::parseByline($rawByline);
        foreach ($names as $name) {
            $journalistId = self::upsertByName($name, $outlet);
            if ($journalistId > 0) {
                Database::execute(
                    'INSERT IGNORE INTO article_journalists (article_id, journalist_id) VALUES (:a, :j)',
                    ['a' => $articleId, 'j' => $journalistId]
                );
            }
        }

        return $names;
    }

    /** Comma-joined display of an article's linked journalists (for lists/exports). */
    public static function bylineForArticle(int $articleId): string
    {
        $rows = Database::select(
            'SELECT j.name FROM article_journalists aj
             JOIN journalists j ON j.id = aj.journalist_id
             WHERE aj.article_id = :id
             ORDER BY j.name ASC',
            ['id' => $articleId]
        );
        return implode(', ', array_map(static fn($r) => (string)$r['name'], $rows));
    }

    // ──────────────────────────────────────────────────────────────
    // Dashboard stats
    // ──────────────────────────────────────────────────────────────

    /**
     * Leaderboard of journalists over a date range, counting only articles in
     * sections flagged counts_for_journalists. Excludes deleted briefs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function leaderboard(string $fromDate, string $toDate): array
    {
        return Database::select(
            "SELECT j.id, j.name, j.last_outlet,
                    COUNT(DISTINCT a.id) AS article_count,
                    SUM(a.sentiment = 'positive') AS positive,
                    SUM(a.sentiment = 'neutral')  AS neutral,
                    SUM(a.sentiment = 'negative') AS negative,
                    SUM(a.sentiment IS NULL)      AS untagged
             FROM journalists j
             JOIN article_journalists aj ON aj.journalist_id = j.id
             JOIN brief_articles a ON a.id = aj.article_id
             JOIN sections s ON s.id = a.section_id
             JOIN briefs b ON b.id = a.brief_id
             WHERE s.counts_for_journalists = 1
               AND b.deleted_at IS NULL
               AND b.brief_date BETWEEN :from AND :to
             GROUP BY j.id, j.name, j.last_outlet
             ORDER BY article_count DESC, positive DESC, j.name ASC",
            ['from' => $fromDate, 'to' => $toDate]
        );
    }

    /**
     * The "Unassigned" bucket: articles in relevant sections and date range
     * that have no linked journalist.
     *
     * @return array<string, int>
     */
    public static function unassignedTally(string $fromDate, string $toDate): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS article_count,
                    SUM(a.sentiment = 'positive') AS positive,
                    SUM(a.sentiment = 'neutral')  AS neutral,
                    SUM(a.sentiment = 'negative') AS negative,
                    SUM(a.sentiment IS NULL)      AS untagged
             FROM brief_articles a
             JOIN sections s ON s.id = a.section_id
             JOIN briefs b ON b.id = a.brief_id
             LEFT JOIN article_journalists aj ON aj.article_id = a.id
             WHERE s.counts_for_journalists = 1
               AND b.deleted_at IS NULL
               AND b.brief_date BETWEEN :from AND :to
               AND aj.article_id IS NULL",
            ['from' => $fromDate, 'to' => $toDate]
        );

        return [
            'article_count' => (int)($row['article_count'] ?? 0),
            'positive'      => (int)($row['positive'] ?? 0),
            'neutral'       => (int)($row['neutral'] ?? 0),
            'negative'      => (int)($row['negative'] ?? 0),
            'untagged'      => (int)($row['untagged'] ?? 0),
        ];
    }

    /** Sections and their current counts_for_journalists flag, for the toggles. */
    public static function sectionFlags(): array
    {
        return Database::select(
            'SELECT id, name, slug, counts_for_journalists
             FROM sections
             WHERE is_active = 1
             ORDER BY display_order ASC, name ASC'
        );
    }

    public static function setSectionFlag(int $sectionId, bool $counts): void
    {
        Database::execute(
            'UPDATE sections SET counts_for_journalists = :c WHERE id = :id',
            ['c' => $counts ? 1 : 0, 'id' => $sectionId]
        );
    }
}
