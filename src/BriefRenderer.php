<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Renders a brief into the HTML block that gets copied into Outlook.
 *
 * Inline styles only (Outlook strips <style> blocks). Arial 14pt per the
 * March 2025 guidance. Navy headers per brand guidelines page 2.
 */
final class BriefRenderer
{
    private const NAVY = '#19223D';
    private const BLUE = '#003976';
    private const RED = '#EF3E33';
    private const BODY_STYLE = 'font-family: Arial, sans-serif; font-size: 14pt; color: #1D1D1B; line-height: 1.5;';

    public static function renderHtml(array $brief, array $articles): string
    {
        $date = self::formatDate((string)$brief['brief_date']);
        $grouped = self::groupBySection($articles);

        $html = '<div style="' . self::BODY_STYLE . ' max-width: 800px;">';
        $html .= self::renderHeader($date);
        $html .= self::renderExecutiveSummary((string)($brief['executive_summary'] ?? ''));
        $html .= self::renderSections($grouped);
        $html .= '</div>';

        return $html;
    }

    private static function renderHeader(string $date): string
    {
        $h = '<div style="background: ' . self::NAVY . '; color: #FFFFFF; padding: 20px 24px; margin-bottom: 16px;">';
        $h .= '<div style="font-family: Arial, sans-serif; font-size: 20pt; font-weight: bold; letter-spacing: 0.5px;">DAILY BRIEF: ' . strtoupper(htmlspecialchars($date, ENT_QUOTES)) . '</div>';
        $h .= '</div>';
        return $h;
    }

    private static function renderExecutiveSummary(string $summary): string
    {
        if (trim($summary) === '') {
            return '';
        }

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
            if (count($articles) === 0) {
                continue;
            }

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
            $grouped[$sectionName] ??= [];
            $grouped[$sectionName][] = $article;
        }
        return $grouped;
    }

    public static function formatDate(string $date): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            return $date;
        }
        return $dt->format('l jS F Y');
    }

    public static function formatSubjectLine(string $date): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            return 'DAILY BRIEF: ' . strtoupper($date);
        }
        return 'DAILY BRIEF: ' . strtoupper($dt->format('l jS F Y'));
    }
}
