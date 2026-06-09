<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Post-generation check: flags any banned words or phrases that slipped through.
 *
 * The editor sees flags in the UI and can correct them before commit.
 * Does not auto-edit Claude's output.
 */
final class StyleGuard
{
    /** @var array<int, string>|null */
    private static ?array $bannedWordsCache = null;

    /** @var array<int, string>|null */
    private static ?array $bannedPhrasesCache = null;

    /**
     * Check a summary for rule violations.
     *
     * @return array{clean: bool, violations: array<int, array{type: string, term: string, position: int}>}
     */
    public static function check(string $text): array
    {
        self::loadRules();
        $violations = [];
        $lowerText = strtolower($text);

        foreach ((array)self::$bannedWordsCache as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $violations[] = [
                    'type' => 'banned_word',
                    'term' => $word,
                    'position' => (int)$m[0][1],
                ];
            }
        }

        foreach ((array)self::$bannedPhrasesCache as $phrase) {
            $pos = stripos($text, $phrase);
            if ($pos !== false) {
                $violations[] = [
                    'type' => 'banned_phrase',
                    'term' => $phrase,
                    'position' => $pos,
                ];
            }
        }

        // Em dash check (U+2014)
        if (str_contains($text, "\u{2014}")) {
            $violations[] = [
                'type' => 'em_dash',
                'term' => '— (em dash)',
                'position' => (int)strpos($text, "\u{2014}"),
            ];
        }

        return [
            'clean' => count($violations) === 0,
            'violations' => $violations,
        ];
    }

    private static function loadRules(): void
    {
        if (self::$bannedWordsCache !== null) {
            return;
        }

        $rows = Database::select(
            "SELECT rule_type, rule_value FROM style_rules WHERE is_active = 1"
        );

        self::$bannedWordsCache = [];
        self::$bannedPhrasesCache = [];

        foreach ($rows as $row) {
            if ($row['rule_type'] === 'banned_word') {
                self::$bannedWordsCache[] = (string)$row['rule_value'];
            } elseif ($row['rule_type'] === 'banned_phrase') {
                self::$bannedPhrasesCache[] = (string)$row['rule_value'];
            }
        }
    }

    /**
     * Reset the cache (useful after admin updates).
     */
    public static function clearCache(): void
    {
        self::$bannedWordsCache = null;
        self::$bannedPhrasesCache = null;
    }
}
