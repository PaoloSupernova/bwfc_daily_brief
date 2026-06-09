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
        foreach ($grouped as $sectionName => $articles) {
            if (count($articles) === 0) continue;

            $h .= '<div style="margin-bottom: 28px;">';
            $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-weight: bold; font-size: 15pt; color: ' . self::BLUE . '; border-bottom: 2px solid ' . self::BLUE . '; padding-bottom: 4px; margin-bottom: 14px; letter-spacing: 1px; text-transform: uppercase;">' . htmlspecialchars((string)$sectionName, ENT_QUOTES) . '</div>';

            foreach ($articles as $article) {
                $h .= self::renderArticle($article);
            }
            $h .= '</div>';
        }
        return $h;
    }

    private static function renderArticle(array $article): string
    {
        $outlet = htmlspecialchars((string)$article['outlet_name'], ENT_QUOTES);
        $headline = htmlspecialchars((string)$article['headline'], ENT_QUOTES);
        $url = htmlspecialchars((string)$article['url'], ENT_QUOTES);
        $summary = nl2br(htmlspecialchars((string)$article['summary'], ENT_QUOTES));

        $h = '<div style="margin-bottom: 18px;">';
        $h .= '<div style="font-size: 13pt; margin-bottom: 6px;">';
        $h .= '<span style="font-weight: bold; color: ' . self::NAVY . ';">' . $outlet . ':</span> ';
        $h .= '<a href="' . $url . '" style="color: ' . self::BLUE . '; font-weight: bold; text-decoration: underline;">' . $headline . '</a>';
        $h .= '</div>';
        $h .= '<div style="font-size: 12pt; color: #333333; line-height: 1.45;">' . $summary . '</div>';

        if (!empty($article['related'])) {
            $links = [];
            foreach ($article['related'] as $r) {
                $ro = htmlspecialchars((string)$r['outlet_name'], ENT_QUOTES);
                $rh = htmlspecialchars((string)$r['headline'], ENT_QUOTES);
                $ru = htmlspecialchars((string)$r['url'], ENT_QUOTES);
                $links[] = '<a href="' . $ru . '" style="color: ' . self::BLUE . '; text-decoration: underline;">' . $ro . ': ' . $rh . '</a>';
            }
            $h .= '<div style="font-size: 11pt; color: #555555; margin-top: 5px;">';
            $h .= '<span style="font-weight: 700; color: ' . self::NAVY . ';">More:</span> ';
            $h .= implode(' <span style="color: #BBBBBB; margin: 0 3px;">|</span> ', $links);
            $h .= '</div>';
        }

        $h .= '</div>';
        return $h;
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
            .section { margin: 0 22pt 18pt; }
            .section-heading { font-family: nippo, Arial, sans-serif; font-size: 13pt; font-weight: bold; color: ' . self::BLUE . '; border-bottom: 1.5pt solid ' . self::BLUE . '; padding-bottom: 3pt; margin-bottom: 10pt; letter-spacing: 0.8pt; page-break-after: avoid; }
            .article { margin-bottom: 12pt; page-break-inside: avoid; orphans: 3; widows: 3; }
            .article-head { margin-bottom: 4pt; page-break-after: avoid; }
            .article-outlet { font-weight: bold; color: ' . self::NAVY . '; }
            .article-headline { color: ' . self::BLUE . '; font-weight: bold; text-decoration: underline; }
            .article-summary { color: #333333; line-height: 1.4; orphans: 3; widows: 3; }
            .article-more { font-size: 9.5pt; color: #555555; margin-top: 4pt; }
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

        foreach ($grouped as $sectionName => $items) {
            if (count($items) === 0) continue;

            $html .= '<div class="section">';
            $html .= '<div class="section-heading">' . htmlspecialchars(strtoupper((string)$sectionName), ENT_QUOTES) . '</div>';

            foreach ($items as $article) {
                $outlet = htmlspecialchars((string)$article['outlet_name'], ENT_QUOTES);
                $headline = htmlspecialchars((string)$article['headline'], ENT_QUOTES);
                $url = htmlspecialchars((string)$article['url'], ENT_QUOTES);
                $summary = nl2br(htmlspecialchars((string)$article['summary'], ENT_QUOTES));

                $html .= '<div class="article">';
                $html .= '<div class="article-head"><span class="article-outlet">' . $outlet . ':</span> ';
                $html .= '<a href="' . $url . '" class="article-headline">' . $headline . '</a></div>';
                $html .= '<div class="article-summary">' . $summary . '</div>';

                if (!empty($article['related'])) {
                    $links = [];
                    foreach ($article['related'] as $r) {
                        $ro = htmlspecialchars((string)$r['outlet_name'], ENT_QUOTES);
                        $rh = htmlspecialchars((string)$r['headline'], ENT_QUOTES);
                        $ru = htmlspecialchars((string)$r['url'], ENT_QUOTES);
                        $links[] = '<a href="' . $ru . '" class="article-more-link">' . $ro . ': ' . $rh . '</a>';
                    }
                    $html .= '<div class="article-more"><span class="article-more-label">More:</span> ';
                    $html .= implode(' <span class="article-more-sep">|</span> ', $links);
                    $html .= '</div>';
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