<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Detects non-English article content and translates it to English with a more
 * capable model (Sonnet) so the usual summarisation/analysis runs on English
 * text and stays consistent. Records the source language so the brief can note
 * that a story was translated.
 *
 * English text is handled by a fast local heuristic first, so English articles
 * never incur an extra API call — translation only runs on genuinely foreign
 * content.
 */
final class Translator
{
    /**
     * @return array{language:string, is_english:bool, text:string}
     */
    public static function toEnglish(string $content, string $headline = ''): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['language' => 'English', 'is_english' => true, 'text' => $content];
        }

        $sample = trim($headline . "\n" . mb_substr($content, 0, 1500));

        // Cheap pre-filter: if it clearly reads as English, skip the AI entirely.
        if (self::looksEnglish($sample)) {
            return ['language' => 'English', 'is_english' => true, 'text' => $content];
        }

        $client = new ClaudeClient();

        // Detect language (cheap, default model).
        try {
            $raw = $client->complete(
                "Identify the language of the following text. Reply with ONLY the English name of the "
                . "language (e.g. English, French, Spanish, German, Italian, Portuguese, Dutch).\n\n" . $sample
            );
        } catch (\Throwable $e) {
            return ['language' => 'English', 'is_english' => true, 'text' => $content];
        }

        $language = ucfirst(strtolower(trim((string)preg_replace('/[^a-zA-Z ]/', '', $raw))));
        if ($language === '' || stripos($language, 'english') !== false) {
            return ['language' => 'English', 'is_english' => true, 'text' => $content];
        }

        // Translate with Sonnet (falls back to the default model if unavailable).
        $model = (string)env('ANTHROPIC_TRANSLATE_MODEL', 'claude-sonnet-4-5');
        $prompt = "Translate the following {$language} news article into natural, fluent British English. "
            . "Preserve every fact, name, quote, figure, score and date exactly. Do not summarise, add or omit "
            . "anything. Return ONLY the English translation, no preamble.\n\n" . mb_substr($content, 0, 8000);

        try {
            $translated = $client->complete($prompt, null, ['model' => $model, 'max_tokens' => 4096]);
        } catch (\Throwable $e) {
            try {
                $translated = $client->complete($prompt, null, ['max_tokens' => 4096]);
            } catch (\Throwable $e2) {
                // Could not translate — keep original text but still flag the language.
                return ['language' => $language, 'is_english' => false, 'text' => $content];
            }
        }

        $translated = trim($translated);
        return [
            'language'   => $language,
            'is_english' => false,
            'text'       => $translated !== '' ? $translated : $content,
        ];
    }

    /**
     * Fast, free heuristic: enough common English function words present means we
     * can safely treat the text as English without an API call.
     */
    private static function looksEnglish(string $text): bool
    {
        $t = ' ' . mb_strtolower($text) . ' ';
        $stop = [' the ', ' and ', ' of ', ' to ', ' in ', ' a ', ' is ', ' for ',
                 ' that ', ' with ', ' on ', ' as ', ' was ', ' at ', ' by ', ' he ', ' it '];
        $hits = 0;
        foreach ($stop as $w) {
            if (str_contains($t, $w)) {
                $hits++;
                if ($hits >= 4) {
                    return true;
                }
            }
        }
        return false;
    }
}
