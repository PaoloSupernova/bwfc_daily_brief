<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use Throwable;
use RuntimeException;

/**
 * Generates weekly editorial insights summaries using Claude.
 *
 * Aggregates the week's coverage metrics, samples a few brief executive summaries
 * for context, computes a comparison against the prior week, and asks Claude
 * to write a 3-paragraph analytical overview.
 *
 * Prompt storage matches the existing prompt_templates schema: a single
 * template_body field with system instructions and user prompt template
 * separated by '---' on its own line.
 */
final class WeeklyInsights
{
    public static function generate(?string $weekStart = null, string $generatedBy = 'manual'): array
    {
        if ($weekStart === null) {
            $weekStart = date('Y-m-d', strtotime('last monday', strtotime('today')));
            if ($weekStart === date('Y-m-d')) {
                $weekStart = date('Y-m-d', strtotime('-7 days'));
            }
        }
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $metrics = self::aggregateMetrics($weekStart, $weekEnd);
        $sample = self::sampleSummaries($weekStart, $weekEnd);
        $comparison = self::compareToPriorWeek($weekStart, $weekEnd);

        $summary = self::callClaude($weekStart, $weekEnd, $metrics, $sample, $comparison);

        $existing = Database::selectOne(
            "SELECT id FROM weekly_insights WHERE week_start = :w",
            ['w' => $weekStart]
        );
        $payload = [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'summary_text' => $summary,
            'metrics_json' => json_encode(['metrics' => $metrics, 'comparison' => $comparison]),
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $generatedBy,
        ];
        if ($existing !== null) {
            Database::updateRow('weekly_insights', $payload, ['id' => (int)$existing['id']]);
        } else {
            Database::insertRow('weekly_insights', $payload);
        }

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'summary' => $summary,
            'metrics' => $metrics,
        ];
    }

    public static function latest(): ?array
    {
        $row = Database::selectOne(
            "SELECT * FROM weekly_insights ORDER BY week_start DESC LIMIT 1"
        );
        if ($row === null) return null;

        $row['metrics_json'] = $row['metrics_json'] !== null
            ? json_decode((string)$row['metrics_json'], true)
            : null;
        return $row;
    }

    private static function aggregateMetrics(string $weekStart, string $weekEnd): array
    {
        $localPatterns = ['bwfc.co.uk', 'Bolton News', 'Lancashire Evening Post',
                          'Manchester Evening News', 'BWitC', 'Bolton Stadium Hotel'];

        $totalArticles = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e",
            ['s' => $weekStart, 'e' => $weekEnd]
        )['n'] ?? 0);

        $totalBriefs = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM briefs
             WHERE deleted_at IS NULL AND brief_date BETWEEN :s AND :e",
            ['s' => $weekStart, 'e' => $weekEnd]
        )['n'] ?? 0);

        $bwfcArticles = Database::select(
            "SELECT a.outlet_name FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             JOIN sections s ON s.id = a.section_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e AND s.slug = 'bwfc'",
            ['s' => $weekStart, 'e' => $weekEnd]
        );
        $bwfcLocal = 0;
        $bwfcNational = 0;
        foreach ($bwfcArticles as $row) {
            $isLocal = false;
            foreach ($localPatterns as $p) {
                if (stripos((string)$row['outlet_name'], $p) !== false) {
                    $isLocal = true;
                    break;
                }
            }
            $isLocal ? $bwfcLocal++ : $bwfcNational++;
        }
        $bwfcTotal = $bwfcLocal + $bwfcNational;
        $pickupPct = $bwfcTotal > 0 ? round(($bwfcNational / $bwfcTotal) * 100) : 0;

        $topOutlets = Database::select(
            "SELECT a.outlet_name AS name, COUNT(*) AS n
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e
             GROUP BY a.outlet_name ORDER BY n DESC LIMIT 5",
            ['s' => $weekStart, 'e' => $weekEnd]
        );

        $sectionBreakdown = Database::select(
            "SELECT s.name, COUNT(*) AS n
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             JOIN sections s ON s.id = a.section_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e AND s.deleted_at IS NULL
             GROUP BY s.id, s.name ORDER BY n DESC",
            ['s' => $weekStart, 'e' => $weekEnd]
        );

        return [
            'total_articles' => $totalArticles,
            'total_briefs' => $totalBriefs,
            'bwfc_articles' => $bwfcTotal,
            'bwfc_local' => $bwfcLocal,
            'bwfc_national' => $bwfcNational,
            'national_pickup_pct' => $pickupPct,
            'top_outlets' => array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['n']], $topOutlets),
            'section_breakdown' => array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['n']], $sectionBreakdown),
        ];
    }

    private static function compareToPriorWeek(string $weekStart, string $weekEnd): array
    {
        $priorStart = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        $priorEnd = date('Y-m-d', strtotime($weekEnd . ' -7 days'));

        $priorTotal = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e",
            ['s' => $priorStart, 'e' => $priorEnd]
        )['n'] ?? 0);

        return [
            'prior_week_start' => $priorStart,
            'prior_week_end' => $priorEnd,
            'prior_total_articles' => $priorTotal,
        ];
    }

    private static function sampleSummaries(string $weekStart, string $weekEnd): array
    {
        $rows = Database::select(
            "SELECT b.brief_date, b.executive_summary
             FROM briefs b
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :s AND :e
               AND b.executive_summary IS NOT NULL AND b.executive_summary <> ''
             ORDER BY b.brief_date DESC LIMIT 4",
            ['s' => $weekStart, 'e' => $weekEnd]
        );
        return array_map(fn($r) => [
            'date' => (string)$r['brief_date'],
            'executive_summary' => (string)$r['executive_summary'],
        ], $rows);
    }

    /**
     * Reads the prompt template from prompt_templates.template_body.
     * The body is structured as: system instructions, then a line containing
     * just '---', then the user prompt template. Same convention as the
     * existing summarise template.
     */
    private static function callClaude(string $weekStart, string $weekEnd, array $metrics, array $sample, array $comparison): string
    {
        $template = Database::selectOne(
            "SELECT template_body FROM prompt_templates
             WHERE template_key = 'weekly_insights' AND is_active = 1
             ORDER BY version DESC LIMIT 1"
        );
        if ($template === null) {
            throw new RuntimeException('weekly_insights prompt template not found in prompt_templates');
        }

        // Split the body into system + user halves on the '---' delimiter
        $body = (string)$template['template_body'];
        $parts = preg_split('/^\s*---\s*$/m', $body, 2);
        if ($parts === false || count($parts) < 2) {
            throw new RuntimeException('weekly_insights template missing --- delimiter between system and user sections');
        }
        $systemPrompt = trim($parts[0]);
        $userTemplate = trim($parts[1]);

        $metricsText = self::formatMetricsForPrompt($metrics);
        $sampleText = self::formatSampleForPrompt($sample);
        $comparisonText = self::formatComparisonForPrompt($metrics, $comparison);

        $userPrompt = strtr($userTemplate, [
            '{{week_start}}' => date('jS F Y', strtotime($weekStart)),
            '{{week_end}}' => date('jS F Y', strtotime($weekEnd)),
            '{{metrics}}' => $metricsText,
            '{{sample}}' => $sampleText,
            '{{comparison}}' => $comparisonText,
        ]);

        $client = new ClaudeClient();
        $response = $client->complete($systemPrompt, $userPrompt, ['max_tokens' => 800]);

        return trim($response);
    }

    private static function formatMetricsForPrompt(array $m): string
    {
        $lines = [];
        $lines[] = "- Total articles in briefs this week: {$m['total_articles']}";
        $lines[] = "- Briefs sent: {$m['total_briefs']}";
        $lines[] = "- BWFC-section articles: {$m['bwfc_articles']} (local outlets: {$m['bwfc_local']}, national: {$m['bwfc_national']})";
        $lines[] = "- National pickup of BWFC stories: {$m['national_pickup_pct']}%";
        $lines[] = "- Top 5 outlets:";
        foreach ($m['top_outlets'] as $o) {
            $lines[] = "    - {$o['name']}: {$o['count']} articles";
        }
        $lines[] = "- Section breakdown:";
        foreach ($m['section_breakdown'] as $s) {
            $lines[] = "    - {$s['name']}: {$s['count']}";
        }
        return implode("\n", $lines);
    }

    private static function formatSampleForPrompt(array $sample): string
    {
        if (count($sample) === 0) return '(no executive summaries available)';
        $lines = [];
        foreach ($sample as $s) {
            $date = date('l jS F', strtotime($s['date']));
            $lines[] = "[{$date}]";
            $lines[] = $s['executive_summary'];
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }

    private static function formatComparisonForPrompt(array $metrics, array $comparison): string
    {
        $current = $metrics['total_articles'];
        $prior = $comparison['prior_total_articles'];
        if ($prior === 0) {
            return "No data for the prior week, so no comparison available.";
        }
        $delta = $current - $prior;
        $pct = round(($delta / $prior) * 100);
        $direction = $delta >= 0 ? 'up' : 'down';
        return "Prior week ({$comparison['prior_week_start']} to {$comparison['prior_week_end']}): {$prior} articles. This week: {$current}. Change: {$direction} " . abs($pct) . "%.";
    }
}
