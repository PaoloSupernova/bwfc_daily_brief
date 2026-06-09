<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Parses RSS 2.0, Atom 1.0, and Google News RSS feeds into a uniform shape.
 *
 * Returns array of items, each with: url, headline, description, outlet_name,
 * published_at (ISO 8601 string or null).
 *
 * Google News RSS has a quirk: the "source" element gives the originating outlet
 * (e.g. "BBC Sport") and the description is HTML with a link to the actual article.
 * Standard RSS uses <link> for the URL and <source> may be absent.
 */
final class FeedParser
{
    /**
     * Fetch and parse a feed.
     *
     * @return array<int, array{url: string, headline: string, description: ?string, outlet_name: ?string, published_at: ?string}>
     */
    public static function fetch(string $url, string $sourceType = 'rss', int $timeoutSeconds = 15): array
    {
        $body = self::httpGet($url, $timeoutSeconds);
        return self::parse($body, $sourceType);
    }

    /**
     * Parse raw XML body. Public for unit-testability.
     *
     * @return array<int, array{url: string, headline: string, description: ?string, outlet_name: ?string, published_at: ?string}>
     */
    public static function parse(string $xml, string $sourceType): array
    {
        if (trim($xml) === '') return [];

        $doc = new DOMDocument();
        // Suppress libxml warnings - feeds in the wild often have minor issues
        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$loaded) return [];

        $xpath = new DOMXPath($doc);

        // Detect format. Atom has <feed> root; RSS has <rss>/<channel>
        $isAtom = $doc->documentElement && $doc->documentElement->localName === 'feed';

        if ($isAtom) {
            return self::parseAtom($xpath);
        }

        if ($sourceType === 'google_news') {
            return self::parseGoogleNews($xpath);
        }

        return self::parseRss($xpath);
    }

    /**
     * Standard RSS 2.0 parsing.
     */
    private static function parseRss(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query('//item') ?: [] as $node) {
            $url = self::firstText($xpath, 'link', $node);
            $title = self::firstText($xpath, 'title', $node);
            if ($url === '' || $title === '') continue;

            $description = self::firstText($xpath, 'description', $node);
            $pubDate = self::firstText($xpath, 'pubDate', $node);
            $sourceName = self::firstText($xpath, 'source', $node);

            $items[] = [
                'url' => $url,
                'headline' => self::clean($title),
                'description' => $description !== '' ? self::clean(strip_tags($description)) : null,
                'outlet_name' => $sourceName !== '' ? self::clean($sourceName) : null,
                'published_at' => self::normaliseDate($pubDate),
            ];
        }
        return $items;
    }

    /**
     * Atom 1.0 parsing.
     */
    private static function parseAtom(DOMXPath $xpath): array
    {
        // Register Atom namespace - Atom feeds use a default xmlns
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        $items = [];
        foreach ($xpath->query('//atom:entry') ?: [] as $node) {
            $title = self::firstText($xpath, 'atom:title', $node);

            // Atom <link> uses href attribute, not text content
            $url = '';
            $linkNodes = $xpath->query('atom:link', $node);
            if ($linkNodes !== false) {
                foreach ($linkNodes as $linkNode) {
                    $rel = $linkNode->attributes->getNamedItem('rel');
                    if ($rel === null || $rel->nodeValue === 'alternate') {
                        $href = $linkNode->attributes->getNamedItem('href');
                        if ($href !== null) {
                            $url = $href->nodeValue;
                            break;
                        }
                    }
                }
            }

            if ($url === '' || $title === '') continue;

            $summary = self::firstText($xpath, 'atom:summary', $node);
            if ($summary === '') {
                $summary = self::firstText($xpath, 'atom:content', $node);
            }
            $published = self::firstText($xpath, 'atom:published', $node);
            if ($published === '') {
                $published = self::firstText($xpath, 'atom:updated', $node);
            }

            $items[] = [
                'url' => $url,
                'headline' => self::clean($title),
                'description' => $summary !== '' ? self::clean(strip_tags($summary)) : null,
                'outlet_name' => null,
                'published_at' => self::normaliseDate($published),
            ];
        }
        return $items;
    }

    /**
     * Google News RSS parsing. Items have <source> with the originating outlet.
     */
    private static function parseGoogleNews(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query('//item') ?: [] as $node) {
            $url = self::firstText($xpath, 'link', $node);
            $title = self::firstText($xpath, 'title', $node);
            if ($url === '' || $title === '') continue;

            $description = self::firstText($xpath, 'description', $node);
            $pubDate = self::firstText($xpath, 'pubDate', $node);
            $sourceName = self::firstText($xpath, 'source', $node);

            // Google News titles often come as "Headline - Outlet Name". Strip if so
            // and use the parsed source as outlet.
            $cleanTitle = $title;
            if ($sourceName !== '' && str_ends_with($title, ' - ' . $sourceName)) {
                $cleanTitle = substr($title, 0, strlen($title) - strlen(' - ' . $sourceName));
            }

            $items[] = [
                'url' => $url,
                'headline' => self::clean($cleanTitle),
                'description' => $description !== '' ? self::clean(strip_tags($description)) : null,
                'outlet_name' => $sourceName !== '' ? self::clean($sourceName) : null,
                'published_at' => self::normaliseDate($pubDate),
            ];
        }
        return $items;
    }

    private static function firstText(DOMXPath $xpath, string $query, $context): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) return '';
        $text = $nodes->item(0)->nodeValue ?? '';
        return trim($text);
    }

    private static function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private static function normaliseDate(string $s): ?string
    {
        if ($s === '') return null;
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Tiny HTTP fetch with timeout. Sets a polite User-Agent so feed providers
     * don't block us as suspicious traffic.
     */
    private static function httpGet(string $url, int $timeoutSeconds): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'BWFC-DailyBrief/2.0 (+communications team tool)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Feed fetch failed: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Feed returned HTTP {$httpCode}");
        }
        return is_string($body) ? $body : '';
    }
}
