<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Shared\Converter;
use RuntimeException;

/**
 * Generates a Word (.docx) document from a brief using PHPWord.
 *
 * Structure mirrors the PDF: date header, executive summary,
 * then articles grouped by section with outlet, headline, summary, and source link.
 */
final class WordExporter
{
    // Brand colours (hex without #)
    private const NAVY  = '19223D';
    private const BLUE  = '003976';
    private const MUTED = '6A6A6A';
    private const INK   = '1D1D1B';
    private const GREEN = '1E7D3C';
    private const RULE  = 'D9DBE3';

    public static function generate(array $brief, array $articles): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        // ── Page section ──────────────────────────────────────────────────
        $section = $phpWord->addSection([
            'marginLeft'   => Converter::cmToTwip(2),
            'marginRight'  => Converter::cmToTwip(2),
            'marginTop'    => Converter::cmToTwip(2),
            'marginBottom' => Converter::cmToTwip(2),
        ]);

        // ── Title block ───────────────────────────────────────────────────
        $briefDate = date('l jS F Y', strtotime((string)$brief['brief_date']));

        $section->addText(
            'BWFC Daily Brief',
            ['bold' => true, 'size' => 24, 'color' => self::NAVY, 'name' => 'Calibri'],
            ['spaceAfter' => 40]
        );
        $dateRun = $section->addTextRun(['spaceAfter' => 60]);
        $dateRun->addText(
            $briefDate,
            ['size' => 13, 'bold' => true, 'color' => self::NAVY]
        );
        if ((string)$brief['status'] === 'sent') {
            $dateRun->addText('   SENT', ['size' => 10, 'bold' => true, 'color' => self::GREEN]);
        } else {
            $dateRun->addText('   DRAFT', ['size' => 10, 'bold' => true, 'color' => self::MUTED]);
        }

        // Horizontal rule after the title block
        self::addRule($section);

        // ── Executive summary ─────────────────────────────────────────────
        $execSummary = trim((string)($brief['executive_summary'] ?? ''));
        if ($execSummary !== '') {
            $section->addText(
                'Executive Summary',
                ['bold' => true, 'size' => 12, 'color' => self::NAVY, 'allCaps' => true, 'name' => 'Calibri'],
                ['spaceAfter' => 80, 'spaceBefore' => 120]
            );
            $paragraphs = preg_split('/\n{2,}/', $execSummary) ?: [$execSummary];
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if ($para !== '') {
                    $section->addText(
                        htmlspecialchars_decode($para, ENT_QUOTES),
                        ['size' => 11, 'color' => self::INK],
                        ['spaceAfter' => 100]
                    );
                }
            }
            self::addRule($section);
        }

        // ── Articles grouped by section ───────────────────────────────────
        // Group preserving the section display order from articlesForBrief()
        $grouped = [];
        foreach ($articles as $article) {
            $slug = (string)($article['section_slug'] ?? 'other');
            $name = (string)($article['section_name'] ?? 'Other');
            if (!isset($grouped[$slug])) {
                $grouped[$slug] = ['name' => $name, 'articles' => []];
            }
            $grouped[$slug]['articles'][] = $article;
        }

        foreach ($grouped as $group) {
            // Section heading
            $section->addText(
                strtoupper($group['name']),
                ['bold' => true, 'size' => 11, 'color' => self::NAVY, 'allCaps' => true, 'name' => 'Calibri'],
                ['spaceBefore' => 200, 'spaceAfter' => 120, 'borderBottomColor' => self::NAVY, 'borderBottomSize' => 8]
            );

            foreach ($group['articles'] as $article) {
                // Outlet
                $outlet = strtoupper(trim((string)($article['outlet_name'] ?? '')));
                if ($outlet !== '') {
                    $section->addText(
                        $outlet,
                        ['size' => 8, 'bold' => true, 'color' => self::MUTED, 'name' => 'Calibri'],
                        ['spaceAfter' => 30, 'spaceBefore' => 120]
                    );
                }

                // Headline
                $headline = trim((string)($article['headline'] ?? ''));
                if ($headline !== '') {
                    $section->addText(
                        htmlspecialchars_decode($headline, ENT_QUOTES),
                        ['size' => 12, 'bold' => true, 'color' => self::INK, 'name' => 'Calibri'],
                        ['spaceAfter' => 80]
                    );
                }

                // Summary
                $summary = trim((string)($article['summary'] ?? ''));
                if ($summary !== '') {
                    $section->addText(
                        htmlspecialchars_decode($summary, ENT_QUOTES),
                        ['size' => 11, 'color' => self::INK],
                        ['spaceAfter' => 60]
                    );
                }

                // Source URL
                $url = trim((string)($article['url'] ?? ''));
                if ($url !== '') {
                    $run = $section->addTextRun(['spaceAfter' => 160]);
                    $run->addText('Source: ', ['size' => 9, 'color' => self::MUTED]);
                    try {
                        $run->addLink(
                            $url,
                            $url,
                            ['size' => 9, 'color' => self::BLUE, 'underline' => Font::UNDERLINE_SINGLE],
                            []
                        );
                    } catch (\Throwable $e) {
                        $run->addText($url, ['size' => 9, 'color' => self::MUTED]);
                    }
                }
            }
        }

        // ── Serialise to a temp file and return raw bytes ─────────────────
        $tempFile = sys_get_temp_dir() . '/bwfc-brief-' . uniqid('', true) . '.docx';
        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);
            $bytes = file_get_contents($tempFile);
            if ($bytes === false) {
                throw new RuntimeException('Could not read generated Word file');
            }
            return $bytes;
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    public static function filename(array $brief): string
    {
        return 'BWFC-Daily-Brief-' . (string)$brief['brief_date'] . '.docx';
    }

    private static function addRule(object $section): void
    {
        $section->addText(
            '',
            [],
            ['borderBottomColor' => self::RULE, 'borderBottomSize' => 4, 'spaceAfter' => 120, 'spaceBefore' => 40]
        );
    }
}
