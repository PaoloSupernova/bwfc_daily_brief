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
        $html .= self::renderContents($grouped);
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

    /**
     * A small colour-coded sentiment face (green smile / amber flat / red frown)
     * as an inline SVG data URI. SVG is vector, needs no GD, and renders in the
     * browser, Outlook and mPDF alike (emoji don't render in mPDF's fonts).
     */
    private static function sentimentFace(string $sentiment): string
    {
        $key = in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';
        $color = match ($key) {
            'positive' => '#2E9E5B',
            'negative' => '#C0392B',
            default    => '#C9A227',
        };
        $mouth = match ($key) {
            'positive' => '<path d="M4.6 9.6 Q8 13 11.4 9.6" stroke="#fff" stroke-width="1.5" fill="none" stroke-linecap="round"/>',
            'negative' => '<path d="M4.6 11.6 Q8 8.2 11.4 11.6" stroke="#fff" stroke-width="1.5" fill="none" stroke-linecap="round"/>',
            default    => '<line x1="5" y1="10.6" x2="11" y2="10.6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>',
        };
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">'
            . '<circle cx="8" cy="8" r="7.5" fill="' . $color . '"/>'
            . '<circle cx="5.6" cy="6.4" r="1.2" fill="#fff"/>'
            . '<circle cx="10.4" cy="6.4" r="1.2" fill="#fff"/>'
            . $mouth
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function truncate(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    /**
     * A hyperlinked contents list under the executive summary: one story per
     * line — a colour-coded sentiment face, then "Outlet — Headline" (truncated
     * to a single line), the headline linked to the source article.
     */
    private static function renderContents(array $grouped): string
    {
        $hasAny = false;
        foreach ($grouped as $arts) {
            if (count($arts) > 0) { $hasAny = true; break; }
        }
        if (!$hasAny) return '';

        $legendFace = function (string $s): string {
            $uri = self::sentimentFace($s);
            return $uri !== '' ? '<img src="' . $uri . '" width="13" height="13" style="vertical-align: middle;">' : '';
        };

        $h = '<div style="background: #F4F4F6; border-left: 4px solid ' . self::BLUE . '; padding: 14px 20px; margin-bottom: 24px;">';
        $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-weight: bold; font-size: 11pt; color: ' . self::NAVY . '; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px;">In this brief</div>';
        $h .= '<div style="font-size: 9pt; color: #777777; margin-bottom: 10px;">'
            . $legendFace('positive') . ' positive &nbsp; '
            . $legendFace('neutral') . ' neutral &nbsp; '
            . $legendFace('negative') . ' negative</div>';

        foreach ($grouped as $sectionName => $articles) {
            if (count($articles) === 0) continue;
            $h .= '<div style="font-family: ' . self::FONT_HEAD . '; font-weight: bold; font-size: 9.5pt; color: ' . self::BLUE . '; text-transform: uppercase; letter-spacing: 0.5px; margin: 10px 0 4px;">' . htmlspecialchars((string)$sectionName, ENT_QUOTES) . '</div>';
            foreach ($articles as $a) {
                $faceUri = self::sentimentFace((string)($a['sentiment'] ?? ''));
                $faceImg = $faceUri !== '' ? '<img src="' . $faceUri . '" width="14" height="14" style="vertical-align: middle; margin-right: 7px;">' : '';
                $outlet = htmlspecialchars((string)$a['outlet_name'], ENT_QUOTES);
                $headline = htmlspecialchars(self::truncate((string)$a['headline'], 64), ENT_QUOTES);
                $url = htmlspecialchars((string)$a['url'], ENT_QUOTES);
                $h .= '<div style="font-size: 11pt; line-height: 1.75; white-space: nowrap; overflow: hidden;">'
                    . $faceImg
                    . '<strong style="color: ' . self::NAVY . ';">' . $outlet . '</strong> &mdash; '
                    . '<a href="' . $url . '" style="color: ' . self::BLUE . '; text-decoration: none;">' . $headline . '</a>'
                    . '</div>';
            }
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
            // Smaller hero on its own line, below the headline (image wrapped in a
            // block div so it can never sit inline with the headline).
            $inner = $kicker . $headlineHtml
                . '<div style="margin: 2px 0 14px;"><img src="' . $img . '" alt="" width="380" referrerpolicy="no-referrer" '
                . 'style="width: 380px; max-width: 100%; height: auto; display: block; border-radius: 8px;"></div>'
                . $summaryHtml . $moreHtml;
        } elseif (($mode === 'left' || $mode === 'right') && $imageSrc !== '') {
            // Headline full width, then a two-column table (image + summary).
            // Tables render identically in browsers, Outlook and mPDF — no floats.
            $imgCell = '<td valign="top" width="180" style="width: 180px;">'
                . '<img src="' . $img . '" alt="" width="180" referrerpolicy="no-referrer" '
                . 'style="width: 180px; display: block; border-radius: 8px; border: 1px solid #E2E2E6;"></td>';
            $pad = $mode === 'left' ? 'padding-left: 18px;' : 'padding-right: 18px;';
            $txtCell = '<td valign="top" style="' . $pad . '">' . $summaryHtml . '</td>';
            $row = $mode === 'left' ? ($imgCell . $txtCell) : ($txtCell . $imgCell);
            $inner = $kicker . $headlineHtml
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $row . '</tr></table>'
                . $moreHtml;
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
            .article-headline { font-family: nippo, Arial, sans-serif; font-size: 13pt; font-weight: bold; color: ' . self::NAVY . '; line-height: 1.2; margin-bottom: 9pt; }
            .article-summary { color: #2B2B2B; font-size: 10.5pt; line-height: 1.55; }
            .article-hero-wrap { margin: 0 0 9pt; }
            .article-img-hero { width: 280pt; border-radius: 5pt; }
            .article-img-side { width: 140pt; border: 0.5pt solid #E2E2E6; border-radius: 5pt; }
            .article-sidetable { width: 100%; }
            .article-sidetable td { vertical-align: top; }
            .article-more { font-size: 9.5pt; color: #555555; margin-top: 6pt; }
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

        // Hyperlinked contents list (same box as the HTML version), inset within
        // the page margins.
        $html .= '<div style="margin: 0 22pt 16pt;">' . self::renderContents($grouped) . '</div>';

        $globalIdx = 0;   // first article in the brief is the lead (hero)
        $sideCounter = 0; // alternates side images left/right

        foreach ($grouped as $sectionName => $items) {
            if (count($items) === 0) continue;

            $html .= '<div class="section">';
            $headingHtml = '<div class="section-heading">' . htmlspecialchars(strtoupper((string)$sectionName), ENT_QUOTES) . '</div>';
            $firstInSection = true;

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
                // Headline wrapped in a block DIV (mPDF doesn't reliably treat an
                // <a> as display:block, which caused the image to split it).
                $headlineTag = '<div class="article-headline"><a href="' . $url . '" style="color: ' . self::NAVY . '; text-decoration: none;">' . $headline . '</a></div>';
                $summaryTag = '<div class="article-summary">' . $summary . '</div>';

                $cardHtml = '<div class="article-card">';
                if ($mode === 'hero') {
                    $cardHtml .= $kicker . $headlineTag
                        . '<div class="article-hero-wrap"><img src="' . $imgSrc . '" class="article-img-hero"></div>'
                        . $summaryTag . $moreHtml;
                } elseif ($mode === 'left' || $mode === 'right') {
                    // Two-column table (mPDF renders these cleanly; floats don't).
                    $imgCell = '<td width="150" style="width: 150pt;"><img src="' . $imgSrc . '" class="article-img-side"></td>';
                    $txtPad = $mode === 'left' ? 'padding-left: 12pt;' : 'padding-right: 12pt;';
                    $txtCell = '<td style="' . $txtPad . '">' . $summaryTag . '</td>';
                    $row = $mode === 'left' ? ($imgCell . $txtCell) : ($txtCell . $imgCell);
                    $cardHtml .= $kicker . $headlineTag
                        . '<table class="article-sidetable"><tr>' . $row . '</tr></table>'
                        . $moreHtml;
                } else {
                    $cardHtml .= $kicker . $headlineTag . $summaryTag . $moreHtml;
                }
                $cardHtml .= '</div>';

                if ($firstInSection) {
                    // Bind the section heading to its first story so the heading
                    // is never left stranded at the bottom of a page.
                    $html .= '<div style="page-break-inside: avoid;">' . $headingHtml . $cardHtml . '</div>';
                    $firstInSection = false;
                } else {
                    $html .= $cardHtml;
                }
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