<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Fetches an article from a URL and extracts readable content.
 *
 * Strategy: cURL the HTML, strip scripts/styles/nav, extract from <article>,
 * <main>, or heuristically from the densest text block. Returns headline,
 * content, and detected outlet.
 */
final class ArticleFetcher
{
    /**
     * @return array{success: bool, headline: string, content: string, outlet: string, domain: string, error: ?string, paywalled: bool}
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        $domain = $this->extractDomain($url);

        $outlet = $this->detectOutlet($domain);
        $paywalled = $this->isPaywalled($domain);

        $base = [
            'headline' => '',
            'content' => '',
            'byline_raw' => '',
            'outlet' => $outlet,
            'domain' => $domain,
            'paywalled' => $paywalled,
        ];

        try {
            $html = $this->downloadHtml($url);
            if ($html === '') {
                return array_merge($base, ['success' => false, 'error' => 'Empty response from URL']);
            }

            $headline = $this->extractHeadline($html);
            $content = $this->extractContent($html);
            $byline = $this->extractByline($html);

            if ($content === '' || strlen($content) < 100) {
                return array_merge($base, [
                    'success' => false,
                    'headline' => $headline,
                    'byline_raw' => $byline,
                    'error' => 'Article content could not be extracted automatically. Use the paste fallback.',
                ]);
            }

            return [
                'success' => true,
                'headline' => $headline,
                'content' => $content,
                'byline_raw' => $byline,
                'outlet' => $outlet,
                'domain' => $domain,
                'paywalled' => $paywalled,
                'error' => null,
            ];
        } catch (RuntimeException $e) {
            return array_merge($base, ['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function downloadHtml(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid URL');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int)env('FETCH_TIMEOUT', 10),
            CURLOPT_USERAGENT => (string)env('FETCH_USER_AGENT', 'Mozilla/5.0 (compatible; BWFC-DailyBrief/1.0)'),
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-GB,en;q=0.9',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // XAMPP often lacks CA bundle
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Fetch failed: ' . $err);
        }

        if ($code >= 400) {
            throw new RuntimeException("HTTP {$code} returned (site may block scrapers or require login)");
        }

        return (string)$body;
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        return strtolower(preg_replace('/^www\./', '', $host));
    }

    private function detectOutlet(string $domain): string
    {
        if ($domain === '') {
            return 'Unknown';
        }

        // Exact match
        $row = Database::selectOne(
            'SELECT display_name FROM outlets WHERE domain = :domain LIMIT 1',
            ['domain' => $domain]
        );
        if ($row !== null) {
            return (string)$row['display_name'];
        }

        // Try stripping subdomains one at a time (e.g. sport.bbc.co.uk → bbc.co.uk)
        $parts = explode('.', $domain);
        while (count($parts) > 2) {
            array_shift($parts);
            $candidate = implode('.', $parts);
            $row = Database::selectOne(
                'SELECT display_name FROM outlets WHERE domain = :domain LIMIT 1',
                ['domain' => $candidate]
            );
            if ($row !== null) {
                return (string)$row['display_name'];
            }
        }

        // Fall back to domain as-is
        return $domain;
    }

    private function isPaywalled(string $domain): bool
    {
        $row = Database::selectOne(
            'SELECT is_paywalled FROM outlets WHERE domain = :domain LIMIT 1',
            ['domain' => $domain]
        );
        return $row !== null && (int)$row['is_paywalled'] === 1;
    }

    private function extractHeadline(string $html): string
    {
        // Prefer Open Graph title, then Twitter card.
        $title = $this->metaContent($html, 'property', 'og:title')
              ?? $this->metaContent($html, 'name', 'twitter:title');

        if ($title !== null) {
            return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Then <title>
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * Extract a <meta> tag's content value, matching the identifying attribute
     * ($attr=$value, e.g. property="og:title") in any position within the tag.
     *
     * The content value is captured using a back-referenced delimiter — (["\'])
     * then \1 — so an attribute value that contains the *opposite* quote
     * character reads in full. The previous [^"\']+ pattern truncated at the
     * first apostrophe, so a double-quoted value like
     *   content="Bolton's new signing"
     * was captured as just "Bolton". Outlets that HTML-encode the apostrophe were
     * unaffected, which is why only some sources (e.g. The Bolton News) showed
     * clipped headlines.
     */
    private function metaContent(string $html, string $attr, string $value): ?string
    {
        if (!preg_match_all('/<meta\b[^>]*>/is', $html, $tags)) {
            return null;
        }

        $attrQ  = preg_quote($attr, '/');
        $valueQ = preg_quote($value, '/');

        foreach ($tags[0] as $tag) {
            // Does this meta tag identify itself as the one we want?
            if (!preg_match('/\b' . $attrQ . '=(["\'])' . $valueQ . '\1/i', $tag)) {
                continue;
            }
            // Pull its content value, honouring the delimiting quote.
            if (preg_match('/\bcontent=(["\'])(.*?)\1/is', $tag, $cm)) {
                return $cm[2];
            }
        }

        return null;
    }

    /**
     * Extract the article byline (author names), preferring reliable sources:
     *   1. JSON-LD structured data (schema.org Article/NewsArticle author)
     *   2. <meta name="author"> / <meta property="article:author">
     *   3. Common byline CSS classes / rel="author" links
     *
     * Returns a raw string (names joined by " and ") for downstream parsing,
     * or '' when no author can be found.
     */
    private function extractByline(string $html): string
    {
        // 1. JSON-LD — the most structured and reliable source.
        $fromJsonLd = $this->bylineFromJsonLd($html);
        if ($fromJsonLd !== '') {
            return $fromJsonLd;
        }

        // 2. Meta tags.
        $meta = $this->metaContent($html, 'name', 'author')
             ?? $this->metaContent($html, 'property', 'article:author');
        if ($meta !== null) {
            $meta = trim(html_entity_decode($meta, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // article:author is sometimes a URL rather than a name — ignore those.
            if ($meta !== '' && !preg_match('#^https?://#i', $meta)) {
                return $meta;
            }
        }

        // 3. Byline CSS classes / rel="author".
        $fromDom = $this->bylineFromDom($html);
        if ($fromDom !== '') {
            return $fromDom;
        }

        return '';
    }

    /**
     * Pull author name(s) out of any application/ld+json blocks. Handles author
     * as a string, an object with a name, an array of either, and @graph nesting.
     */
    private function bylineFromJsonLd(string $html): string
    {
        if (!preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $blocks
        )) {
            return '';
        }

        foreach ($blocks[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) {
                continue;
            }

            // Normalise to a list of nodes to inspect (handles @graph and top-level arrays).
            $nodes = [];
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                $nodes = $data['@graph'];
            } elseif (array_is_list($data)) {
                $nodes = $data;
            } else {
                $nodes = [$data];
            }

            foreach ($nodes as $node) {
                if (!is_array($node) || !isset($node['author'])) {
                    continue;
                }
                $names = $this->namesFromAuthorField($node['author']);
                if ($names !== []) {
                    return implode(' and ', $names);
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $author
     * @return array<int, string>
     */
    private function namesFromAuthorField($author): array
    {
        $names = [];

        if (is_string($author)) {
            $author = trim($author);
            if ($author !== '') {
                $names[] = $author;
            }
        } elseif (is_array($author)) {
            if (isset($author['name']) && is_string($author['name'])) {
                // Single author object.
                $n = trim($author['name']);
                if ($n !== '') {
                    $names[] = $n;
                }
            } else {
                // List of authors (objects or strings).
                foreach ($author as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $names[] = trim($entry);
                    } elseif (is_array($entry) && isset($entry['name']) && is_string($entry['name']) && trim($entry['name']) !== '') {
                        $names[] = trim($entry['name']);
                    }
                }
            }
        }

        return $names;
    }

    /** Look for visible byline markup: rel="author", or class/itemprop hints. */
    private function bylineFromDom(string $html): string
    {
        // rel="author" anchor text.
        if (preg_match('/<a[^>]+rel=["\']author["\'][^>]*>(.*?)<\/a>/is', $html, $m)) {
            $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                return $text;
            }
        }

        // itemprop="author" or a class containing "byline"/"author".
        $patterns = [
            '/<[^>]+itemprop=["\']author["\'][^>]*>(.*?)<\/[^>]+>/is',
            '/<[^>]+class=["\'][^"\']*\b(?:byline|author-name|author)\b[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
                if ($text !== '' && mb_strlen($text) <= 120) {
                    return $text;
                }
            }
        }

        return '';
    }

    private function extractContent(string $html): string
    {
        // Strip script, style, noscript, nav, footer, aside
        $stripped = preg_replace(
            '/<(script|style|noscript|nav|footer|aside|form|iframe)\b[^>]*>.*?<\/\1>/is',
            ' ',
            $html
        );

        if ($stripped === null) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $stripped)) {
            libxml_clear_errors();
            return '';
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Try common article containers in order
        $candidates = [
            '//article',
            '//main',
            '//*[@role="main"]',
            '//*[contains(@class,"article-body")]',
            '//*[contains(@class,"story-body")]',
            '//*[contains(@class,"entry-content")]',
            '//*[contains(@class,"post-content")]',
            '//*[contains(@itemprop,"articleBody")]',
        ];

        foreach ($candidates as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            $text = '';
            foreach ($nodes as $node) {
                $text .= ' ' . ($node->textContent ?? '');
            }
            $clean = $this->cleanText($text);
            if (strlen($clean) > 200) {
                return $clean;
            }
        }

        // Last resort: collect all <p> tags
        $paragraphs = $xpath->query('//p');
        if ($paragraphs !== false && $paragraphs->length > 0) {
            $text = '';
            foreach ($paragraphs as $p) {
                $t = trim($p->textContent ?? '');
                if (strlen($t) > 40) {
                    $text .= $t . "\n\n";
                }
            }
            return $this->cleanText($text);
        }

        return '';
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        return trim($text);
    }
}
