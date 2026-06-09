<?php
/**
 * BWFC Daily Brief - Configuration bootstrap
 *
 * Loads environment variables, sets timezone, registers autoloader.
 */

declare(strict_types=1);

// Paths
define('BASE_PATH', dirname(__DIR__));
define('SRC_PATH', BASE_PATH . '/src');
define('VIEWS_PATH', BASE_PATH . '/views');
define('PUBLIC_PATH', BASE_PATH . '/public');

// Composer autoload (if installed)
$autoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Fallback PSR-4 loader for src/
    spl_autoload_register(function (string $class): void {
        $prefix = 'BWFC\\DailyBrief\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = SRC_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Load .env
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
        $dotenv->load();
    } else {
        // Minimal fallback parser if dotenv package missing
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$k])) {
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }
}

// Helper for reading env vars with defaults
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return match (strtolower((string)$value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}

// Timezone
date_default_timezone_set((string)env('APP_TIMEZONE', 'Europe/London'));

// Error handling
if (env('APP_DEBUG', false) === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
