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
 *   /?queue=1              → morning discovery queue
 *   /?insights=1           → weekly insights
 *   /?admin=sections       → admin: manage sections
 *   /?admin=sources        → admin: discovery sources
 *   /?admin=jobs           → admin: scheduled jobs
 *   /?admin=export         → admin: export data
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Auth;
use BWFC\DailyBrief\BriefRepository;

Auth::requireLogin();

$action = $_GET['brief'] ?? null;
$review = isset($_GET['review']);
$archive = isset($_GET['archive']);
$queue = isset($_GET['queue']);
$insights = isset($_GET['insights']);
$journalists = isset($_GET['journalists']);
$people = isset($_GET['people']);
$admin = $_GET['admin'] ?? null;

if ($admin !== null) {
    switch ($admin) {
        case 'sections':
            $view = VIEWS_PATH . '/admin/sections.php';
            $pageTitle = 'Admin - Sections';
            break;
        case 'sources':
            $view = VIEWS_PATH . '/admin/sources.php';
            $pageTitle = 'Admin - Discovery Sources';
            break;
        case 'jobs':
            $view = VIEWS_PATH . '/admin/jobs.php';
            $pageTitle = 'Admin - Scheduled Jobs';
            break;
        case 'squad':
            $view = VIEWS_PATH . '/admin/squad.php';
            $pageTitle = 'Admin - Squad & People';
            break;
        case 'export':
            $view = VIEWS_PATH . '/admin/export.php';
            $pageTitle = 'Admin - Export Data';
            break;
        default:
            $view = VIEWS_PATH . '/admin/index.php';
            $pageTitle = 'Admin';
    }
} elseif ($queue) {
    $view = VIEWS_PATH . '/queue.php';
    $pageTitle = 'Morning Queue';
} elseif ($insights) {
    $view = VIEWS_PATH . '/insights.php';
    $pageTitle = 'Weekly Insights';
} elseif ($journalists) {
    $view = VIEWS_PATH . '/journalists.php';
    $pageTitle = 'Journalists';
} elseif ($people) {
    $view = VIEWS_PATH . '/people.php';
    $pageTitle = 'People in the news';
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
