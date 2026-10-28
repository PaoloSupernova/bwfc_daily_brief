<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * "Ask your archive" — answers natural-language questions grounded strictly in
 * the captured coverage. Combines FULLTEXT retrieval of relevant articles with
 * a compact block of structured stats (volume, sentiment, people, journalists,
 * topics) so it can handle recall, trends and people/journalist questions.
 * Answers via Sonnet, citing sources; supports follow-up conversation.
 */
final class ArchiveAssistant
{
    private const MAX_ARTICLES = 20;
    private const MAX_HISTORY = 8;

    /**
     * @param array<int,array{role:string,content:string}> $history
     * @return array{answer:string, sources:array<int,array<string,mixed>>}
     */
    public static function answer(string $question, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['answer' => 'Please ask a question.', 'sources' => []];
        }

        $articles = self::retrieve($question);
        $statsBlock = self::statsContext();
        $articlesBlock = self::articlesContext($articles);

        $system = "You are the research assistant for Bolton Wanderers' communications team. "
            . "Answer the user's questions using ONLY the ARCHIVE DATA provided (coverage the team has "
            . "logged in its daily briefs). Do not use outside knowledge or invent anything. If the data "
            . "doesn't cover the question, say so plainly.\n\n"
            . "When you state a fact from an article, cite it inline as [Outlet, D Mon YYYY]. Be concise and "
            . "direct, in British English. For trend or counting questions, use the STATS and the dates on "
            . "the articles. Today's date is " . date('j M Y') . ".\n\n"
            . "=== ARCHIVE STATS ===\n" . $statsBlock;

        // Build the conversation: prior turns, then this question with its
        // freshly-retrieved supporting articles.
        $messages = [];
        foreach (array_slice($history, -self::MAX_HISTORY) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($turn['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages[] = [
            'role' => 'user',
            'content' => "RELEVANT ARTICLES FROM THE ARCHIVE:\n" . $articlesBlock . "\n\nQUESTION: " . $question,
        ];

        $model = (string)env('ANTHROPIC_ASK_MODEL', 'claude-sonnet-4-5');
        try {
            $answer = (new ClaudeClient())->completeMessages($messages, $system, ['model' => $model, 'max_tokens' => 1500]);
        } catch (\Throwable $e) {
            try {
                $answer = (new ClaudeClient())->completeMessages($messages, $system, ['max_tokens' => 1500]);
            } catch (\Throwable $e2) {
                throw new \RuntimeException('The assistant could not answer: ' . $e2->getMessage());
            }
        }

        return [
            'answer'  => $answer,
            'sources' => array_map(static fn($a) => [
                'brief_id'   => (int)$a['brief_id'],
                'brief_date' => (string)$a['brief_date'],
                'outlet'     => (string)$a['outlet_name'],
                'headline'   => (string)$a['headline'],
                'url'        => (string)$a['url'],
            ], $articles),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function retrieve(string $question): array
    {
        return Database::select(
            "SELECT a.id, a.brief_id, b.brief_date, a.outlet_name, a.headline, a.url, a.summary,
                    a.sentiment, a.topic, a.source_language,
                    (SELECT GROUP_CONCAT(p.name SEPARATOR ', ') FROM article_people ap
                       JOIN people p ON p.id = ap.person_id WHERE ap.article_id = a.id) AS people,
                    (SELECT GROUP_CONCAT(j.name SEPARATOR ', ') FROM article_journalists aj
                       JOIN journalists j ON j.id = aj.journalist_id WHERE aj.article_id = a.id) AS byline
             FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL
               AND MATCH(a.headline, a.summary, a.article_content) AGAINST(:q)
             ORDER BY MATCH(a.headline, a.summary, a.article_content) AGAINST(:q2) DESC, b.brief_date DESC
             LIMIT " . self::MAX_ARTICLES,
            ['q' => $question, 'q2' => $question]
        );
    }

    /** @param array<int,array<string,mixed>> $articles */
    private static function articlesContext(array $articles): string
    {
        if (count($articles) === 0) {
            return '(No individual articles matched the question text — rely on the STATS above for '
                . 'volume, sentiment, people and journalist questions.)';
        }
        $lines = [];
        foreach ($articles as $a) {
            $date = date('j M Y', strtotime((string)$a['brief_date']));
            $parts = ["[{$date}] {$a['outlet_name']}: {$a['headline']}"];
            $summary = trim((string)$a['summary']);
            if ($summary !== '') {
                $parts[] = 'Summary: ' . mb_substr($summary, 0, 400);
            }
            $meta = [];
            if (!empty($a['sentiment'])) $meta[] = 'sentiment ' . $a['sentiment'];
            if (!empty($a['topic'])) $meta[] = 'topic ' . $a['topic'];
            if (!empty($a['byline'])) $meta[] = 'by ' . $a['byline'];
            if (!empty($a['people'])) $meta[] = 'mentions ' . $a['people'];
            if ($meta) $parts[] = '(' . implode('; ', $meta) . ')';
            $lines[] = implode("\n", $parts);
        }
        return implode("\n\n", $lines);
    }

    private static function statsContext(): string
    {
        $lines = [];

        $totals = Database::selectOne(
            "SELECT COUNT(*) AS articles, MIN(b.brief_date) AS first, MAX(b.brief_date) AS last
             FROM brief_articles a JOIN briefs b ON b.id = a.brief_id WHERE b.deleted_at IS NULL"
        );
        $briefs = (int)(Database::selectOne("SELECT COUNT(*) AS n FROM briefs WHERE deleted_at IS NULL")['n'] ?? 0);
        $lines[] = "Total articles logged: " . (int)($totals['articles'] ?? 0)
            . " across {$briefs} briefs, from " . ($totals['first'] ?? '?') . " to " . ($totals['last'] ?? '?') . ".";

        // Sentiment overall
        $sent = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach (Database::select(
            "SELECT a.sentiment, COUNT(*) n FROM brief_articles a JOIN briefs b ON b.id=a.brief_id
             WHERE b.deleted_at IS NULL AND a.sentiment IS NOT NULL GROUP BY a.sentiment"
        ) as $r) {
            if (isset($sent[$r['sentiment']])) $sent[$r['sentiment']] = (int)$r['n'];
        }
        $lines[] = "Overall sentiment: {$sent['positive']} positive, {$sent['neutral']} neutral, {$sent['negative']} negative.";

        // Volume last 6 months
        $vol = Database::select(
            "SELECT DATE_FORMAT(b.brief_date,'%Y-%m') ym, COUNT(*) n
             FROM brief_articles a JOIN briefs b ON b.id=a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY ym ORDER BY ym DESC"
        );
        if ($vol) {
            $lines[] = "Articles per month (recent): " . implode(', ', array_map(fn($r) => "{$r['ym']}: {$r['n']}", $vol)) . ".";
        }

        // Top journalists with sentiment
        $js = Database::select(
            "SELECT j.name, COUNT(DISTINCT a.id) n,
                    SUM(a.sentiment='positive') p, SUM(a.sentiment='neutral') nu, SUM(a.sentiment='negative') ng
             FROM journalists j JOIN article_journalists aj ON aj.journalist_id=j.id
             JOIN brief_articles a ON a.id=aj.article_id JOIN briefs b ON b.id=a.brief_id
             WHERE b.deleted_at IS NULL GROUP BY j.id, j.name ORDER BY n DESC LIMIT 10"
        );
        if ($js) {
            $lines[] = "Most-active journalists (articles; +/=/-): " . implode('; ', array_map(
                fn($r) => "{$r['name']} ({$r['n']}; " . (int)$r['p'] . '/' . (int)$r['nu'] . '/' . (int)$r['ng'] . ')', $js)) . ".";
        }

        // Top people with sentiment
        $ps = Database::select(
            "SELECT p.name, p.role, COUNT(DISTINCT a.id) n,
                    SUM(a.sentiment='positive') pos, SUM(a.sentiment='neutral') nu, SUM(a.sentiment='negative') ng
             FROM people p JOIN article_people ap ON ap.person_id=p.id
             JOIN brief_articles a ON a.id=ap.article_id JOIN briefs b ON b.id=a.brief_id
             WHERE b.deleted_at IS NULL GROUP BY p.id, p.name, p.role ORDER BY n DESC LIMIT 10"
        );
        if ($ps) {
            $lines[] = "Most-covered people (articles; +/=/-): " . implode('; ', array_map(
                fn($r) => "{$r['name']} ({$r['role']}, {$r['n']}; " . (int)$r['pos'] . '/' . (int)$r['nu'] . '/' . (int)$r['ng'] . ')', $ps)) . ".";
        }

        // Topics
        $ts = Database::select(
            "SELECT a.topic, COUNT(*) n FROM brief_articles a JOIN briefs b ON b.id=a.brief_id
             WHERE b.deleted_at IS NULL AND a.topic IS NOT NULL GROUP BY a.topic ORDER BY n DESC"
        );
        if ($ts) {
            $lines[] = "Coverage by topic: " . implode(', ', array_map(
                fn($r) => Topics::label((string)$r['topic']) . ": {$r['n']}", $ts)) . ".";
        }

        return implode("\n", $lines);
    }
}
