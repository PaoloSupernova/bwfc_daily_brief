<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use Mpdf\Mpdf;
use RuntimeException;

/**
 * Generates a PDF from a brief using mPDF.
 *
 * mPDF is installed via Composer (see composer.json).
 * If mPDF isn't available, a clear error is thrown.
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

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 18,
            'margin_header' => 0,
            'margin_footer' => 8,
            'default_font' => 'Arial',
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetTitle('BWFC Daily Brief - ' . $date);
        $mpdf->SetAuthor('BWFC Communications');
        $mpdf->SetCreator('BWFC Daily Brief Tool');

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S'); // return as string
    }

    public static function filename(array $brief): string
    {
        $date = (string)$brief['brief_date'];
        return 'BWFC-Daily-Brief-' . $date . '.pdf';
    }
}
