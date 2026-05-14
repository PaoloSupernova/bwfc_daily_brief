<?php
/**
 * BWFC Daily Brief - Database configuration
 *
 * Returns PDO connection parameters loaded from environment.
 */

declare(strict_types=1);

return [
    'host' => (string)env('DB_HOST', '127.0.0.1'),
    'port' => (int)env('DB_PORT', 3306),
    'dbname' => (string)env('DB_NAME', 'bwfc_daily_brief'),
    'user' => (string)env('DB_USER', 'root'),
    'pass' => (string)env('DB_PASS', ''),
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
];
