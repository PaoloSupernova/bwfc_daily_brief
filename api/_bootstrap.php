<?php
/**
 * API endpoint bootstrap.
 *
 * Loads config, sets JSON headers, handles errors uniformly,
 * ensures auth (no-op locally).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Auth::requireLogin();

/**
 * Return a JSON success response and exit.
 */
function api_success(array $data = [], int $code = 200): never
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Return a JSON error response and exit.
 */
function api_error(string $message, int $code = 400, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Decode JSON POST body into an associative array.
 *
 * @return array<string, mixed>
 */
function api_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

set_exception_handler(function (Throwable $e): void {
    if (env('APP_DEBUG', false) === true) {
        api_error($e->getMessage(), 500, ['trace' => $e->getTraceAsString()]);
    }
    api_error('Unexpected server error', 500);
});
