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

// AI endpoints wait on Claude (summary + classification, etc.). On Windows,
// max_execution_time counts that wait as elapsed time, so the default 30s would
// kill a slow generation mid-request and return a non-JSON error page (the
// client then shows "Invalid JSON response"). Give requests room to finish.
@set_time_limit((int)env('API_TIME_LIMIT', 300));

// API responses must be pure JSON. Keep PHP warnings/notices out of the body
// (they would corrupt the JSON and surface as "Invalid JSON response"); they
// still go to the error log.
@ini_set('display_errors', '0');

// Last line of defence: if a fatal error ends the request, emit a clean JSON
// error instead of a blank/HTML page, so the client shows a real message.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => (env('APP_DEBUG', false) === true)
                ? ('Server error: ' . $e['message'])
                : 'The server ran out of time or memory while processing. Please try again.',
        ]);
    }
});

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
