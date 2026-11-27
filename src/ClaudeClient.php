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
     * @param string|null $systemPrompt Optional system prompt
     * @param array<string,mixed> $options Per-call overrides: 'model', 'max_tokens'
     * @return string Claude's response text
     */
    public function complete(string $userMessage, ?string $systemPrompt = null, array $options = []): string
    {
        $payload = [
            'model' => (string)($options['model'] ?? $this->model),
            'max_tokens' => (int)($options['max_tokens'] ?? $this->maxTokens),
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
                $msg = strtolower($e->getMessage());

                $isTransient = str_contains($msg, 'temporarily busy')
                    || str_contains($msg, '529')
                    || str_contains($msg, 'timeout')
                    || str_contains($msg, 'timed out')
                    || str_contains($msg, 'connection refused')
                    || str_contains($msg, 'could not resolve host')
                    || str_contains($msg, 'failed to connect')
                    || str_contains($msg, 'reset')            // connection reset by peer
                    || str_contains($msg, 'recv failure')
                    || str_contains($msg, 'send failure')
                    || str_contains($msg, 'empty reply')
                    || str_contains($msg, 'transfer closed')
                    || str_contains($msg, 'ssl connect')
                    || str_contains($msg, 'broken pipe');

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

    /**
     * Run several independent prompts CONCURRENTLY (one HTTP connection each,
     * in parallel via curl_multi) and return their texts keyed the same way.
     *
     * This is the big latency win for article processing: the summary, the
     * section/sentiment/topic classification and the duplicate check no longer
     * run back-to-back — they overlap, so total wall-clock ≈ the slowest single
     * call instead of their sum. Any call that fails (including transiently) is
     * retried once, sequentially, through the normal retry/backoff path so the
     * result is as robust as a plain complete().
     *
     * @param array<string,array{prompt:string,system?:?string,options?:array<string,mixed>}> $jobs
     * @return array<string,string>  key => response text
     */
    public function completeConcurrent(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }
        if (count($jobs) === 1) {
            // No benefit to curl_multi for a single job; keep full retry semantics.
            $key = array_key_first($jobs);
            $job = $jobs[$key];
            return [$key => $this->complete((string)$job['prompt'], $job['system'] ?? null, $job['options'] ?? [])];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($jobs as $key => $job) {
            $ch = $this->buildHandle($this->buildPayload((string)$job['prompt'], $job['system'] ?? null, $job['options'] ?? []));
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        // Drive all transfers to completion. curl_multi_select returns -1 on
        // some platforms (notably Windows) when there is nothing to wait on; a
        // short sleep then avoids a CPU busy-loop.
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                if (curl_multi_select($mh, 1.0) === -1) {
                    usleep(50000); // 50ms
                }
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        $failed = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            try {
                if ($body === null || $body === '') {
                    throw new RuntimeException('Claude API request failed: ' . ($err !== '' ? $err : 'empty response'));
                }
                $results[$key] = $this->extractText($this->decodeBody((string)$body, $code));
            } catch (RuntimeException $e) {
                $failed[$key] = true;
            }
        }
        curl_multi_close($mh);

        // Retry any failures once, sequentially, with the normal backoff path.
        foreach (array_keys($failed) as $key) {
            $job = $jobs[$key];
            $results[$key] = $this->complete((string)$job['prompt'], $job['system'] ?? null, $job['options'] ?? []);
        }

        return $results;
    }

    /**
     * Build the request payload for a single prompt.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildPayload(string $userMessage, ?string $systemPrompt, array $options): array
    {
        $payload = [
            'model' => (string)($options['model'] ?? $this->model),
            'max_tokens' => (int)($options['max_tokens'] ?? $this->maxTokens),
            'messages' => [['role' => 'user', 'content' => $userMessage]],
        ];
        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }
        return $payload;
    }

    /**
     * Build a configured cURL handle for a payload (shared by single + multi).
     *
     * @param array<string,mixed> $payload
     * @return \CurlHandle
     */
    private function buildHandle(array $payload): \CurlHandle
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
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

        $ch = curl_init(self::API_URL);
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
        return $ch;
    }

    /**
     * Decode an API response body, throwing on HTTP/transient/JSON errors
     * (same error semantics as request()).
     *
     * @return array<string,mixed>
     */
    private function decodeBody(string $body, int $httpCode): array
    {
        $decoded = json_decode($body, true);
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

    /** @param array<string,mixed> $response */
    private function extractText(array $response): string
    {
        if (!isset($response['content'][0]['text'])) {
            throw new RuntimeException('Claude response malformed: no text block returned');
        }
        return trim($response['content'][0]['text']);
    }

    /**
     * Multi-turn conversation: send a full messages array (each
     * ['role'=>'user'|'assistant','content'=>string]) and return the reply text.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options Per-call overrides: 'model', 'max_tokens'
     */
    public function completeMessages(array $messages, ?string $systemPrompt = null, array $options = []): string
    {
        $payload = [
            'model' => (string)($options['model'] ?? $this->model),
            'max_tokens' => (int)($options['max_tokens'] ?? $this->maxTokens),
            'messages' => array_values($messages),
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

    public function getModel(): string
    {
        return $this->model;
    }
}
