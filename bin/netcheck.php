<?php
/**
 * Outbound connectivity diagnostic.
 *
 * Run on the server that hosts the app:
 *     php bin\netcheck.php
 * (or: C:\xampp_new\php\php.exe bin\netcheck.php)
 *
 * It tries to reach the Claude API and a few news sites over the default route,
 * forced IPv4, and forced IPv6 — using the same cURL stack the app uses — and
 * prints how each attempt went. This tells us whether the problem is broken
 * IPv6 (IPv4 works, default/IPv6 hangs) or a firewall block (IPv4 also fails).
 */

declare(strict_types=1);

$proxy = getenv('FETCH_PROXY') ?: '';
$envFile = __DIR__ . '/../.env';
if ($proxy === '' && is_file($envFile)) {
    foreach (file($envFile) as $line) {
        if (preg_match('/^\s*FETCH_PROXY\s*=\s*(.+)\s*$/', $line, $m)) {
            $proxy = trim($m[1], " \"'");
        }
    }
}

$targets = [
    'https://api.anthropic.com/v1/messages',
    'https://www.bbc.co.uk/sport',
    'https://www.theguardian.com/football',
    'https://www.theboltonnews.co.uk',
];

$modes = [
    'default' => null,
    'ipv4'    => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : null,
    'ipv6'    => defined('CURL_IPRESOLVE_V6') ? CURL_IPRESOLVE_V6 : null,
];

echo "cURL version: " . (curl_version()['version'] ?? '?') . " (ssl: " . (curl_version()['ssl_version'] ?? '?') . ")\n";
echo $proxy !== '' ? "Proxy configured: {$proxy}\n" : "Proxy: none\n";
echo str_repeat('-', 74) . "\n";
printf("%-28s %-8s %8s  %s\n", 'HOST', 'ROUTE', 'TIME', 'RESULT');
echo str_repeat('-', 74) . "\n";

foreach ($targets as $url) {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    foreach ($modes as $mode => $res) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (netcheck)',
        ]);
        if ($res !== null) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, $res);
        }
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $t = microtime(true);
        $ok = curl_exec($ch);
        $ms = (int)round((microtime(true) - $t) * 1000);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = $ok !== false
            ? "OK  (HTTP {$code} via {$ip})"
            : "FAIL  {$err} [curl {$errno}]";
        printf("%-28s %-8s %6dms  %s\n", $host, $mode, $ms, $result);
    }
    echo "\n";
}

echo "Reading: for each host, compare the ipv4 row to the default/ipv6 rows.\n";
echo " - ipv4 OK but default/ipv6 FAIL  => broken IPv6 route (the IPv4 fix solves it).\n";
echo " - ipv4 also FAIL                 => a firewall is blocking outbound 443 (needs IT / a proxy).\n";
