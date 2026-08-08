<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Reputation alerting — early warning for the comms team.
 *
 * Computes alerts live from recent coverage (no stored state), combining the
 * sentiment, people, and topic dimensions:
 *   - an overall spike in negative coverage
 *   - a person (player / staff / exec) attracting repeated negative coverage
 *   - negative coverage clustering in a sensitive topic (ownership, discipline)
 *
 * Thresholds are conservative so alerts stay meaningful; tune the constants if
 * the team wants more or fewer.
 */
final class AlertService
{
    // Overall negative spike
    private const SPIKE_DAYS = 3;
    private const SPIKE_MIN  = 4;

    // Per-person negative coverage
    private const PERSON_DAYS = 7;
    private const PERSON_MIN  = 2;

    // Sensitive-topic negative clustering
    private const TOPIC_DAYS = 7;
    private const TOPIC_MIN  = 2;
    private const SENSITIVE_TOPICS = ['ownership', 'discipline'];

    /**
     * @return array<int, array{id:string, level:string, kind:string, title:string, detail:string, count:int}>
     */
    public static function current(): array
    {
        $alerts = [];

        // 1. Overall negative spike
        $since = date('Y-m-d', strtotime('-' . self::SPIKE_DAYS . ' days'));
        $negRecent = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date >= :since
               AND a.sentiment = 'negative' AND a.parent_article_id IS NULL",
            ['since' => $since]
        )['n'] ?? 0);

        if ($negRecent >= self::SPIKE_MIN) {
            $alerts[] = [
                'id'     => 'spike',
                'level'  => 'warning',
                'kind'   => 'spike',
                'title'  => 'Negative coverage spike',
                'detail' => "{$negRecent} negative articles in the last " . self::SPIKE_DAYS . " days.",
                'count'  => $negRecent,
            ];
        }

        // 2. Per-person negative coverage
        $psince = date('Y-m-d', strtotime('-' . self::PERSON_DAYS . ' days'));
        $people = Database::select(
            "SELECT p.name, p.role, COUNT(DISTINCT a.id) AS neg
             FROM people p
             JOIN article_people ap ON ap.person_id = p.id
             JOIN brief_articles a ON a.id = ap.article_id
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date >= :since
               AND a.sentiment = 'negative'
             GROUP BY p.id, p.name, p.role
             HAVING neg >= :min
             ORDER BY neg DESC",
            ['since' => $psince, 'min' => self::PERSON_MIN]
        );
        foreach ($people as $p) {
            $roleLabel = ['player' => 'Player', 'staff' => 'Staff', 'exec' => 'Executive', 'other' => ''][$p['role']] ?? '';
            $who = $roleLabel !== '' ? "{$roleLabel} {$p['name']}" : (string)$p['name'];
            $alerts[] = [
                'id'     => 'person:' . self::slug((string)$p['name']),
                'level'  => ($p['role'] === 'exec') ? 'warning' : 'info',
                'kind'   => 'person',
                'title'  => 'Negative coverage: ' . $p['name'],
                'detail' => "{$who} appeared in " . (int)$p['neg'] . " negative articles in the last " . self::PERSON_DAYS . " days.",
                'count'  => (int)$p['neg'],
            ];
        }

        // 3. Sensitive-topic negative clustering
        $tsince = date('Y-m-d', strtotime('-' . self::TOPIC_DAYS . ' days'));
        $placeholders = [];
        $params = ['since' => $tsince, 'min' => self::TOPIC_MIN];
        foreach (self::SENSITIVE_TOPICS as $i => $t) {
            $key = 'topic' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $t;
        }
        $topicRows = Database::select(
            "SELECT a.topic, COUNT(*) AS neg
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date >= :since
               AND a.sentiment = 'negative' AND a.parent_article_id IS NULL
               AND a.topic IN (" . implode(', ', $placeholders) . ")
             GROUP BY a.topic
             HAVING neg >= :min
             ORDER BY neg DESC",
            $params
        );
        foreach ($topicRows as $t) {
            $alerts[] = [
                'id'     => 'topic:' . (string)$t['topic'],
                'level'  => 'warning',
                'kind'   => 'topic',
                'title'  => 'Negative ' . Topics::label((string)$t['topic']) . ' coverage',
                'detail' => (int)$t['neg'] . " negative articles on " . Topics::label((string)$t['topic'])
                          . " in the last " . self::TOPIC_DAYS . " days.",
                'count'  => (int)$t['neg'],
            ];
        }

        // Warnings first, then by count.
        usort($alerts, static function ($a, $b) {
            if ($a['level'] !== $b['level']) {
                return $a['level'] === 'warning' ? -1 : 1;
            }
            return $b['count'] <=> $a['count'];
        });

        return $alerts;
    }

    private static function slug(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
