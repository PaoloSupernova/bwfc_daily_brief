<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Renders a brief into three formats:
 * - renderHtml: inline-styled HTML for Outlook copy-paste.
 *   Brand fonts (Satoshi, Nippo) declared first, Arial fallback for clients
 *   without them. Banner embedded as base64 so it travels with forwards.
 * - renderPlainText: mimics the existing Daily Brief email format exactly.
 * - renderPdfHtml: branded HTML tuned for mPDF. Uses registered mPDF font names
 *   which match the TTF files in src/fonts-pdf/ (if present).
 *
 * Banner rotation: each brief gets a banner from public/img/banners/ chosen by
 * brief ID, rotating through alphabetically-sorted files.
 */
final class BriefRenderer
{
    private const NAVY = '#19223D';
    private const BLUE = '#003976';
    private const RED = '#EF3E33';

    private const FONT_BODY = "'Satoshi', Arial, sans-serif";
    private const FONT_HEAD = "'Nippo', 'Arial Narrow', Arial, sans-serif";

    private const BODY_STYLE = 'font-family: ' . self::FONT_BODY . '; font-size: 14pt; color: #1D1D1B; line-height: 1.5;';

    // ============================================================
    // HTML (for Outlook paste)
    // ============================================================

    public static function renderHtml(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $briefId = (int)($brief['id'] ?? 0);

        $html = '<div style="' . self::BODY_STYLE . ' max-width: 800px;">';
        $html .= self::renderBanner($briefId);
        $html .= self::renderHeader($date);
        $html .= self::renderExecutiveSummary((string)($brief['executive_summary'] ?? ''));
        $html .= self::renderSections($grouped);
        $html .= '</div>';

        return $html;
    }

    private static function renderBanner(int $briefId): string
    {
        $bannerPath = BannerRotator::forBriefId($briefId);
        if ($bannerPath === null) return '';

        $dataUri = BannerRotator::asDataUri($bannerPath);
        if ($dataUri === '') return '';

        return '<div style="margin-bottom: 0; line-height: 0;">'
            . '<img src="' . $dataUri . '" alt="BWFC Daily Brief" style="width: 100%; max-width: 800px; display: block;">'
            . '</div>';
    }

    private static function renderHeader(string $date): string
    {
        $h = '<div style="background: ' . self::NAVY . '; color: #FFFFFF; padding: 16px 24px; margin-bottom: 16px; text-align: center;">';
        $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-size: 18pt; font-weight: bold; letter-spacing: 0.5px;">DAILY BRIEF: ' . strtoupper(htmlspecialchars($date, ENT_QUOTES)) . '</div>';
        $h .= '</div>';
        return $h;
    }

    private static function renderExecutiveSummary(string $summary): string
    {
        if (trim($summary) === '') return '';

        $h = '<div style="background: #F4F4F6; border-left: 4px solid ' . self::RED . '; padding: 16px 20px; margin-bottom: 24px;">';
        $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-weight: bold; font-size: 11pt; color: ' . self::NAVY . '; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px;">Summary</div>';

        $paragraphs = preg_split('/\n\s*\n/', trim($summary)) ?: [trim($summary)];
        foreach ($paragraphs as $p) {
            $h .= '<p style="margin: 0 0 12px 0;">' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES)) . '</p>';
        }
        $h .= '</div>';
        return $h;
    }

    private static function renderSections(array $grouped): string
    {
        $h = '';
        $globalIdx = 0;   // first article in the brief is the lead
        $sideCounter = 0; // alternates side images left/right

        foreach ($grouped as $sectionName => $articles) {
            if (count($articles) === 0) continue;

            $h .= '<div style="margin-bottom: 24px;">';
            $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-weight: bold; font-size: 15pt; color: ' . self::BLUE . '; border-bottom: 2px solid ' . self::BLUE . '; padding-bottom: 4px; margin-bottom: 14px; letter-spacing: 1px; text-transform: uppercase;">' . htmlspecialchars((string)$sectionName, ENT_QUOTES) . '</div>';

            foreach ($articles as $article) {
                $imageSrc = self::articleImageSrc($article);
                if ($imageSrc === '') {
                    $mode = 'none';
                } elseif ($globalIdx === 0) {
                    $mode = 'hero';
                } else {
                    $mode = ($sideCounter % 2 === 0) ? 'right' : 'left';
                    $sideCounter++;
                }
                $h .= self::renderArticle($article, $imageSrc, $mode);
                $globalIdx++;
            }
            $h .= '</div>';
        }
        return $h;
    }

    /**
     * Render one story as a grey card. $mode is 'hero' (full-width image on
     * top), 'left'/'right' (image floated to that side, text flows around), or
     * 'none' (text only).
     */
    private static function renderArticle(array $article, string $imageSrc = '', string $mode = 'none'): string
    {
        $outlet = htmlspecialchars((string)$article['outlet_name'], ENT_QUOTES);
        $headline = htmlspecialchars((string)$article['headline'], ENT_QUOTES);
        $url = htmlspecialchars((string)$article['url'], ENT_QUOTES);
        $summary = nl2br(htmlspecialchars((string)$article['summary'], ENT_QUOTES));

        $kicker = '<div style="font-family: ' . self::FONT_HEAD . '; font-size: 9.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2px; color: ' . self::RED . '; margin: 0 0 5px;">' . $outlet . '</div>';
        $headlineHtml = '<a href="' . $url . '" style="font-family: ' . self::FONT_HEAD . '; font-size: 16pt; font-weight: bold; color: ' . self::NAVY . '; text-decoration: none; line-height: 1.24; display: block; margin: 0 0 14px;">' . $headline . '</a>';
        $summaryHtml = '<div style="font-size: 12pt; color: #2B2B2B; line-height: 1.6;">' . $summary . '</div>';

        $moreHtml = '';
        if (!empty($article['related'])) {
            $links = [];
            foreach ($article['related'] as $r) {
                $ro = htmlspecialchars((string)$r['outlet_name'], ENT_QUOTES);
                $rh = htmlspecialchars((string)$r['headline'], ENT_QUOTES);
                $ru = htmlspecialchars((string)$r['url'], ENT_QUOTES);
                $links[] = '<a href="' . $ru . '" style="color: ' . self::BLUE . '; text-decoration: underline;">' . $ro . ': ' . $rh . '</a>';
            }
            $moreHtml = '<div style="font-size: 11pt; color: #555555; margin-top: 8px;">'
                . '<span style="font-weight: 700; color: ' . self::NAVY . ';">More:</span> '
                . implode(' <span style="color: #BBBBBB; margin: 0 3px;">|</span> ', $links)
                . '</div>';
        }

        $img = htmlspecialchars($imageSrc, ENT_QUOTES);
        $inner = '';

        if ($mode === 'hero' && $imageSrc !== '') {
            // Smaller hero on its own line, below the headline.
            $inner = $kicker . $headlineHtml
                . '<img src="' . $img . '" alt="" width="380" referrerpolicy="no-referrer" '
                . 'style="width: 380px; max-width: 100%; height: auto; display: block; border-radius: 8px; margin: 2px 0 14px;">'
                . $summaryHtml . $moreHtml;
        } elseif (($mode === 'left' || $mode === 'right') && $imageSrc !== '') {
            // Headline full width; image floats and the summary flows around it.
            // The card wrapper below sets overflow:hidden so the float is always
            // contained within this story (never leaks into the next card).
            $float = $mode === 'left' ? 'left' : 'right';
            $margin = $mode === 'left' ? 'margin: 2px 16px 6px 0;' : 'margin: 2px 0 6px 16px;';
            $imgTag = '<img src="' . $img . '" alt="" width="160" referrerpolicy="no-referrer" '
                . 'style="width: 160px; float: ' . $float . '; ' . $margin . ' border-radius: 8px; border: 1px solid #E2E2E6;">';
            $inner = $kicker . $headlineHtml . $imgTag . $summaryHtml . $moreHtml
                . '<div style="clear: both; font-size: 1px; line-height: 0;">&nbsp;</div>';
        } else {
            $inner = $kicker . $headlineHtml . $summaryHtml . $moreHtml;
        }

        // Subtle grey card. overflow:hidden makes the card a self-contained
        // block so a floated image can never escape into the next story.
        return '<div style="background: #F5F6F8; border: 1px solid #ECEEF1; border-radius: 10px; padding: 18px 20px; margin: 0 0 14px; overflow: hidden;">'
            . $inner . '</div>';
    }

    private static function groupBySection(array $articles): array
    {
        $nested = self::nestRelated($articles);
        $grouped = [];
        foreach ($nested as $article) {
            $sectionName = (string)$article['section_name'];
            $grouped[$sectionName] = $grouped[$sectionName] ?? [];
            $grouped[$sectionName][] = $article;
        }
        return $grouped;
    }

    /**
     * Separate related-coverage children from parent articles and attach them
     * as a 'related' key on their parent. Parents without children get related = [].
     *
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, mixed>>
     */
    private static function nestRelated(array $articles): array
    {
        $parents = [];
        $children = [];

        foreach ($articles as $article) {
            if (!empty($article['parent_article_id'])) {
                $pid = (int)$article['parent_article_id'];
                $children[$pid][] = $article;
            } else {
                $parents[] = $article;
            }
        }

        return array_map(function (array $article) use ($children): array {
            $article['related'] = $children[(int)$article['id']] ?? [];
            return $article;
        }, $parents);
    }

    // ============================================================
    // PLAIN TEXT (mimics the Daily Brief email sample format)
    // ============================================================

    public static function renderPlainText(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $lines = [];

        $lines[] = 'DAILY BRIEF: ' . strtoupper($date);
        $lines[] = '';

        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $lines[] = 'SUMMARY';
            $lines[] = '';
            $paragraphs = preg_split('/\n\s*\n/', $execSummary) ?: [$execSummary];
            foreach ($paragraphs as $p) {
                $lines[] = trim($p);
                $lines[] = '';
            }
        }

        foreach ($grouped as $sectionName => $items) {
            if (count($items) === 0) continue;

            $lines[] = strtoupper((string)$sectionName);
            $lines[] = '';

            foreach ($items as $article) {
                $lines[] = trim((string)$article['outlet_name']) . ': ' . trim((string)$article['headline']);
                $lines[] = trim((string)$article['summary']);
                if (!empty($article['related'])) {
                    $more = array_map(fn($r) => trim((string)$r['outlet_name']) . ': ' . trim((string)$r['headline']), $article['related']);
                    $lines[] = 'More: ' . implode(' | ', $more);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    public static function renderPlainTextWithLinks(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $lines = [];

        $lines[] = 'DAILY BRIEF: ' . strtoupper($date);
        $lines[] = '';

        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $lines[] = 'SUMMARY';
            $lines[] = '';
            $paragraphs = preg_split('/\n\s*\n/', $execSummary) ?: [$execSummary];
            foreach ($paragraphs as $p) {
                $lines[] = trim($p);
                $lines[] = '';
            }
        }

        foreach ($grouped as $sectionName => $items) {
            if (count($items) === 0) continue;

            $lines[] = strtoupper((string)$sectionName);
            $lines[] = '';

            foreach ($items as $article) {
                $outlet = trim((string)$article['outlet_name']);
                $headline = trim((string)$article['headline']);
                $url = trim((string)$article['url']);
                $lines[] = "{$outlet}: {$headline}";
                if ($url !== '') $lines[] = $url;
                $lines[] = trim((string)$article['summary']);
                if (!empty($article['related'])) {
                    $more = array_map(function ($r) {
                        $part = trim((string)$r['outlet_name']) . ': ' . trim((string)$r['headline']);
                        $u = trim((string)$r['url']);
                        return $u !== '' ? $part . ' - ' . $u : $part;
                    }, $article['related']);
                    $lines[] = 'More: ' . implode(' | ', $more);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    // ============================================================
    // PDF-TAILORED HTML (for mPDF rendering)
    //
    // Three layout choices baked in here:
    // 1. Header text centred (text-align: center on .header-title)
    // 2. The first page renders banner+navy bar flush at the top.
    //    Pages 2+ get a top margin via the @page rule, so content has
    //    breathing room from the page edge.
    // 3. Articles and exec summary use page-break-inside: avoid so they
    //    don't split across pages mid-paragraph.
    // ============================================================

    public static function renderPdfHtml(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $briefId = (int)($brief['id'] ?? 0);

        $bannerHtml = '';
        $bannerPath = BannerRotator::forBriefId($briefId);
        if ($bannerPath !== null) {
            $dataUri = BannerRotator::asDataUri($bannerPath);
            if ($dataUri !== '') {
                $bannerHtml = '<img src="' . $dataUri . '" style="width: 100%; display: block;">';
            }
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<style>
            @page {
                margin-top: 18mm;
                margin-bottom: 18mm;
                margin-left: 0;
                margin-right: 0;
            }
            @page :first {
                margin-top: 0;
            }
            body { font-family: satoshi, Arial, sans-serif; font-size: 11pt; color: #1D1D1B; line-height: 1.45; margin: 0; padding: 0; }
            .banner { margin: 0; padding: 0; line-height: 0; }
            .banner img { width: 100%; display: block; }
            .header { background: ' . self::NAVY . '; color: #FFFFFF; padding: 14pt 22pt; border-bottom: 3pt solid ' . self::RED . '; text-align: center; margin-bottom: 16pt; }
            .header-title { font-family: nippo, Arial, sans-serif; font-size: 16pt; font-weight: bold; letter-spacing: 0.3pt; }
            .click-note { font-size: 10pt; color: #444444; font-style: italic; text-align: right; padding: 4pt 22pt 12pt; }
            .exec-summary { background: #F4F4F6; padding: 14pt 18pt; margin: 0 22pt 20pt; border-left: 3pt solid ' . self::RED . '; page-break-inside: avoid; }
            .exec-summary-label { font-family: nippo, Arial, sans-serif; font-size: 9pt; font-weight: bold; color: ' . self::NAVY . '; letter-spacing: 1pt; margin-bottom: 6pt; }
            .exec-summary p { margin: 0 0 8pt; orphans: 3; widows: 3; }
            .section { margin: 0 22pt 20pt; }
            .section-heading { font-family: nippo, Arial, sans-serif; font-size: 13pt; font-weight: bold; color: ' . self::BLUE . '; border-bottom: 1.5pt solid ' . self::BLUE . '; padding-bottom: 3pt; margin-bottom: 12pt; letter-spacing: 0.8pt; page-break-after: avoid; }
            .article-card { background: #F5F6F8; border: 0.5pt solid #ECEEF1; border-radius: 6pt; padding: 11pt 13pt; margin-bottom: 11pt; page-break-inside: avoid; }
            .article-kicker { font-family: nippo, Arial, sans-serif; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1pt; color: ' . self::RED . '; margin-bottom: 3pt; }
            .article-headline { font-family: nippo, Arial, sans-serif; font-size: 13pt; font-weight: bold; color: ' . self::NAVY . '; text-decoration: none; line-height: 1.2; margin-bottom: 9pt; }
            .article-summary { color: #2B2B2B; font-size: 10.5pt; line-height: 1.55; orphans: 3; widows: 3; }
            .article-img-hero { width: 300pt; border-radius: 5pt; margin-bottom: 7pt; }
            .article-img-side-r { width: 120pt; border: 0.5pt solid #E2E2E6; border-radius: 5pt; float: right; margin: 0 0 6pt 10pt; }
            .article-img-side-l { width: 120pt; border: 0.5pt solid #E2E2E6; border-radius: 5pt; float: left; margin: 0 10pt 6pt 0; }
            .article-more { font-size: 9.5pt; color: #555555; margin-top: 5pt; }
            .article-more-label { font-weight: 700; color: ' . self::NAVY . '; }
            .article-more-link { color: ' . self::BLUE . '; text-decoration: underline; }
            .article-more-sep { color: #BBBBBB; margin: 0 3pt; }
        </style></head><body>';

        if ($bannerHtml !== '') {
            $html .= '<div class="banner">' . $bannerHtml . '</div>';
        }

        $html .= '<div class="header"><div class="header-title">DAILY BRIEF: ' . strtoupper(htmlspecialchars($date, ENT_QUOTES)) . '</div></div>';

        $html .= '<div class="click-note">&#128279; Click any headline to read the full article online.</div>';

        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $html .= '<div class="exec-summary"><div class="exec-summary-label">SUMMARY</div>';
            $paragraphs = preg_split('/\n\s*\n/', $execSummary) ?: [$execSummary];
            foreach ($paragraphs as $p) {
                $html .= '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES)) . '</p>';
            }
            $html .= '</div>';
        }

        $globalIdx = 0;   // first article in the brief is the lead (hero)
        $sideCounter = 0; // alternates side images left/right

        foreach ($grouped as $sectionName => $items) {
            if (count($items) === 0) continue;

            $html .= '<div class="section">';
            $html .= '<div class="section-heading">' . htmlspecialchars(strtoupper((string)$sectionName), ENT_QUOTES) . '</div>';

            foreach ($items as $article) {
                $outlet = htmlspecialchars((string)$article['outlet_name'], ENT_QUOTES);
                $headline = htmlspecialchars((string)$article['headline'], ENT_QUOTES);
                $url = htmlspecialchars((string)$article['url'], ENT_QUOTES);
                $summary = nl2br(htmlspecialchars((string)$article['summary'], ENT_QUOTES));
                $imageSrc = self::articleImageSrc($article);

                $moreHtml = '';
                if (!empty($article['related'])) {
                    $links = [];
                    foreach ($article['related'] as $r) {
                        $ro = htmlspecialchars((string)$r['outlet_name'], ENT_QUOTES);
                        $rh = htmlspecialchars((string)$r['headline'], ENT_QUOTES);
                        $ru = htmlspecialchars((string)$r['url'], ENT_QUOTES);
                        $links[] = '<a href="' . $ru . '" class="article-more-link">' . $ro . ': ' . $rh . '</a>';
                    }
                    $moreHtml = '<div class="article-more"><span class="article-more-label">More:</span> '
                        . implode(' <span class="article-more-sep">|</span> ', $links) . '</div>';
                }

                // Decide layout mode.
                if ($imageSrc === '') {
                    $mode = 'none';
                } elseif ($globalIdx === 0) {
                    $mode = 'hero';
                } else {
                    $mode = ($sideCounter % 2 === 0) ? 'right' : 'left';
                    $sideCounter++;
                }
                $globalIdx++;

                $imgSrc = htmlspecialchars($imageSrc, ENT_QUOTES);
                $kicker = '<div class="article-kicker">' . $outlet . '</div>';
                $headlineTag = '<a href="' . $url . '" class="article-headline">' . $headline . '</a>';
                $summaryTag = '<div class="article-summary">' . $summary . '</div>';

                $html .= '<div class="article-card">';
                if ($mode === 'hero') {
                    $html .= $kicker . $headlineTag
                        . '<img src="' . $imgSrc . '" class="article-img-hero">'
                        . $summaryTag . $moreHtml;
                } elseif ($mode === 'left' || $mode === 'right') {
                    $cls = $mode === 'left' ? 'article-img-side-l' : 'article-img-side-r';
                    $html .= $kicker . $headlineTag
                        . '<img src="' . $imgSrc . '" class="' . $cls . '">'
                        . $summaryTag . $moreHtml
                        . '<div style="clear: both;"></div>';
                } else {
                    $html .= $kicker . $headlineTag . $summaryTag . $moreHtml;
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</body></html>';

        return $html;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Resolve an article's image to an embeddable src: a base64 data URI from
     * the locally-cached copy when available (bulletproof in PDF/email), else
     * the remote URL as a fallback. Empty string when there is no image.
     */
    private static function articleImageSrc(array $article): string
    {
        $cached = trim((string)($article['image_cached'] ?? ''));
        if ($cached !== '') {
            $uri = ImageCache::dataUri($cached);
            if ($uri !== null) {
                return $uri;
            }
        }
        return trim((string)($article['image_url'] ?? ''));
    }

    public static function formatDate(string $date): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if ($dt === false) return $date;
        return $dt->format('l jS F Y');
    }

    public static function formatSubjectLine(string $date): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if ($dt === false) return 'DAILY BRIEF: ' . strtoupper($date);
        return 'DAILY BRIEF: ' . strtoupper($dt->format('l jS F Y'));
    }
}