<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

use BWFC\DailyBrief\Auth;

if (!Auth::enabled()) {
    session_destroy();
    header('Location: ' . rtrim((string)env('APP_URL', '/'), '/') . '/');
    exit;
}

Auth::logout();
