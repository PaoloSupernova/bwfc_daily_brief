<?php
/**
 * Health check page. Visit this once after setup to confirm everything is wired up.
 *
 * URL: http://localhost/bwfc-daily-brief/public/health.php
 *
 * Delete or restrict this file before deployment.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use BWFC\DailyBrief\Database;

header('Content-Type: text/html; charset=utf-8');

$checks = [];

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$checks[] = [
    'name' => 'PHP 8.1+',
    'ok' => $phpOk,
    'detail' => 'Running ' . PHP_VERSION,
];

// Required extensions
foreach (['curl', 'dom', 'json', 'mbstring', 'pdo', 'pdo_mysql'] as $ext) {
    $checks[] = [
        'name' => 'Extension: ' . $ext,
        'ok' => extension_loaded($ext),
        'detail' => extension_loaded($ext) ? 'Loaded' : 'Missing - enable in php.ini',
    ];
}

// .env exists
$envOk = file_exists(BASE_PATH . '/.env');
$checks[] = [
    'name' => '.env file',
    'ok' => $envOk,
    'detail' => $envOk ? 'Found at ' . BASE_PATH . '/.env' : 'Missing - copy .env.example to .env',
];

// API key
$apiKey = (string)env('ANTHROPIC_API_KEY', '');
$keyOk = str_starts_with($apiKey, 'sk-ant-') && strlen($apiKey) > 30;
$checks[] = [
    'name' => 'Anthropic API key',
    'ok' => $keyOk,
    'detail' => $keyOk ? 'Key looks well-formed (not tested for validity)' : 'Key missing or malformed in .env',
];

// Database connection
try {
    Database::connection();
    $checks[] = ['name' => 'Database connection', 'ok' => true, 'detail' => 'Connected to ' . env('DB_NAME')];

    // Tables exist
    $tables = ['briefs', 'brief_articles', 'sections', 'outlets', 'style_rules', 'prompt_templates', 'audit_log', 'users', 'rss_cache'];
    $existing = array_column(Database::select('SHOW TABLES'), 'Tables_in_' . env('DB_NAME'));
    $missing = array_diff($tables, $existing);
    $checks[] = [
        'name' => 'Schema tables',
        'ok' => count($missing) === 0,
        'detail' => count($missing) === 0 ? count($tables) . ' tables present' : 'Missing: ' . implode(', ', $missing),
    ];

    // Seed data
    $sectionCount = (int)Database::selectOne('SELECT COUNT(*) AS c FROM sections')['c'];
    $promptCount = (int)Database::selectOne('SELECT COUNT(*) AS c FROM prompt_templates')['c'];
    $ruleCount = (int)Database::selectOne('SELECT COUNT(*) AS c FROM style_rules')['c'];

    $seedOk = $sectionCount >= 5 && $promptCount >= 3 && $ruleCount >= 30;
    $checks[] = [
        'name' => 'Seed data',
        'ok' => $seedOk,
        'detail' => "{$sectionCount} sections, {$promptCount} prompts, {$ruleCount} style rules",
    ];
} catch (Throwable $e) {
    $checks[] = ['name' => 'Database connection', 'ok' => false, 'detail' => $e->getMessage()];
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <title>BWFC Daily Brief - Health Check</title>
    <link rel="stylesheet" href="css/app.css">
</head>
<body>
    <div class="site-main">
        <h1 class="heading-display">Health check</h1>
        <p class="lede">
            <?php if ($allOk): ?>
                All checks passed. You can <a href="./">open the dashboard</a>.
            <?php else: ?>
                Some checks failed. Fix the items marked in red below.
            <?php endif; ?>
        </p>

        <div class="editor__section">
            <table class="archive-table">
                <thead>
                    <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></td>
                            <td>
                                <?php if ($c['ok']): ?>
                                    <span class="status-pill status-pill--sent">Pass</span>
                                <?php else: ?>
                                    <span class="status-pill" style="background:#FFE0E0;color:#8B0000;">Fail</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: var(--font-mono); font-size: 13px;"><?= htmlspecialchars($c['detail'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="field__hint" style="margin-top: 24px;">
            Delete <code>public/health.php</code> or restrict access before deployment.
        </p>
    </div>
</body>
</html>
