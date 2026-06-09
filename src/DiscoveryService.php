<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use Throwable;

/**
 * Orchestrates the discovery pipeline:
 *  - Lists active sources
 *  - Polls each (skipping if too recently polled)
 *  - Inserts new candidates, dedup'd by URL hash
 *  - Marks candidates that match URLs already in brief_articles as 'duplicate'
 *  - Logs each poll attempt to discovery_polls
 *
 * Stays self-contained: no UI dependencies, called from API endpoints and the
 * scheduled job runner.
 */
final class DiscoveryService
{
    /**
     * Poll all active sources whose poll frequency has elapsed.
     * Returns summary of work done, suitable for logging.
     *
     * @return array{sources_polled: int, sources_skipped: int, items_found: int, items_new: int, errors: array}
     */
    public static function pollAllDue(bool $force = false): array
    {
        $sources = Database::select(
            "SELECT * FROM discovery_sources
             WHERE is_active = 1 AND deleted_at IS NULL
             ORDER BY name"
        );

        $polled = 0;
        $skipped = 0;
        $itemsFound = 0;
        $itemsNew = 0;
        $errors = [];

        foreach ($sources as $source) {
            if (!$force && !self::isDueForPoll($source)) {
                $skipped++;
                continue;
            }

            try {
                $result = self::pollSource((int)$source['id']);
                $polled++;
                $itemsFound += $result['items_found'];
                $itemsNew += $result['items_new'];
            } catch (Throwable $e) {
                $errors[] = [
                    'source' => $source['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'sources_polled' => $polled,
            'sources_skipped' => $skipped,
            'items_found' => $itemsFound,
            'items_new' => $itemsNew,
            'errors' => $errors,
        ];
    }

    /**
     * Poll a single source. Records the attempt in discovery_polls.
     *
     * @return array{items_found: int, items_new: int}
     */
    public static function pollSource(int $sourceId): array
    {
        $source = Database::selectOne(
            "SELECT * FROM discovery_sources WHERE id = :id AND deleted_at IS NULL",
            ['id' => $sourceId]
        );
        if ($source === null) {
            throw new \RuntimeException("Source {$sourceId} not found");
        }

        $pollId = Database::insertRow('discovery_polls', [
            'source_id' => $sourceId,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $items = FeedParser::fetch(
                (string)$source['url'],
                (string)$source['source_type']
            );
            $newCount = self::ingestItems($sourceId, (string)$source['name'], $items);

            Database::updateRow('discovery_polls',
                [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'items_found' => count($items),
                    'items_new' => $newCount,
                    'success' => 1,
                ],
                ['id' => $pollId]
            );

            Database::updateRow('discovery_sources',
                [
                    'last_polled_at' => date('Y-m-d H:i:s'),
                    'last_success_at' => date('Y-m-d H:i:s'),
                    'last_error' => null,
                ],
                ['id' => $sourceId]
            );

            return ['items_found' => count($items), 'items_new' => $newCount];

        } catch (Throwable $e) {
            Database::updateRow('discovery_polls',
                [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'success' => 0,
                    'error_message' => $e->getMessage(),
                ],
                ['id' => $pollId]
            );

            Database::updateRow('discovery_sources',
                [
                    'last_polled_at' => date('Y-m-d H:i:s'),
                    'last_error' => substr($e->getMessage(), 0, 500),
                ],
                ['id' => $sourceId]
            );

            throw $e;
        }
    }

    /**
     * Insert candidates from a parsed feed, dedup'd by URL hash.
     *
     * @param array<int, array<string, mixed>> $items
     * @return int Count of new candidates inserted
     */
    private static function ingestItems(int $sourceId, string $sourceName, array $items): int
    {
        $newCount = 0;
        foreach ($items as $item) {
            $url = (string)($item['url'] ?? '');
            $headline = (string)($item['headline'] ?? '');
            if ($url === '' || $headline === '') continue;

            $urlHash = hash('sha256', self::canonicaliseUrl($url));

            // Skip if we've already seen this URL
            $existing = Database::selectOne(
                "SELECT id FROM discovery_candidates WHERE url_hash = :h",
                ['h' => $urlHash]
            );
            if ($existing !== null) continue;

            // Detect outlet: feed-provided source > heuristic from URL host
            $outlet = (string)($item['outlet_name'] ?? '');
            if ($outlet === '') {
                $outlet = self::outletFromUrl($url) ?? $sourceName;
            }

            // Check if URL already exists in brief_articles - mark as duplicate
            $alreadyPublished = Database::selectOne(
                "SELECT id FROM brief_articles WHERE url = :url LIMIT 1",
                ['url' => $url]
            );
            $status = $alreadyPublished !== null ? 'duplicate' : 'new';

            Database::insertRow('discovery_candidates', [
                'source_id' => $sourceId,
                'url' => $url,
                'url_hash' => $urlHash,
                'headline' => $headline,
                'description' => $item['description'] ?? null,
                'outlet_name' => $outlet,
                'published_at' => $item['published_at'] ?? null,
                'discovered_at' => date('Y-m-d H:i:s'),
                'status' => $status,
            ]);

            if ($status === 'new') $newCount++;
        }
        return $newCount;
    }

    private static function isDueForPoll(array $source): bool
    {
        $last = $source['last_polled_at'] ?? null;
        if ($last === null) return true;
        $freq = (int)($source['poll_frequency_minutes'] ?? 60);
        $threshold = strtotime("-{$freq} minutes");
        return strtotime((string)$last) < $threshold;
    }

    /**
     * Canonicalise URL for dedup: strip tracking params, lowercase host, remove fragment.
     */
    private static function canonicaliseUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) return $url;

        $host = strtolower($parts['host'] ?? '');
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = rtrim($parts['path'] ?? '', '/');
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            // Drop common tracking params
            $drop = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                     'fbclid', 'gclid', 'ref', 'source', '_ga'];
            foreach ($drop as $key) unset($params[$key]);
            ksort($params);
            if (count($params) > 0) {
                $query = '?' . http_build_query($params);
            }
        }
        return $host . $path . $query;
    }

    private static function outletFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') return null;

        // Map common hosts to readable names
        $known = [
            'theboltonnews.co.uk' => 'Bolton News',
            'bwfc.co.uk' => 'bwfc.co.uk',
            'efl.com' => 'EFL',
            'bbc.co.uk' => 'BBC Sport',
            'bbc.com' => 'BBC Sport',
            'theathletic.com' => 'The Athletic',
            'manchestereveningnews.co.uk' => 'Manchester Evening News',
            'lep.co.uk' => 'Lancashire Evening Post',
            'guardian.co.uk' => 'The Guardian',
            'theguardian.com' => 'The Guardian',
            'dailymail.co.uk' => 'Daily Mail',
            'mirror.co.uk' => 'Daily Mirror',
            'thesun.co.uk' => 'The Sun',
            'independent.co.uk' => 'The Independent',
            'telegraph.co.uk' => 'The Telegraph',
        ];
        foreach ($known as $domain => $name) {
            if (str_ends_with($host, $domain)) return $name;
        }
        return ucfirst(explode('.', $host)[0]);
    }
}
