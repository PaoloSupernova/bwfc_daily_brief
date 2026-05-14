<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use Mpdf\Mpdf;
use Mpdf\Config\FontVariables;
use Mpdf\Config\ConfigVariables;
use RuntimeException;

/**
 * Generates a PDF from a brief using mPDF.
 *
 * Custom brand fonts (Nippo, Satoshi, Built Titling) are registered at runtime
 * from src/fonts-pdf/ if the TTF or OTF files exist. Falls back to Arial if
 * the files aren't present.
 *
 * Page layout: page 1 is flush at the top so the banner sits at the page edge.
 * Pages 2+ use the @page CSS rules in BriefRenderer to add 18mm top margin.
 */
final class PdfExporter
{
    public static function generate(array $brief, array $articles): string
    {
        if (!class_exists(Mpdf::class)) {
            throw new RuntimeException(
                'mPDF library not installed. Run "composer require mpdf/mpdf" in the project root.'
            );
        }

        $html = BriefRenderer::renderPdfHtml($brief, $articles);
        $date = BriefRenderer::formatDate((string)$brief['brief_date']);

        $tempDir = sys_get_temp_dir() . '/mpdf_bwfc';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = self::buildConfig($tempDir);

        $mpdf = new Mpdf($config);

        $mpdf->SetTitle('BWFC Daily Brief - ' . $date);
        $mpdf->SetAuthor('BWFC Communications');
        $mpdf->SetCreator('BWFC Daily Brief Tool');

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public static function filename(array $brief): string
    {
        $date = (string)$brief['brief_date'];
        return 'BWFC-Daily-Brief-' . $date . '.pdf';
    }

    /**
     * Build the mPDF config. Page 1 has top margin 0 so the banner sits flush
     * at the page edge; the CSS @page rule then adds margin-top: 18mm for pages 2+.
     */
    private static function buildConfig(string $tempDir): array
    {
        $fontDir = BASE_PATH . '/src/fonts-pdf';

        $config = [
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 18,
            'margin_header' => 0,
            'margin_footer' => 8,
            'default_font' => 'satoshi',
            'tempDir' => $tempDir,
            'autoPageBreak' => true,
        ];

        if (!is_dir($fontDir)) {
            $config['default_font'] = 'arial';
            return $config;
        }

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();

        $fontData = $defaultFontConfig['fontdata'];
        $customDirs = $defaultConfig['fontDir'];
        $customDirs[] = $fontDir;

        $registrations = [
            'nippo' => [
                'R' => 'Nippo-Regular.ttf',
                'M' => 'Nippo-Medium.ttf',
                'B' => 'Nippo-Bold.ttf',
            ],
            'satoshi' => [
                'R' => 'Satoshi-Regular.ttf',
                'M' => 'Satoshi-Medium.ttf',
                'B' => 'Satoshi-Bold.ttf',
            ],
            'builttitling' => [
                'R' => 'BuiltTitling-Regular.ttf',
            ],
        ];

        foreach ($registrations as $family => $files) {
            $regularPath = $fontDir . '/' . ($files['R'] ?? '');

            if (!is_file($regularPath)) {
                $otfAlt = str_replace('.ttf', '.otf', $files['R'] ?? '');
                if (is_file($fontDir . '/' . $otfAlt)) {
                    foreach ($files as $weight => $filename) {
                        $files[$weight] = str_replace('.ttf', '.otf', $filename);
                    }
                } else {
                    continue;
                }
            }

            $resolved = [];
            foreach ($files as $weight => $filename) {
                if (is_file($fontDir . '/' . $filename)) {
                    $resolved[$weight] = $filename;
                }
            }
            if (count($resolved) > 0) {
                $fontData[$family] = $resolved;
            }
        }

        $config['fontDir'] = $customDirs;
        $config['fontdata'] = $fontData;

        if (!isset($fontData['satoshi'])) {
            $config['default_font'] = 'arial';
        }

        return $config;
    }
}