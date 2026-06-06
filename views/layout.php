<?php
declare(strict_types=1);

use BWFC\DailyBrief\Auth;

$user = Auth::currentUser();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/**
 * Build a cache-busted URL for a local asset under /public.
 * Appends ?v=<file-modification-time> so browsers fetch a fresh copy
 * whenever the file changes, but cache aggressively when it hasn't.
 */
$asset = function (string $relativePath) use ($basePath): string {
    $url = $basePath . '/' . ltrim($relativePath, '/');
    $file = (defined('PUBLIC_PATH') ? PUBLIC_PATH : __DIR__ . '/../public') . '/' . ltrim($relativePath, '/');
    $version = is_file($file) ? (string)filemtime($file) : (string)time();
    return $url . '?v=' . $version;
};
?><!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? 'BWFC Daily Brief', ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($asset('css/app.css'), ENT_QUOTES) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Archivo+Narrow:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        window.BWFC_BASE = '<?= $basePath ?>';
    </script>
    <script src="<?= htmlspecialchars($asset('js/app.js'), ENT_QUOTES) ?>"></script>
    <script src="<?= htmlspecialchars($asset('js/archive.js'), ENT_QUOTES) ?>"></script>
    <script src="<?= htmlspecialchars($asset('js/dashboard.js'), ENT_QUOTES) ?>"></script>
    <script src="<?= htmlspecialchars($asset('js/queue.js'), ENT_QUOTES) ?>"></script>
    <script src="<?= htmlspecialchars($asset('js/admin.js'), ENT_QUOTES) ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body>
    <header class="site-header">
        <div class="site-header__inner">
            <a href="<?= $basePath ?>/" class="site-header__brand">
                <img src="<?= $basePath ?>/img/bwfc-marque.png" alt="Bolton Wanderers" class="site-header__marque">
                <div class="site-header__title">
                    <div class="site-header__title-main">Daily Brief</div>
                    <div class="site-header__title-sub">Communications Team Tool</div>
                </div>
            </a>
            <nav class="site-nav">
                <a href="<?= $basePath ?>/" class="site-nav__link">Dashboard</a>
                <a href="<?= $basePath ?>/?brief=new" class="site-nav__link site-nav__link--primary">New Brief</a>
                <a href="<?= $basePath ?>/?queue=1" class="site-nav__link <?= ($queue ?? false) ? 'is-active' : '' ?>">Queue</a>
                <a href="<?= $basePath ?>/?archive=1" class="site-nav__link">Archive</a>
                <a href="<?= $basePath ?>/?insights=1" class="site-nav__link <?= ($insights ?? false) ? 'is-active' : '' ?>">Insights</a>
                <a href="<?= $basePath ?>/?admin=sections" class="site-nav__link <?= (($admin ?? '') !== '') ? 'is-active' : '' ?>">Admin</a>
                <?php if ($user !== null): ?>
                    <span class="site-nav__user"><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></span>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="site-main">
        <?php require $view; ?>
    </main>

    <footer class="site-footer">
        <div class="site-footer__inner">
            <span>BWFC Daily Brief Tool &middot; Internal use only</span>
            <span class="site-footer__meta">v1.6 &middot; <?= date('Y') ?></span>
        </div>
    </footer>
</body>
</html>
