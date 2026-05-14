<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use RuntimeException;

/**
 * Coordinates Claude API calls for the three prompt types:
 * - article_summary
 * - executive_summary
 * - section_suggest (now uses dynamic section rules from the database)
 *
 * Pulls active prompt templates from the database and hydrates placeholders.
 */
final class Summariser
{
    private ClaudeClient $claude;

    public function __construct(?ClaudeClient $claude = null)
    {
        $this->claude = $claude ?? new ClaudeClient();
    }

    public function summariseArticle(string $headline, string $outlet, string $content): string
    {
        $template = $this->getPromptTemplate('article_summary');
        $prompt = $this->hydrate($template, [
            'headline' => $headline,
            'outlet' => $outlet,
            'content' => $this->truncateContent($content, 6000),
        ]);

        return $this->claude->complete($prompt);
    }

    public function suggestSection(string $headline, string $outlet, string $content): string
    {
        $sections = BriefRepository::sections(true);
        $rules = '';
        $allowed = [];
        foreach ($sections as $s) {
            $label = strtoupper($s['slug']);
            $allowed[] = $label;
            $description = trim((string)($s['routing_description'] ?? '')) ?: 'General section for ' . $s['name'];
            $rules .= "- {$label}: {$description}\n";
        }

        $template = $this->getPromptTemplate('section_suggest');
        $prompt = $this->hydrate($template, [
            'headline' => $headline,
            'outlet' => $outlet,
            'content' => $this->truncateContent($content, 1500),
            'section_rules' => trim($rules),
        ]);

        $raw = $this->claude->complete($prompt);
        $label = strtoupper(trim(preg_replace('/[^A-Z_]/i', '', $raw) ?? ''));

        if (in_array($label, $allowed, true)) {
            return $label;
        }

        // Fallback: return the first active section slug in uppercase
        return count($allowed) > 0 ? $allowed[0] : 'BWFC';
    }

    /**
     * @param array<int, array{headline: string, outlet: string, summary: string, section: string}> $articles
     */
    public function executiveSummary(array $articles): string
    {
        if (count($articles) === 0) {
            throw new RuntimeException('Cannot generate executive summary with no articles');
        }

        $formatted = '';
        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $formatted .= "[{$num}] Section: {$article['section']}\n";
            $formatted .= "Outlet: {$article['outlet']}\n";
            $formatted .= "Headline: {$article['headline']}\n";
            $formatted .= "Summary: {$article['summary']}\n\n";
        }

        $template = $this->getPromptTemplate('executive_summary');
        $prompt = $this->hydrate($template, ['articles' => $formatted]);

        return $this->claude->complete($prompt);
    }

    private function getPromptTemplate(string $key): string
    {
        $row = Database::selectOne(
            'SELECT template_body FROM prompt_templates WHERE template_key = :key AND is_active = 1 ORDER BY version DESC LIMIT 1',
            ['key' => $key]
        );

        if ($row === null) {
            throw new RuntimeException("Prompt template '{$key}' not found in database. Run seed.sql.");
        }

        return (string)$row['template_body'];
    }

    /**
     * @param array<string, string> $vars
     */
    private function hydrate(string $template, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }
        return $template;
    }

    private function truncateContent(string $content, int $maxChars): string
    {
        if (strlen($content) <= $maxChars) {
            return $content;
        }
        return substr($content, 0, $maxChars) . "\n\n[... content truncated ...]";
    }
}
