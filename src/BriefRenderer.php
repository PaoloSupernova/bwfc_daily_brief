<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Renders a brief into three formats:
 * - renderHtml: inline-styled HTML for Outlook copy-paste (Arial 14pt, BWFC palette)
 * - renderPlainText: mimics the existing Daily Brief email format exactly
 * - renderPdfHtml: same branded HTML, tweaked for mPDF rendering
 *
 * Banner rotation: each brief gets a banner from public/img/banners/ chosen by
 * brief ID (rotating through alphabetically-sorted files).
 */
final class BriefRenderer
{
    private const NAVY = '#19223D';
    private const BLUE = '#003976';
    private const RED = '#EF3E33';
    private const BODY_STYLE = 'font-family: Arial, sans-serif; font-size: 14pt; color: #1D1D1B; line-height: 1.5;';

    // ============================================================
    // HTML (for Outlook paste)
    // Note: banner embedded as base64 so when the HTML is pasted into Outlook
    // and forwarded, the image travels with it.
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
        $h = '<div style="background: ' . self::NAVY . '; color: #FFFFFF; padding: 16px 24px; margin-bottom: 16px;">';
        $h .= '<div style="font-family: Arial, sans-serif; font-size: 18pt; font-weight: bold; letter-spacing: 0.5px;">DAILY BRIEF: ' . strtoupper(htmlspecialchars($date, ENT_QUOTES)) . '</div>';
        $h .= '</div>';
        return $h;
    }

    private static function renderExecutiveSummary(string $summary): string
    {
        if (trim($summary) === '') return '';

        $h = '<div style="background: #F4F4F6; border-left: 4px solid ' . self::RED . '; padding: 16px 20px; margin-bottom: 24px;">';
        $h .= '<div style="font-weight: bold; font-size: 11pt; color: ' . self::NAVY . '; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px;">Executive Summary</div>';

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
            $h .= '<div style="font-family: Arial, sans-serif; font-weight: bold; font-size: 15pt; color: ' . self::BLUE . '; border-bottom: 2px solid ' . self::BLUE . '; padding-bottom: 4px; margin-bottom: 14px; letter-spacing: 1px; text-transform: uppercase;">' . htmlspecialchars((string)$sectionName, ENT_QUOTES) . '</div>';

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
        $h .= '</div>';
        return $h;
    }

    private static function groupBySection(array $articles): array
    {
        $grouped = [];
        foreach ($articles as $article) {
            $sectionName = (string)$article['section_name'];
            $grouped[$sectionName] = $grouped[$sectionName] ?? [];
            $grouped[$sectionName][] = $article;
        }
        return $grouped;
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
            $lines[] = 'EXECUTIVE SUMMARY';
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
                $summary = trim((string)$article['summary']);

                $lines[] = "{$outlet}: {$headline}";
                $lines[] = $summary;
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Plain text variant with URLs included on their own line after each headline.
     * Outlook typically auto-hyperlinks these on paste.
     */
    public static function renderPlainTextWithLinks(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $lines = [];

        $lines[] = 'DAILY BRIEF: ' . strtoupper($date);
        $lines[] = '';

        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $lines[] = 'EXECUTIVE SUMMARY';
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
                $summary = trim((string)$article['summary']);

                $lines[] = "{$outlet}: {$headline}";
                if ($url !== '') $lines[] = $url;
                $lines[] = $summary;
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    // ============================================================
    // PDF-TAILORED HTML (for mPDF rendering)
    // Banner image embedded as base64 data URI for reliability.
    // ============================================================

    public static function renderPdfHtml(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);
        $briefId = (int)($brief['id'] ?? 0);

        // Banner as data URI for embedding
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
            body { font-family: Arial, sans-serif; font-size: 11pt; color: #1D1D1B; line-height: 1.45; margin: 0; padding: 0; }
            .banner { margin: 0; padding: 0; line-height: 0; }
            .banner img { width: 100%; display: block; }
            .header { background: ' . self::NAVY . '; color: #FFFFFF; padding: 14pt 22pt; border-bottom: 3pt solid ' . self::RED . '; }
            .header-title { font-size: 16pt; font-weight: bold; letter-spacing: 0.3pt; }
            .exec-summary { background: #F4F4F6; padding: 14pt 18pt; margin: 16pt 22pt 20pt; border-left: 3pt solid ' . self::RED . '; }
            .exec-summary-label { font-size: 9pt; font-weight: bold; color: ' . self::NAVY . '; letter-spacing: 1pt; margin-bottom: 6pt; }
            .exec-summary p { margin: 0 0 8pt; }
            .section { margin: 0 22pt 18pt; }
            .section-heading { font-size: 13pt; font-weight: bold; color: ' . self::BLUE . '; border-bottom: 1.5pt solid ' . self::BLUE . '; padding-bottom: 3pt; margin-bottom: 10pt; letter-spacing: 0.8pt; }
            .article { margin-bottom: 12pt; page-break-inside: avoid; }
            .article-head { margin-bottom: 4pt; }
            .article-outlet { font-weight: bold; color: ' . self::NAVY . '; }
            .article-headline { color: ' . self::BLUE . '; font-weight: bold; text-decoration: underline; }
            .article-summary { color: #333333; line-height: 1.4; }
        </style></head><body>';

        if ($bannerHtml !== '') {
            $html .= '<div class="banner">' . $bannerHtml . '</div>';
        }

        $html .= '<div class="header"><div class="header-title">DAILY BRIEF: ' . strtoupper(htmlspecialchars($date, ENT_QUOTES)) . '</div></div>';

        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $html .= '<div class="exec-summary"><div class="exec-summary-label">EXECUTIVE SUMMARY</div>';
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
