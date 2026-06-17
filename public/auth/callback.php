<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

use BWFC\DailyBrief\Auth;

if (!Auth::enabled()) {
    header('Location: ' . rtrim((string)env('APP_URL', '/'), '/') . '/');
    exit;
}

try {
    $returnTo = Auth::handleCallback();
} catch (\RuntimeException $e) {
    http_response_code(403);
    $message = htmlspecialchars($e->getMessage(), ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8"><title>Login failed — BWFC Daily Brief</title>'
        . '<style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px}'
        . 'h1{color:#19223D}.msg{background:#fff3cd;border:1px solid #ffc107;padding:16px;border-radius:4px}'
        . 'a{color:#003976}</style></head><body>'
        . '<h1>Login failed</h1>'
        . '<div class="msg"><p>' . $message . '</p></div>'
        . '<p><a href="' . htmlspecialchars(rtrim((string)env('APP_URL', '/'), '/') . '/', ENT_QUOTES) . '">Return to home</a></p>'
        . '</body></html>';
    exit;
}

$appUrl = rtrim((string)env('APP_URL', ''), '/');
if (str_starts_with($returnTo, '/')) {
    header('Location: ' . $appUrl . $returnTo);
} else {
    header('Location: ' . $appUrl . '/');
}
exit;
