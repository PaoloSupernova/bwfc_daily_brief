<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Auto-triage of the morning discovery queue. Asks Claude, in one call, to rank
 * each new candidate (high/medium/low), suggest a section, and cluster
 * duplicate coverage of the same story — then stores the result on each
 * candidate so the queue can surface the must-includes and group duplicates.
 */
final class QueueTriage
{
    private const MAX_CANDIDATES = 60;

    /**
     * Triage the current 'new' candidates in the window.
     * @return array{triaged:int, clusters:int}
     */
    public static function run(int $hours = 48): array
    {
        $hours = max(1, min(168, $hours));

        $candidates = Database::select(
            "SELECT c.id, c.headline, c.outlet_name, s.name AS source_name
             FROM discovery_candidates c
             JOIN discovery_sources s ON s.id = c.source_id
             WHERE c.status = 'new'
               AND c.discovered_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
               AND (c.published_at IS NULL OR c.published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
             ORDER BY c.discovered_at DESC
             LIMIT " . self::MAX_CANDIDATES,
            ['hours' => $hours]
        );

        if (count($candidates) === 0) {
            return ['triaged' => 0, 'clusters' => 0];
        }

        $sections = BriefRepository::sections(true);
        $slugs = array_map(static fn($s) => (string)$s['slug'], $sections);
        $sectionRules = implode(', ', array_map(
            static fn($s) => $s['slug'] . ' (' . $s['name'] . ')', $sections
        ));

        $list = '';
        foreach ($candidates as $c) {
            $outlet = (string)($c['outlet_name'] ?: $c['source_name']);
            $list .= "[{$c['id']}] {$outlet}: {$c['headline']}\n";
        }

        $prompt = <<<PROMPT
You are triaging a morning news queue for Bolton Wanderers' communications team.
Each line is a candidate article: [id] Outlet: Headline.

{$list}
For EACH candidate, decide:
- priority: "high" (must consider for the brief — direct, significant BWFC news),
  "medium" (relevant, worth including), or "low" (tangential/minor/filler).
- section: the best-fit section slug from: {$sectionRules}
- cluster: an integer. Give the SAME cluster number to candidates covering the
  same underlying story (e.g. the same match, signing, or announcement from
  different outlets). Use a unique number for a standalone story.

Reply with ONLY a JSON array, no prose, like:
[{"id":12,"priority":"high","section":"bwfc","cluster":1},{"id":13,"priority":"low","section":"efl","cluster":2}]
PROMPT;

        $raw = (new ClaudeClient())->complete($prompt, null, ['max_tokens' => 2000]);
        $decoded = self::parseJsonArray($raw);
        if ($decoded === null) {
            throw new \RuntimeException('Triage returned an unexpected response.');
        }

        $validIds = [];
        foreach ($candidates as $c) {
            $validIds[(int)$c['id']] = true;
        }

        $clusters = [];
        $triaged = 0;
        foreach ($decoded as $row) {
            $id = (int)($row['id'] ?? 0);
            if (!isset($validIds[$id])) {
                continue;
            }
            $priority = in_array(($row['priority'] ?? ''), ['high', 'medium', 'low'], true) ? $row['priority'] : 'medium';
            $section = in_array(($row['section'] ?? ''), $slugs, true) ? $row['section'] : null;
            $cluster = isset($row['cluster']) ? (int)$row['cluster'] : null;

            Database::updateRow('discovery_candidates', [
                'triage_priority' => $priority,
                'triage_section'  => $section,
                'triage_cluster'  => $cluster,
                'triaged_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $id]);

            if ($cluster !== null) {
                $clusters[$cluster] = true;
            }
            $triaged++;
        }

        return ['triaged' => $triaged, 'clusters' => count($clusters)];
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function parseJsonArray(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip code fences if present.
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw;
        // Extract the first [...] block.
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $raw = $m[0];
        }
        $decoded = json_decode(trim($raw), true);
        return is_array($decoded) ? $decoded : null;
    }
}
