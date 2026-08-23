<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Editable knowledge base — durable club facts, terminology, context and
 * distilled style rules that are injected into the AI summary prompts so the
 * output is grounded and on-house-voice. Managed in Admin -> Knowledge.
 */
final class KnowledgeBase
{
    public const CATEGORIES = ['fact', 'terminology', 'context', 'style', 'avoid', 'general'];

    private const CATEGORY_LABELS = [
        'fact'        => 'Facts',
        'terminology' => 'Terminology',
        'context'     => 'Context',
        'style'       => 'Style rules',
        'avoid'       => 'Avoid',
        'general'     => 'General',
    ];

    // ──────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return Database::select(
            "SELECT id, category, title, content, is_active, display_order
             FROM knowledge_entries {$where}
             ORDER BY FIELD(category, 'fact','terminology','context','style','avoid','general'),
                      display_order ASC, id ASC"
        );
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? ucfirst($category);
    }

    /**
     * Build the prompt block of active knowledge, grouped by category, capped in
     * length. Returns '' when there is nothing active.
     */
    public static function promptBlock(int $maxChars = 2800): string
    {
        // Best-effort: if the knowledge_entries table is missing (migration not
        // run yet) or unreadable, skip injection rather than break summarising.
        try {
            $rows = self::all(true);
        } catch (\Throwable $e) {
            return '';
        }
        if (count($rows) === 0) {
            return '';
        }

        $byCat = [];
        foreach ($rows as $r) {
            $byCat[(string)$r['category']][] = $r;
        }

        $lines = ["CLUB KNOWLEDGE & HOUSE STYLE",
            "Use the following for accuracy and house consistency. If a specific fact in the article conflicts, prefer the article — but always keep our terminology and style preferences.\n"];

        foreach (self::CATEGORIES as $cat) {
            if (empty($byCat[$cat])) {
                continue;
            }
            $lines[] = strtoupper(self::categoryLabel($cat)) . ':';
            foreach ($byCat[$cat] as $r) {
                $title = trim((string)$r['title']);
                $content = trim((string)$r['content']);
                $lines[] = '- ' . ($title !== '' ? $title . ': ' : '') . $content;
            }
            $lines[] = '';
        }

        $block = implode("\n", $lines);
        if (mb_strlen($block) > $maxChars) {
            $block = mb_substr($block, 0, $maxChars) . "\n[...]";
        }
        return $block . "\n---\n\n";
    }

    // ──────────────────────────────────────────────────────────────
    // CRUD (admin)
    // ──────────────────────────────────────────────────────────────

    public static function create(string $category, string $title, string $content, bool $active = true): int
    {
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'general';
        return Database::insert(
            'INSERT INTO knowledge_entries (category, title, content, is_active) VALUES (:c, :t, :co, :a)',
            ['c' => $category, 't' => $title, 'co' => $content, 'a' => $active ? 1 : 0]
        );
    }

    public static function update(int $id, string $category, string $title, string $content, bool $active): void
    {
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'general';
        Database::execute(
            'UPDATE knowledge_entries SET category = :c, title = :t, content = :co, is_active = :a WHERE id = :id',
            ['c' => $category, 't' => $title, 'co' => $content, 'a' => $active ? 1 : 0, 'id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM knowledge_entries WHERE id = :id', ['id' => $id]);
    }

    // ──────────────────────────────────────────────────────────────
    // Distilled style rules (feature 2)
    // ──────────────────────────────────────────────────────────────

    /**
     * Ask Claude to distil concise, reusable style rules from the team's recent
     * AI-draft -> editor-final edits. Returns a list of short rule strings (not
     * yet saved) for the user to review and keep.
     *
     * @return array<int, string>
     */
    public static function deriveStyleRules(int $editLimit = 12): array
    {
        $examples = BriefRepository::recentEditExamples($editLimit);
        if (count($examples) < 3) {
            return [];
        }

        $pairs = '';
        foreach ($examples as $i => $ex) {
            $n = $i + 1;
            $pairs .= "EDIT {$n}\nAI draft: " . mb_substr($ex['original'], 0, 600) . "\n"
                . "Editor's final: " . mb_substr($ex['edited'], 0, 600) . "\n\n";
        }

        $prompt = <<<PROMPT
You are analysing how a football club's communications team edits AI-drafted
article summaries, to distil their house style.

Below are recent pairs of an AI draft and the editor's final version.

{$pairs}
TASK
Identify the consistent, reusable STYLE preferences the editors apply (tone,
length, sentence structure, terminology, what they add or cut, formatting).
Write 4-8 short imperative rules a writer could follow, e.g.
"Keep summaries to two tight sentences" or "Always name the manager in full on
first mention". Focus on STYLE, not one-off facts.

Reply with ONLY the rules, one per line, no numbering, no preamble.
PROMPT;

        $raw = (new ClaudeClient())->complete($prompt);

        $rules = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*\d.\)]+)\s*/', '', $line) ?? $line);
            if ($line !== '' && mb_strlen($line) > 8) {
                $rules[] = $line;
            }
        }
        return array_slice($rules, 0, 8);
    }
}
