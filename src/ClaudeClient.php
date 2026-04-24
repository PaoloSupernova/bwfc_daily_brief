<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use RuntimeException;

/**
 * Anthropic Claude API client.
 *
 * Minimal wrapper around the Messages API. Uses cURL, no external deps.
 */
final class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private string $apiKey;
    private string $model;
    private int $maxTokens;

    public function __construct()
    {
        $this->apiKey = (string)env('ANTHROPIC_API_KEY', '');
        $this->model = (string)env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
        $this->maxTokens = (int)env('ANTHROPIC_MAX_TOKENS', 1024);

        if ($this->apiKey === '' || !str_starts_with($this->apiKey, 'sk-ant-')) {
            throw new RuntimeException('Anthropic API key missing or malformed. Check your .env file.');
        }
    }

    /**
     * Send a prompt to Claude and return the text response.
     *
     * @param string $userMessage The prompt content
     * @param string|null $systemPrompt Optional system prompt (defaults to BWFC context)
     * @return string Claude's response text
     */
    public function complete(string $userMessage, ?string $systemPrompt = null): string
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        $response = $this->request($payload);

        if (!isset($response['content'][0]['text'])) {
            throw new RuntimeException('Claude response malformed: no text block returned');
        }

        return trim($response['content'][0]['text']);
    }

    /**
     * Perform the HTTP request to Anthropic.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(array $payload): array
    {
        $ch = curl_init(self::API_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Claude API request failed: ' . $curlError);
        }

        $decoded = json_decode((string)$body, true);

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("Claude API error ({$httpCode}): {$message}");
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Claude API returned invalid JSON');
        }

        return $decoded;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
