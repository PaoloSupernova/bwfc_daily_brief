<?php
/**
 * Dev helper: flush OPcache for the web server (mod_php) so freshly-pulled
 * PHP files are recompiled. Visit this in the browser, then reload the app.
 *
 * Safe to delete once you no longer need it.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "Loaded php.ini: " . (php_ini_loaded_file() ?: '(none)') . "\n";
echo "SAPI: " . PHP_SAPI . "\n";

if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    echo "OPcache enabled: " . (is_array($status) && !empty($status['opcache_enabled']) ? 'yes' : 'no') . "\n";
    if (is_array($status) && isset($status['opcache_statistics'])) {
        echo "Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? '?') . "\n";
    }
} else {
    echo "OPcache extension: not loaded\n";
}

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo "\nopcache_reset(): " . ($ok ? 'OK — cache cleared' : 'failed') . "\n";
} else {
    echo "\nopcache_reset() not available (OPcache off) — nothing to clear\n";
}

// Show which BriefRenderer file is actually loaded and whether it's the v2 layout.
$rendererFile = __DIR__ . '/../src/BriefRenderer.php';
echo "\nBriefRenderer path: " . realpath($rendererFile) . "\n";
$src = @file_get_contents($rendererFile) ?: '';
echo "Contains v2 marker: " . (strpos($src, 'brief-render layout v2') !== false ? 'YES' : 'NO — old file on disk') . "\n";
