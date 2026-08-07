<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * People (players / staff / execs) mention tracking.
 *
 * Mirrors JournalistRepository but for the *subjects* of coverage. Detection is
 * deterministic against a maintained known-people list (accent- and hyphen-
 * folded so "Ruben Rodrigues" matches "Rúben Rodrigues" and "Chris Forino"
 * matches "Chris Forino-Joseph"). Names not on the list can be added by hand on
 * the review page and are stored as role='other'.
 */
final class PeopleRepository
{
    // ──────────────────────────────────────────────────────────────
    // Normalisation
    // ──────────────────────────────────────────────────────────────

    /** Folded identity key: accents removed, lowercased, punctuation → space. */
    public static function foldKey(string $s): string
    {
        $s = self::foldAccents($s);
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\s]/u', ' ', $s) ?? $s;   // hyphens/apostrophes/dots → space
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private static function foldAccents(string $s): string
    {
        if (class_exists('\Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) {
                $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
            }
        }
        // Explicit fallback for the common Latin accented characters.
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ā'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o','ō'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
            'ñ'=>'n','ç'=>'c','ß'=>'ss','ý'=>'y','ÿ'=>'y',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        ];
        return strtr($s, $map);
    }

    // ──────────────────────────────────────────────────────────────
    // Detection
    // ──────────────────────────────────────────────────────────────

    /**
     * Find known people named in a block of text (headline + summary + body).
     * Matches full names and aliases as whole words against the folded text.
     *
     * @return array<int, int> matched person ids
     */
    public static function detectInText(string $text): array
    {
        $folded = ' ' . self::foldKey($text) . ' ';
        if (trim($folded) === '') {
            return [];
        }

        $people = Database::select('SELECT id, name_key, aliases FROM people WHERE active = 1');
        $hits = [];

        foreach ($people as $p) {
            $phrases = [(string)$p['name_key']];
            if (!empty($p['aliases'])) {
                foreach (explode(',', (string)$p['aliases']) as $alias) {
                    $ak = self::foldKey($alias);
                    if ($ak !== '') {
                        $phrases[] = $ak;
                    }
                }
            }
            foreach ($phrases as $phrase) {
                if ($phrase === '') {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/u', $folded)) {
                    $hits[(int)$p['id']] = true;
                    break;
                }
            }
        }

        return array_keys($hits);
    }

    /**
     * Auto-detect known people in an article and store the links (replacing any
     * existing links). Returns the linked names.
     *
     * @return array<int, string> names linked
     */
    public static function autoDetectForArticle(int $articleId, string $text): array
    {
        $ids = self::detectInText($text);
        self::setArticlePeople($articleId, $ids, 'known');
        return self::namesForIds($ids);
    }

    /** Comma-joined names of the known people detected in a block of text. */
    public static function detectNames(string $text): string
    {
        return implode(', ', self::namesForIds(self::detectInText($text)));
    }

    // ──────────────────────────────────────────────────────────────
    // Manual attribution
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve a free-text "people mentioned" string (comma / "and" separated)
     * to person ids, creating role='other' records for names not already known.
     *
     * @return array<int, int> person ids
     */
    public static function resolveNames(?string $raw): array
    {
        $ids = [];
        foreach (self::splitNames($raw) as $name) {
            $id = self::upsertByName($name);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Re-derive an article's people links from a free-text field (used by the
     * review page). Replaces all existing links with the resolved set.
     *
     * @return array<int, string> names linked
     */
    public static function syncArticlePeople(int $articleId, ?string $raw): array
    {
        $ids = self::resolveNames($raw);
        self::setArticlePeople($articleId, $ids, 'manual');
        return self::namesForIds($ids);
    }

    /** @return array<int, string> */
    private static function splitNames(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:,|;|&|\band\b|\/)\s*/i', $raw) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $n = trim($part, " \t\n\r\0\x0B\"'.-");
            $n = preg_replace('/\s+/', ' ', $n) ?? $n;
            if ($n !== '' && preg_match("/^[\p{L}][\p{L}\s.'\-]+$/u", $n)) {
                $names[] = $n;
            }
        }
        return $names;
    }

    public static function upsertByName(string $name, string $role = 'other'): int
    {
        $key = self::foldKey($name);
        if ($key === '') {
            return 0;
        }

        // Match against name_key or any alias.
        $existing = Database::selectOne(
            'SELECT id FROM people
             WHERE name_key = :k
                OR FIND_IN_SET(:k2, REPLACE(LOWER(COALESCE(aliases, "")), ", ", ",")) > 0
             LIMIT 1',
            ['k' => $key, 'k2' => $key]
        );
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        return Database::insert(
            'INSERT INTO people (name, name_key, role, is_known) VALUES (:n, :k, :r, 0)',
            ['n' => $name, 'k' => $key, 'r' => $role]
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Link storage
    // ──────────────────────────────────────────────────────────────

    /**
     * Replace an article's people links with the given ids at a confidence level.
     *
     * @param array<int, int> $personIds
     */
    public static function setArticlePeople(int $articleId, array $personIds, string $confidence = 'known'): void
    {
        if ($articleId <= 0) {
            return;
        }
        Database::execute('DELETE FROM article_people WHERE article_id = :id', ['id' => $articleId]);
        foreach (array_unique($personIds) as $pid) {
            if ((int)$pid <= 0) {
                continue;
            }
            Database::execute(
                'INSERT IGNORE INTO article_people (article_id, person_id, confidence)
                 VALUES (:a, :p, :c)',
                ['a' => $articleId, 'p' => (int)$pid, 'c' => $confidence]
            );
        }
    }

    /** @param array<int,int> $ids @return array<int,string> */
    private static function namesForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::select("SELECT name FROM people WHERE id IN ($in) ORDER BY name ASC");
        return array_map(static fn($r) => (string)$r['name'], $rows);
    }

    /** Comma-joined display of an article's linked people. */
    public static function peopleForArticle(int $articleId): string
    {
        $rows = Database::select(
            'SELECT p.name FROM article_people ap
             JOIN people p ON p.id = ap.person_id
             WHERE ap.article_id = :id
             ORDER BY p.name ASC',
            ['id' => $articleId]
        );
        return implode(', ', array_map(static fn($r) => (string)$r['name'], $rows));
    }

    // ──────────────────────────────────────────────────────────────
    // Dashboard
    // ──────────────────────────────────────────────────────────────

    /**
     * Leaderboard of people over a date range. Counts mentions across ALL
     * sections (a squad player is newsworthy wherever named). Excludes deleted
     * briefs. Optional role filter ('player'|'staff'|'exec'|'other').
     *
     * @return array<int, array<string, mixed>>
     */
    public static function leaderboard(string $fromDate, string $toDate, ?string $role = null): array
    {
        $params = ['from' => $fromDate, 'to' => $toDate];
        $roleClause = '';
        if ($role !== null && in_array($role, ['player', 'staff', 'exec', 'other'], true)) {
            $roleClause = ' AND p.role = :role';
            $params['role'] = $role;
        }

        return Database::select(
            "SELECT p.id, p.name, p.role, p.is_known,
                    COUNT(DISTINCT a.id) AS mention_count,
                    SUM(a.sentiment = 'positive') AS positive,
                    SUM(a.sentiment = 'neutral')  AS neutral,
                    SUM(a.sentiment = 'negative') AS negative,
                    SUM(a.sentiment IS NULL)      AS untagged
             FROM people p
             JOIN article_people ap ON ap.person_id = p.id
             JOIN brief_articles a ON a.id = ap.article_id
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL
               AND b.brief_date BETWEEN :from AND :to
               {$roleClause}
             GROUP BY p.id, p.name, p.role, p.is_known
             ORDER BY mention_count DESC, positive DESC, p.name ASC",
            $params
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Admin CRUD (squad management)
    // ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    public static function all(bool $knownOnly = false): array
    {
        $where = $knownOnly ? 'WHERE is_known = 1' : '';
        return Database::select(
            "SELECT id, name, name_key, role, is_known, aliases, active
             FROM people {$where}
             ORDER BY is_known DESC,
                      FIELD(role, 'player','staff','exec','other'),
                      name ASC"
        );
    }

    public static function create(string $name, string $role, string $aliases = ''): int
    {
        $key = self::foldKey($name);
        if ($key === '') {
            return 0;
        }
        $existing = Database::selectOne('SELECT id FROM people WHERE name_key = :k LIMIT 1', ['k' => $key]);
        if ($existing !== null) {
            // Promote an existing (e.g. AI-caught) record to a known squad member.
            self::update((int)$existing['id'], $name, $role, $aliases, true, true);
            return (int)$existing['id'];
        }
        return Database::insert(
            'INSERT INTO people (name, name_key, role, is_known, aliases, active)
             VALUES (:n, :k, :r, 1, :a, 1)',
            ['n' => $name, 'k' => $key, 'r' => $role, 'a' => ($aliases !== '' ? $aliases : null)]
        );
    }

    public static function update(int $id, string $name, string $role, string $aliases, bool $active, bool $isKnown = true): void
    {
        Database::execute(
            'UPDATE people SET name = :n, name_key = :k, role = :r, aliases = :a, active = :ac, is_known = :ik
             WHERE id = :id',
            [
                'n' => $name,
                'k' => self::foldKey($name),
                'r' => $role,
                'a' => ($aliases !== '' ? $aliases : null),
                'ac' => $active ? 1 : 0,
                'ik' => $isKnown ? 1 : 0,
                'id' => $id,
            ]
        );
    }

    public static function delete(int $id): void
    {
        // article_people rows cascade via FK.
        Database::execute('DELETE FROM people WHERE id = :id', ['id' => $id]);
    }
}
