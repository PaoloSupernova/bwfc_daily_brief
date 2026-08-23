<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Story tracker — saved narratives whose timeline and stats are computed live
 * from the article archive using the existing FULLTEXT index. Follows a running
 * story across days: how long it's run, volume, local vs national spread,
 * sentiment trend and the latest coverage.
 */
final class StoryTracker
{
    private const LOCAL_OUTLETS = [
        'bwfc.co.uk', 'Bolton News', 'Lancashire Evening Post',
        'Manchester Evening News', 'BWitC', 'Bolton Stadium Hotel',
    ];

    // ──────────────────────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT id, title, keywords, is_active FROM tracked_stories ORDER BY is_active DESC, title ASC'
        );
    }

    public static function get(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM tracked_stories WHERE id = :id', ['id' => $id]);
    }

    public static function create(string $title, string $keywords): int
    {
        return Database::insert(
            'INSERT INTO tracked_stories (title, keywords) VALUES (:t, :k)',
            ['t' => $title, 'k' => $keywords]
        );
    }

    public static function update(int $id, string $title, string $keywords, bool $active): void
    {
        Database::execute(
            'UPDATE tracked_stories SET title = :t, keywords = :k, is_active = :a WHERE id = :id',
            ['t' => $title, 'k' => $keywords, 'a' => $active ? 1 : 0, 'id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM tracked_stories WHERE id = :id', ['id' => $id]);
    }

    // ──────────────────────────────────────────────────────────────
    // Matching + stats
    // ──────────────────────────────────────────────────────────────

    /**
     * Matching articles for a keyword string, newest first.
     * @return array<int, array<string,mixed>>
     */
    public static function matches(string $keywords, int $limit = 200): array
    {
        $terms = self::booleanTerms($keywords);
        if ($terms === '') {
            return [];
        }
        return Database::select(
            "SELECT a.id, a.brief_id, a.headline, a.url, a.outlet_name, a.sentiment,
                    a.topic, b.brief_date
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL
               AND MATCH(a.headline, a.summary, a.article_content) AGAINST(:kw IN BOOLEAN MODE)
             ORDER BY b.brief_date DESC, a.id DESC
             LIMIT " . (int)$limit,
            ['kw' => $terms]
        );
    }

    /**
     * Compute headline stats for a story from its matches.
     * @return array<string, mixed>
     */
    public static function stats(string $keywords): array
    {
        $rows = self::matches($keywords, 500);
        $sentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $outlets = [];
        $local = 0; $national = 0;
        $firstDate = null; $lastDate = null;

        foreach ($rows as $r) {
            $s = (string)($r['sentiment'] ?? '');
            if (isset($sentiment[$s])) {
                $sentiment[$s]++;
            }
            $outlet = (string)$r['outlet_name'];
            $outlets[$outlet] = true;
            self::isLocal($outlet) ? $local++ : $national++;
            $d = (string)$r['brief_date'];
            if ($firstDate === null || $d < $firstDate) $firstDate = $d;
            if ($lastDate === null || $d > $lastDate) $lastDate = $d;
        }

        $daysRunning = 0;
        if ($firstDate !== null && $lastDate !== null) {
            $daysRunning = (int)((strtotime($lastDate) - strtotime($firstDate)) / 86400) + 1;
        }

        $latest = $rows[0] ?? null;

        return [
            'total'        => count($rows),
            'days_running' => $daysRunning,
            'first_date'   => $firstDate,
            'last_date'    => $lastDate,
            'outlets'      => count($outlets),
            'local'        => $local,
            'national'     => $national,
            'sentiment'    => $sentiment,
            'latest'       => $latest !== null ? [
                'headline'   => (string)$latest['headline'],
                'url'        => (string)$latest['url'],
                'outlet'     => (string)$latest['outlet_name'],
                'brief_date' => (string)$latest['brief_date'],
            ] : null,
        ];
    }

    private static function isLocal(string $outlet): bool
    {
        foreach (self::LOCAL_OUTLETS as $p) {
            if (stripos($outlet, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Turn a free-text keyword string into a safe BOOLEAN MODE query: each word
     * required (+word), phrases in quotes preserved. Strips FULLTEXT operators
     * that could break the query.
     */
    private static function booleanTerms(string $keywords): string
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            return '';
        }
        // Keep quoted phrases together.
        if (preg_match_all('/"[^"]+"|\S+/', $keywords, $m)) {
            $parts = [];
            foreach ($m[0] as $tok) {
                if (str_starts_with($tok, '"')) {
                    $parts[] = '+' . $tok; // required phrase
                } else {
                    $tok = preg_replace('/[+\-<>()~*"@]/', '', $tok) ?? $tok;
                    if (mb_strlen($tok) >= 2) {
                        $parts[] = '+' . $tok;
                    }
                }
            }
            return implode(' ', $parts);
        }
        return '';
    }
}
