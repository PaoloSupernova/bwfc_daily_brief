<?php
/**
 * BWFC Daily Brief - Entry point and router.
 *
 * Routes:
 *   /                     → dashboard (list of briefs + quick-start)
 *   /?brief=new           → new brief for today (or open today's draft)
 *   /?brief=<id>          → edit existing brief (or view if sent)
 *   /?archive=1           → archive page (phase 2)
 *   /?admin=<page>        → admin UI (phase 2)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;

Auth::requireLogin();

$action = $_GET['brief'] ?? null;
$archive = isset($_GET['archive']);
$admin = $_GET['admin'] ?? null;

if ($action === 'new') {
    // Open today's draft or create a placeholder view (brief record created on first article save)
    $today = date('Y-m-d');
    $existing = BriefRepository::findBriefByDate($today);
    if ($existing !== null) {
        header('Location: ?brief=' . (int)$existing['id']);
        exit;
    }
    $view = VIEWS_PATH . '/brief/new.php';
    $pageTitle = 'New Daily Brief - ' . date('l jS F Y');
} elseif ($action !== null && ctype_digit((string)$action)) {
    $briefId = (int)$action;
    $brief = BriefRepository::findBrief($briefId);
    if ($brief === null) {
        http_response_code(404);
        echo '<p>Brief not found. <a href="./">Back to dashboard</a>.</p>';
        exit;
    }
    $articles = BriefRepository::articlesForBrief($briefId);
    $isLocked = $brief['status'] === 'sent';
    $view = $isLocked ? VIEWS_PATH . '/brief/view.php' : VIEWS_PATH . '/brief/edit.php';
    $pageTitle = ($isLocked ? 'Daily Brief' : 'Edit Daily Brief') . ' - ' . date('l jS F Y', strtotime($brief['brief_date']));
} elseif ($archive) {
    $view = VIEWS_PATH . '/archive/index.php';
    $pageTitle = 'Archive';
} else {
    $view = VIEWS_PATH . '/dashboard.php';
    $pageTitle = 'Daily Brief Dashboard';
}

require VIEWS_PATH . '/layout.php';
