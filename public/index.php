<?php
/**
 * BWFC Daily Brief - Entry point and router.
 *
 * Routes:
 *   /                      → dashboard
 *   /?brief=new            → new brief for today
 *   /?brief=<id>           → edit existing brief
 *   /?brief=<id>&review=1  → final review screen (edit + export)
 *   /?archive=1            → archive page
 *   /?admin=sections       → admin: manage sections
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;

Auth::requireLogin();

$action = $_GET['brief'] ?? null;
$review = isset($_GET['review']);
$archive = isset($_GET['archive']);
$admin = $_GET['admin'] ?? null;

if ($admin !== null) {
    switch ($admin) {
        case 'sections':
            $view = VIEWS_PATH . '/admin/sections.php';
            $pageTitle = 'Admin - Sections';
            break;
        default:
            $view = VIEWS_PATH . '/admin/index.php';
            $pageTitle = 'Admin';
    }
} elseif ($action === 'new') {
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

    if ($review) {
        $view = VIEWS_PATH . '/brief/review.php';
        $pageTitle = 'Review - ' . date('l jS F Y', strtotime($brief['brief_date']));
    } else {
        $view = $isLocked ? VIEWS_PATH . '/brief/view.php' : VIEWS_PATH . '/brief/edit.php';
        $pageTitle = ($isLocked ? 'Daily Brief' : 'Edit Daily Brief') . ' - ' . date('l jS F Y', strtotime($brief['brief_date']));
    }
} elseif ($archive) {
    $view = VIEWS_PATH . '/archive/index.php';
    $pageTitle = 'Archive';
} else {
    $view = VIEWS_PATH . '/dashboard.php';
    $pageTitle = 'Daily Brief Dashboard';
}

require VIEWS_PATH . '/layout.php';
