<?php
declare(strict_types=1);

use BWFC\DailyBrief\Auth;

$user = Auth::currentUser();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?><!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? 'BWFC Daily Brief', ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="<?= $basePath ?>/css/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Archivo+Narrow:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        window.BWFC_BASE = '<?= $basePath ?>';
    </script>
    <script src="<?= $basePath ?>/js/app.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body>
    <header class="site-header">
        <div class="site-header__inner">
            <a href="<?= $basePath ?>/" class="site-header__brand">
                <img src="<?= $basePath ?>/img/bwfc-marque.svg" alt="Bolton Wanderers" class="site-header__marque">
                <div class="site-header__title">
                    <div class="site-header__title-main">Daily Brief</div>
                    <div class="site-header__title-sub">Communications Team Tool</div>
                </div>
            </a>
            <nav class="site-nav">
                <a href="<?= $basePath ?>/" class="site-nav__link">Dashboard</a>
                <a href="<?= $basePath ?>/?brief=new" class="site-nav__link site-nav__link--primary">New Brief</a>
                <a href="<?= $basePath ?>/?archive=1" class="site-nav__link">Archive</a>
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
            <span class="site-footer__meta">v1.0 &middot; <?= date('Y') ?></span>
        </div>
    </footer>
</body>
</html>