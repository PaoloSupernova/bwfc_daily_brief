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

    /**
     * Detect if a new article covers the same story as one already in the brief.
     *
     * @param array<int, array{id: int, headline: string, summary: string}> $existingArticles
     * @return array{article_id: int, headline: string}|null  null = unique story
     */
    public function detectDuplicate(string $headline, string $content, array $existingArticles): ?array
    {
        if (count($existingArticles) === 0) {
            return null;
        }
        $raw = $this->claude->complete($this->buildDuplicatePrompt($headline, $content, $existingArticles));
        return $this->parseDuplicateResult($raw, $existingArticles);
    }

    /**
     * @param array<int, array{id:int, headline:string, summary:string}> $existingArticles
     */
    private function buildDuplicatePrompt(string $headline, string $content, array $existingArticles): string
    {
        $existingList = '';
        foreach ($existingArticles as $a) {
            $existingList .= '[' . $a['id'] . '] ' . $a['headline'] . "\n";
            $summary = trim((string)($a['summary'] ?? ''));
            if ($summary !== '') {
                $existingList .= 'Summary: ' . mb_substr($summary, 0, 200) . "\n";
            }
            $existingList .= "\n";
        }

        $contentExcerpt = $this->truncateContent($content, 800);

        return <<<PROMPT
You are a duplicate story detector for a football news digest.

NEW ARTICLE
Headline: {$headline}
Content excerpt: {$contentExcerpt}

TODAY'S BRIEF
{$existingList}
TASK
If the new article covers the same news story as one of today's articles (same match result, same transfer, same announcement, same incident), reply with ONLY the ID number of that article (e.g. "42").
If it is a genuinely different story, reply with ONLY the word: UNIQUE

Do not include any explanation. One word or number only.
PROMPT;
    }

    /**
     * @param array<int, array{id:int, headline:string, summary:string}> $existingArticles
     * @return array{article_id:int, headline:string}|null
     */
    private function parseDuplicateResult(string $raw, array $existingArticles): ?array
    {
        $raw = trim($raw);
        if (strtoupper($raw) === 'UNIQUE') {
            return null;
        }
        $matched = (int)preg_replace('/\D/', '', $raw);
        if ($matched === 0) {
            return null;
        }
        foreach ($existingArticles as $a) {
            if ((int)$a['id'] === $matched) {
                return ['article_id' => $matched, 'headline' => (string)$a['headline']];
            }
        }
        return null;
    }

    public function summariseArticle(string $headline, string $outlet, string $content, bool $ignoreRelevance = false): string
    {
        return $this->claude->complete($this->buildSummaryPrompt($headline, $outlet, $content, $ignoreRelevance));
    }

    /**
     * Build the full article-summary prompt: knowledge base + recent editor
     * edits (few-shot style) + the task template + optional inclusion override.
     */
    private function buildSummaryPrompt(string $headline, string $outlet, string $content, bool $ignoreRelevance): string
    {
        $template = $this->getPromptTemplate('article_summary');
        $prompt = $this->hydrate($template, [
            'headline' => $headline,
            'outlet' => $outlet,
            'content' => $this->truncateContent($content, 6000),
        ]);

        // When the editor chooses to include a story regardless of its direct
        // BWFC relevance, tell the model to summarise it on its own terms and not
        // to comment on relevance.
        $override = $ignoreRelevance
            ? "\n\nINCLUSION OVERRIDE (highest priority): This article has been deliberately selected for the brief. "
              . "Summarise it factually as a standalone news item on its own terms. Do NOT state or imply that it "
              . "lacks relevance to Bolton Wanderers, and do not add any commentary about its relevance to the club."
            : '';

        return KnowledgeBase::promptBlock() . $this->editStyleExamplesBlock() . $prompt . $override;
    }

    /**
     * Build a few-shot block of the team's recent AI-draft → editor-final pairs,
     * instructing the model to mirror their editorial style (not their content).
     * Returns '' when disabled or when there are no usable edits yet.
     */
    private function editStyleExamplesBlock(): string
    {
        if (env('SUMMARY_LEARN_FROM_EDITS', true) !== true) {
            return '';
        }
        $limit = (int)env('SUMMARY_EDIT_EXAMPLES', 4);
        if ($limit < 1) {
            return '';
        }

        $examples = BriefRepository::recentEditExamples($limit);
        if (count($examples) === 0) {
            return '';
        }

        $block = "HOUSE STYLE — LEARN FROM OUR EDITORS\n"
            . "The communications team refined the AI drafts below into their final versions. "
            . "Study how they change wording, length, tone, structure and emphasis, and write your "
            . "summary in the SAME editorial style. These are style references only — do NOT reuse "
            . "their facts, names or subject matter.\n\n";

        foreach ($examples as $i => $ex) {
            $n = $i + 1;
            $original = $this->truncateContent($ex['original'], 700);
            $edited   = $this->truncateContent($ex['edited'], 700);
            $block .= "STYLE EXAMPLE {$n}\n"
                . "- AI draft: {$original}\n"
                . "- Editor's final: {$edited}\n\n";
        }

        $block .= "---\nNow write the summary for the article below, applying that editorial style.\n\n";
        return $block;
    }

    /**
     * Classify the sentiment of a generated summary in the context of Bolton Wanderers.
     * Returns 'positive', 'neutral', or 'negative'.
     */
    public function classifySentiment(string $headline, string $summary): string
    {
        $prompt = <<<PROMPT
You are classifying the sentiment of a news article summary for Bolton Wanderers Football Club.

Headline: {$headline}
Summary: {$summary}

From the perspective of Bolton Wanderers Football Club, is this article POSITIVE, NEUTRAL, or NEGATIVE?

- POSITIVE: good news for the club (win, signing, community event, positive profile, award)
- NEGATIVE: bad news for the club (defeat, injury, controversy, criticism, legal issue)
- NEUTRAL: factual/informational with no clear positive or negative impact on the club

Reply with ONE word only: POSITIVE, NEUTRAL, or NEGATIVE
PROMPT;

        $raw = strtoupper(trim($this->claude->complete($prompt)));

        return match($raw) {
            'POSITIVE' => 'positive',
            'NEGATIVE' => 'negative',
            default    => 'neutral',
        };
    }

    /**
     * Classify what the article is about, from the controlled Topics taxonomy.
     * Returns a valid topic slug (defaults to 'other').
     */
    public function suggestTopic(string $headline, string $outlet, string $content): string
    {
        $rules = Topics::promptRules();
        $excerpt = $this->truncateContent($content, 1500);

        $prompt = <<<PROMPT
You are classifying what a Bolton Wanderers news article is primarily about.

Headline: {$headline}
Outlet: {$outlet}
Content excerpt: {$excerpt}

Choose the SINGLE best-fitting topic from this list:
{$rules}

Reply with ONLY the topic keyword (the word before the colon), lowercase, nothing else.
PROMPT;

        return Topics::normalise($this->claude->complete($prompt));
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
     * One-shot classification: section, sentiment and topic in a SINGLE Claude
     * call. Sentiment is judged from the headline + content (not the summary),
     * which means this call does NOT depend on the summary and can run
     * concurrently with it — see processArticle().
     *
     * On the rare occasion the model returns unparseable JSON, it falls back to
     * the individual classifier calls so suggestions stay accurate.
     *
     * @return array{section: string, sentiment: string, topic: string}
     */
    public function analyse(string $headline, string $outlet, string $content): array
    {
        [$prompt, $allowedSections] = $this->buildAnalysePrompt($headline, $outlet, $content);
        $raw = $this->claude->complete($prompt, null, ['max_tokens' => 200]);
        return $this->validateAnalysis($this->parseAnalysis($raw), $allowedSections, $headline, $outlet, $content);
    }

    /**
     * Build the combined classification prompt and the list of allowed section
     * labels (for validating the reply).
     *
     * @return array{0:string, 1:array<int,string>}
     */
    private function buildAnalysePrompt(string $headline, string $outlet, string $content): array
    {
        $sections = BriefRepository::sections(true);
        $sectionRules = '';
        $allowedSections = [];
        foreach ($sections as $s) {
            $label = strtoupper($s['slug']);
            $allowedSections[] = $label;
            $description = trim((string)($s['routing_description'] ?? '')) ?: 'General section for ' . $s['name'];
            $sectionRules .= "- {$label}: {$description}\n";
        }
        $topicRules = Topics::promptRules();
        $excerpt = $this->truncateContent($content, 1500);
        $sectionRules = trim($sectionRules);

        $prompt = <<<PROMPT
You are classifying a Bolton Wanderers news article for an internal media brief. Do THREE classifications and return them as JSON.

Headline: {$headline}
Outlet: {$outlet}
Content excerpt: {$excerpt}

1. SECTION — choose the single best-fitting section label:
{$sectionRules}

2. SENTIMENT — from the perspective of Bolton Wanderers Football Club, is this POSITIVE (good news: win, signing, community event, positive profile, award), NEGATIVE (bad news: defeat, injury, controversy, criticism, legal issue), or NEUTRAL (factual/informational with no clear impact on the club)?

3. TOPIC — choose the single best-fitting topic (the keyword before the colon):
{$topicRules}

Return ONLY a JSON object, no other text, in exactly this form:
{"section":"SECTION_LABEL","sentiment":"positive|neutral|negative","topic":"topic_keyword"}
PROMPT;

        return [$prompt, $allowedSections];
    }

    /**
     * Validate a parsed analysis reply, falling back to individual classifier
     * calls when the JSON was unusable.
     *
     * @param array<string,mixed>|null $parsed
     * @param array<int,string> $allowedSections
     * @return array{section:string, sentiment:string, topic:string}
     */
    private function validateAnalysis(?array $parsed, array $allowedSections, string $headline, string $outlet, string $content): array
    {
        if ($parsed === null) {
            return [
                'section'   => $this->suggestSection($headline, $outlet, $content),
                'sentiment' => $this->classifySentiment($headline, $content),
                'topic'     => $this->suggestTopic($headline, $outlet, $content),
            ];
        }

        $section = strtoupper(trim((string)preg_replace('/[^A-Z_]/i', '', (string)($parsed['section'] ?? ''))));
        if (!in_array($section, $allowedSections, true)) {
            $section = $allowedSections[0] ?? 'BWFC';
        }

        $sentiment = strtolower(trim((string)($parsed['sentiment'] ?? 'neutral')));
        if (!in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
            $sentiment = 'neutral';
        }

        $topic = Topics::normalise((string)($parsed['topic'] ?? 'other'));

        return ['section' => $section, 'sentiment' => $sentiment, 'topic' => $topic];
    }

    /**
     * Process an article in ONE concurrent batch: summary, classification and
     * (optionally) duplicate detection all fire at once instead of back-to-back.
     * Total wall-clock ≈ the slowest single call rather than their sum.
     *
     * Returns either ['duplicate' => ['article_id'=>int,'headline'=>string]]
     * when the article matches one already in the brief, or
     * ['summary'=>string, 'section'=>string, 'sentiment'=>string, 'topic'=>string].
     *
     * @param array<int, array{id:int, headline:string, summary:string}> $existingArticles
     * @return array<string, mixed>
     */
    public function processArticle(
        string $headline,
        string $outlet,
        string $content,
        bool $ignoreRelevance = false,
        array $existingArticles = []
    ): array {
        [$analysePrompt, $allowedSections] = $this->buildAnalysePrompt($headline, $outlet, $content);

        $jobs = [
            'summary' => ['prompt' => $this->buildSummaryPrompt($headline, $outlet, $content, $ignoreRelevance)],
            'analyse' => ['prompt' => $analysePrompt, 'options' => ['max_tokens' => 200]],
        ];
        if (count($existingArticles) > 0) {
            $jobs['duplicate'] = ['prompt' => $this->buildDuplicatePrompt($headline, $content, $existingArticles), 'options' => ['max_tokens' => 16]];
        }

        $results = $this->claude->completeConcurrent($jobs);

        // If the article duplicates one already in the brief, report that and
        // discard the (now unused) summary/analysis — duplicates are rare, so
        // computing them concurrently is a good trade for the common path.
        if (isset($results['duplicate'])) {
            $dup = $this->parseDuplicateResult($results['duplicate'], $existingArticles);
            if ($dup !== null) {
                return ['duplicate' => $dup];
            }
        }

        $analysis = $this->validateAnalysis(
            $this->parseAnalysis($results['analyse'] ?? ''),
            $allowedSections,
            $headline,
            $outlet,
            $content
        );

        return [
            'summary'   => trim((string)($results['summary'] ?? '')),
            'section'   => $analysis['section'],
            'sentiment' => $analysis['sentiment'],
            'topic'     => $analysis['topic'],
        ];
    }

    /** Parse the analyse() JSON reply, tolerating code fences / surrounding text. */
    private function parseAnalysis(string $raw): ?array
    {
        $s = trim($raw);
        $s = (string)preg_replace('/^```(?:json)?/i', '', $s);
        $s = (string)preg_replace('/```$/', '', trim($s));
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $s = $m[0];
        }
        $data = json_decode(trim($s), true);
        return is_array($data) ? $data : null;
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

        return $this->claude->complete(KnowledgeBase::promptBlock() . $prompt);
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
