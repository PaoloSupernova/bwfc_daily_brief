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

        $response = $this->requestWithRetry($payload);

        if (!isset($response['content'][0]['text'])) {
            throw new RuntimeException('Claude response malformed: no text block returned');
        }

        return trim($response['content'][0]['text']);
    }

    /**
     * Retry on transient failures: network timeouts, connection errors, and
     * HTTP 529 overload responses. Up to 3 attempts with exponential backoff
     * (2 s, 5 s, 10 s) so a brief routing hiccup doesn't surface as an error.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestWithRetry(array $payload): array
    {
        $delays = [2, 5, 10];
        $attempt = 0;

        while (true) {
            try {
                return $this->request($payload);
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();

                $isTransient = str_contains($msg, 'temporarily busy')
                    || str_contains($msg, '529')
                    || str_contains($msg, 'Timeout')
                    || str_contains($msg, 'timed out')
                    || str_contains($msg, 'Connection refused')
                    || str_contains($msg, 'Could not resolve host')
                    || str_contains($msg, 'Failed to connect');

                if (!$isTransient || $attempt >= count($delays)) {
                    throw $e;
                }

                sleep($delays[$attempt]);
                $attempt++;
            }
        }
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

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Fallback: strip non-UTF-8 bytes and retry encoding
            array_walk_recursive($payload, function (&$v) {
                if (is_string($v)) {
                    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
                }
            });
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        if ($json === false || $json === '') {
            throw new RuntimeException('Failed to encode request payload as JSON: ' . json_last_error_msg());
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT        => (int)env('ANTHROPIC_TIMEOUT', 120),
            CURLOPT_CONNECTTIMEOUT => (int)env('ANTHROPIC_CONNECT_TIMEOUT', 30),
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
            if ($httpCode === 529 || str_contains(strtolower((string)$message), 'overload')) {
                throw new RuntimeException('The AI service is temporarily busy. Please wait a moment and try again.');
            }
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
