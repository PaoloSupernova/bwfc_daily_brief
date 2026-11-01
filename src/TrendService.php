<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Trend alerting — surfaces *movement* in coverage, as distinct from the
 * reputation warnings in AlertService (which only flag negativity).
 *
 * Four signals, all computed live from recent coverage:
 *   - person mention spikes  (a player/staff/exec covered far more than usual)
 *   - topic spikes           (a topic getting far more coverage than usual)
 *   - rising negative streaks (negative coverage on a topic/person several
 *                              active days running, ongoing to the latest day)
 *   - overall volume shifts  (total coverage markedly up or down vs baseline)
 *
 * "Usual" is a smoothed weekly baseline: the count over the 3 weeks *before*
 * the recent 7-day window, divided by three. This is steadier than comparing
 * one week to the single week before it.
 *
 * current() returns every trend item (for the dashboard panel). The strongest
 * one or two are flagged banner=true so the dashboard can also push them into
 * the morning alert banner.
 *
 * Every query is best-effort: a missing people/topic table or an untagged
 * backlog yields fewer trends, never a 500.
 */
final class TrendService
{
    private const RECENT_DAYS   = 7;
    private const BASELINE_DAYS = 21;          // 3 weeks before the recent window
    private const BASELINE_WEEKS = 3;

    private const SPIKE_RATIO   = 2.0;         // recent must be >= this x the weekly baseline
    private const PERSON_MIN    = 3;           // min recent mentions to consider a person
    private const PERSON_NEW_FLOOR = 4;        // min recent mentions to flag someone ~never covered before
    private const TOPIC_MIN     = 4;           // min recent articles to consider a topic

    private const VOLUME_MIN       = 6;        // floor so tiny samples don't trigger
    private const VOLUME_RATIO_UP  = 1.5;
    private const VOLUME_RATIO_DOWN = 0.67;    // recent <= baseline x this => "down"

    private const STREAK_MIN      = 3;         // active days in a row
    private const STREAK_LOOKBACK = 21;        // days to scan for a trailing streak

    private const BANNER_MAX = 2;              // strongest trends pushed to the morning banner

    /**
     * @return array<int, array{id:string,kind:string,level:string,direction:string,title:string,detail:string,metric:string,count:int,score:float,banner:bool}>
     */
    public static function current(): array
    {
        $recentStart   = date('Y-m-d', strtotime('-' . self::RECENT_DAYS . ' days'));
        $baselineStart = date('Y-m-d', strtotime('-' . (self::RECENT_DAYS + self::BASELINE_DAYS) . ' days'));

        $items = [];
        $items = array_merge($items, self::personSpikes($recentStart, $baselineStart));
        $items = array_merge($items, self::topicSpikes($recentStart, $baselineStart));
        $items = array_merge($items, self::negativeStreaks());
        $items = array_merge($items, self::volumeShift($recentStart, $baselineStart));

        // Warnings first, then by score.
        usort($items, static function ($a, $b) {
            if ($a['level'] !== $b['level']) {
                return $a['level'] === 'warning' ? -1 : 1;
            }
            return $b['score'] <=> $a['score'];
        });

        // Flag the strongest one or two (that clear a meaningful bar) for the banner.
        $bannerCount = 0;
        foreach ($items as $i => $item) {
            if ($bannerCount >= self::BANNER_MAX) break;
            if (self::bannerWorthy($item)) {
                $items[$i]['banner'] = true;
                $bannerCount++;
            }
        }

        return $items;
    }

    private static function bannerWorthy(array $item): bool
    {
        if ($item['kind'] === 'streak') return true;               // ongoing negative run — always worth it
        if ($item['level'] === 'warning') return true;
        if ($item['kind'] === 'person' && $item['score'] >= 3.0) return true;
        if ($item['kind'] === 'topic'  && $item['score'] >= 3.0) return true;
        if ($item['kind'] === 'volume' && $item['score'] >= 2.0) return true;
        return false;
    }

    // ============================================================
    // 1. Person mention spikes
    // ============================================================

    private static function personSpikes(string $recentStart, string $baselineStart): array
    {
        try {
            $rows = Database::select(
                "SELECT p.id, p.name, p.role,
                        SUM(b.brief_date >= :recentStart) AS recent,
                        SUM(b.brief_date <  :recentStart2) AS baseline
                 FROM people p
                 JOIN article_people ap ON ap.person_id = p.id
                 JOIN brief_articles a ON a.id = ap.article_id
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :baselineStart
                 GROUP BY p.id, p.name, p.role",
                ['recentStart' => $recentStart, 'recentStart2' => $recentStart, 'baselineStart' => $baselineStart]
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $recent = (int)$r['recent'];
            if ($recent < self::PERSON_MIN) continue;

            $baselineWeekly = (int)$r['baseline'] / self::BASELINE_WEEKS;
            $isNew = $baselineWeekly < 0.75;

            if ($isNew) {
                if ($recent < self::PERSON_NEW_FLOOR) continue;
                $ratio  = (float)$recent; // for scoring
                $metric = 'new';
                $detail = "Mentioned in {$recent} articles this week, having barely featured before.";
            } else {
                $ratio = $recent / $baselineWeekly;
                if ($ratio < self::SPIKE_RATIO) continue;
                $metric = self::fmtRatio($ratio);
                $detail = "Mentioned in {$recent} articles this week — {$metric} the usual ("
                        . self::fmtRate($baselineWeekly) . "/week lately).";
            }

            $role = (string)$r['role'];
            $out[] = [
                'id'        => 'trend:person:' . self::slug((string)$r['name']),
                'kind'      => 'person',
                'level'     => $role === 'exec' ? 'warning' : 'info',
                'direction' => 'up',
                'title'     => $r['name'] . ' coverage climbing',
                'detail'    => $detail,
                'metric'    => $metric === 'new' ? 'New' : $metric,
                'count'     => $recent,
                'score'     => $role === 'exec' ? $ratio + 2.0 : $ratio,
                'banner'    => false,
            ];
        }
        return $out;
    }

    // ============================================================
    // 2. Topic spikes
    // ============================================================

    private static function topicSpikes(string $recentStart, string $baselineStart): array
    {
        try {
            $rows = Database::select(
                "SELECT a.topic,
                        SUM(b.brief_date >= :recentStart) AS recent,
                        SUM(b.brief_date <  :recentStart2) AS baseline
                 FROM brief_articles a
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :baselineStart
                   AND a.topic IS NOT NULL AND a.parent_article_id IS NULL
                 GROUP BY a.topic",
                ['recentStart' => $recentStart, 'recentStart2' => $recentStart, 'baselineStart' => $baselineStart]
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $recent = (int)$r['recent'];
            if ($recent < self::TOPIC_MIN) continue;

            $baselineWeekly = (int)$r['baseline'] / self::BASELINE_WEEKS;
            if ($baselineWeekly < 0.75) {
                $ratio  = (float)$recent;
                $metric = 'New';
                $detail = "{$recent} articles this week on " . Topics::label((string)$r['topic'])
                        . ", a topic that rarely featured before.";
            } else {
                $ratio = $recent / $baselineWeekly;
                if ($ratio < self::SPIKE_RATIO) continue;
                $metric = self::fmtRatio($ratio);
                $detail = "{$recent} articles this week — {$metric} the usual ("
                        . self::fmtRate($baselineWeekly) . "/week lately).";
            }

            $out[] = [
                'id'        => 'trend:topic:' . (string)$r['topic'],
                'kind'      => 'topic',
                'level'     => 'info',
                'direction' => 'up',
                'title'     => Topics::label((string)$r['topic']) . ' coverage up',
                'detail'    => $detail,
                'metric'    => $metric,
                'count'     => $recent,
                'score'     => $ratio,
                'banner'    => false,
            ];
        }
        return $out;
    }

    // ============================================================
    // 3. Rising negative streaks (topics and people)
    // ============================================================

    private static function negativeStreaks(): array
    {
        $lookbackStart = date('Y-m-d', strtotime('-' . self::STREAK_LOOKBACK . ' days'));

        // Active days = days that had any (parent) article, newest first.
        try {
            $activeRows = Database::select(
                "SELECT DISTINCT b.brief_date AS d
                 FROM brief_articles a
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :start
                   AND a.parent_article_id IS NULL
                 ORDER BY d DESC",
                ['start' => $lookbackStart]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $activeDays = array_map(fn($r) => (string)$r['d'], $activeRows);
        if (count($activeDays) < self::STREAK_MIN) return [];

        $out = [];

        // --- Topic streaks ---
        try {
            $topicRows = Database::select(
                "SELECT b.brief_date AS d, a.topic AS topic
                 FROM brief_articles a
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :start
                   AND a.sentiment = 'negative' AND a.parent_article_id IS NULL
                   AND a.topic IS NOT NULL
                 GROUP BY b.brief_date, a.topic",
                ['start' => $lookbackStart]
            );
            $byTopic = [];
            foreach ($topicRows as $r) {
                $byTopic[(string)$r['topic']][(string)$r['d']] = true;
            }
            foreach ($byTopic as $topic => $days) {
                $streak = self::trailingStreak($activeDays, $days);
                if ($streak < self::STREAK_MIN) continue;
                $label = Topics::label($topic);
                $out[] = [
                    'id'        => 'trend:streak:topic:' . $topic,
                    'kind'      => 'streak',
                    'level'     => 'warning',
                    'direction' => 'alert',
                    'title'     => "Negative {$label} coverage — {$streak} days running",
                    'detail'    => "Negative coverage on {$label} every one of the last {$streak} days with a brief.",
                    'metric'    => "{$streak} days",
                    'count'     => $streak,
                    'score'     => 10.0 + $streak,
                    'banner'    => false,
                ];
            }
        } catch (\Throwable $e) {
            // skip topic streaks
        }

        // --- Person streaks ---
        try {
            $personRows = Database::select(
                "SELECT b.brief_date AS d, p.name AS name, p.role AS role
                 FROM people p
                 JOIN article_people ap ON ap.person_id = p.id
                 JOIN brief_articles a ON a.id = ap.article_id
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :start
                   AND a.sentiment = 'negative'
                 GROUP BY b.brief_date, p.name, p.role",
                ['start' => $lookbackStart]
            );
            $byPerson = [];
            $roleOf = [];
            foreach ($personRows as $r) {
                $byPerson[(string)$r['name']][(string)$r['d']] = true;
                $roleOf[(string)$r['name']] = (string)$r['role'];
            }
            foreach ($byPerson as $name => $days) {
                $streak = self::trailingStreak($activeDays, $days);
                if ($streak < self::STREAK_MIN) continue;
                $out[] = [
                    'id'        => 'trend:streak:person:' . self::slug($name),
                    'kind'      => 'streak',
                    'level'     => 'warning',
                    'direction' => 'alert',
                    'title'     => "Negative coverage of {$name} — {$streak} days running",
                    'detail'    => "{$name} featured in negative coverage every one of the last {$streak} days with a brief.",
                    'metric'    => "{$streak} days",
                    'count'     => $streak,
                    'score'     => 10.0 + $streak + (($roleOf[$name] ?? '') === 'exec' ? 1.0 : 0.0),
                    'banner'    => false,
                ];
            }
        } catch (\Throwable $e) {
            // skip person streaks
        }

        return $out;
    }

    /**
     * Count how many of the most-recent active days (in order, newest first)
     * carry an event, stopping at the first day that doesn't. A streak only
     * counts if it is ongoing — i.e. the latest active day has the event.
     *
     * @param array<int,string> $activeDaysDesc  distinct active dates, newest first
     * @param array<string,bool> $daysWithEvent   dates (keys) that had the event
     */
    private static function trailingStreak(array $activeDaysDesc, array $daysWithEvent): int
    {
        $streak = 0;
        foreach ($activeDaysDesc as $d) {
            if (isset($daysWithEvent[$d])) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }

    // ============================================================
    // 4. Overall volume shift
    // ============================================================

    private static function volumeShift(string $recentStart, string $baselineStart): array
    {
        try {
            $row = Database::selectOne(
                "SELECT SUM(b.brief_date >= :recentStart) AS recent,
                        SUM(b.brief_date <  :recentStart2) AS baseline
                 FROM brief_articles a
                 JOIN briefs b ON b.id = a.brief_id
                 WHERE b.deleted_at IS NULL AND b.brief_date >= :baselineStart
                   AND a.parent_article_id IS NULL",
                ['recentStart' => $recentStart, 'recentStart2' => $recentStart, 'baselineStart' => $baselineStart]
            );
        } catch (\Throwable $e) {
            return [];
        }
        if ($row === null) return [];

        $recent = (int)($row['recent'] ?? 0);
        $baselineWeekly = (int)($row['baseline'] ?? 0) / self::BASELINE_WEEKS;

        // Need a meaningful amount of data on at least one side.
        if ($recent < self::VOLUME_MIN && $baselineWeekly < self::VOLUME_MIN) return [];
        if ($baselineWeekly < 0.75) return []; // no stable baseline to compare against

        $ratio = $recent / $baselineWeekly;
        $pct = (int)round(($ratio - 1) * 100);

        if ($ratio >= self::VOLUME_RATIO_UP) {
            return [[
                'id'        => 'trend:volume:up',
                'kind'      => 'volume',
                'level'     => 'info',
                'direction' => 'up',
                'title'     => 'Coverage volume up',
                'detail'    => "{$recent} articles this week vs about " . self::fmtRate($baselineWeekly)
                             . "/week lately (+{$pct}%).",
                'metric'    => '+' . $pct . '%',
                'count'     => $recent,
                'score'     => $ratio,
                'banner'    => false,
            ]];
        }
        if ($ratio <= self::VOLUME_RATIO_DOWN) {
            return [[
                'id'        => 'trend:volume:down',
                'kind'      => 'volume',
                'level'     => 'info',
                'direction' => 'down',
                'title'     => 'Coverage volume down',
                'detail'    => "{$recent} articles this week vs about " . self::fmtRate($baselineWeekly)
                             . "/week lately ({$pct}%).",
                'metric'    => $pct . '%',
                'count'     => $recent,
                'score'     => 1 / max($ratio, 0.01),
                'banner'    => false,
            ]];
        }
        return [];
    }

    // ============================================================
    // Formatting helpers
    // ============================================================

    private static function fmtRatio(float $ratio): string
    {
        // 4.0 -> "4×", 2.5 -> "2.5×"
        $rounded = round($ratio, 1);
        $str = (fmod($rounded, 1.0) === 0.0) ? (string)(int)$rounded : (string)$rounded;
        return $str . '×';
    }

    private static function fmtRate(float $perWeek): string
    {
        $rounded = round($perWeek, 1);
        return (fmod($rounded, 1.0) === 0.0) ? (string)(int)$rounded : (string)$rounded;
    }

    private static function slug(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }
}
