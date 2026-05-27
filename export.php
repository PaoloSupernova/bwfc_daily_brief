#!/usr/bin/env php
<?php
/**
 * BWFC Daily Brief — CLI export script
 *
 * Usage:
 *   php export.php
 *   php export.php --output /path/to/output.zip
 *
 * Generates a full data export ZIP and prints a summary table.
 */

declare(strict_types=1);

// Must be run from command line
if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/config/config.php';

use BWFC\DailyBrief\Exporter;

// Parse --output argument
$outputPath = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--output' && isset($argv[$i + 1])) {
        $outputPath = $argv[$i + 1];
    }
}

echo "\n";
echo "  BWFC Daily Brief — Data Export\n";
echo "  " . str_repeat('─', 40) . "\n\n";
echo "  Building export package...\n\n";

try {
    $exporter = new Exporter();
    $result   = $exporter->generate();
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n\n";
    exit(1);
}

$s = $result['summary'];

// Move ZIP to requested output path if specified
$finalPath = $result['path'];
if ($outputPath !== null) {
    if (!rename($result['path'], $outputPath)) {
        echo "  WARNING: Could not move ZIP to '{$outputPath}'. File saved at default location.\n";
    } else {
        $finalPath = $outputPath;
    }
} else {
    // Default: save to project root
    $defaultPath = __DIR__ . '/' . $result['filename'];
    if (rename($result['path'], $defaultPath)) {
        $finalPath = $defaultPath;
    }
}

// ── Summary table ────────────────────────────────────────────────

$rows = [
    ['Category',                     'Item',                      'Count / Size'],
    ['─',                            '─',                         '─'],
    ['Briefs',                       'Sent',                      number_format($s['briefs_sent'])],
    ['',                             'Draft',                     number_format($s['briefs_draft'])],
    ['',                             'Total',                     number_format($s['briefs_sent'] + $s['briefs_draft'])],
    ['─',                            '─',                         '─'],
    ['Articles',                     'Full summaries',            number_format($s['articles'])],
    ['',                             'Related "More:" links',     number_format($s['related_links'])],
    ['─',                            '─',                         '─'],
    ['Configuration',                'Sections',                  number_format($s['sections'])],
    ['',                             'Outlets',                   number_format($s['outlets'])],
    ['',                             'Users',                     number_format($s['users'])],
    ['',                             'Prompt templates',          number_format($s['prompt_templates'])],
    ['─',                            '─',                         '─'],
    ['Audit log',                    'Entries',                   number_format($s['audit_entries'])],
    ['─',                            '─',                         '─'],
    ['Files',                        'Banner images',             number_format($s['banner_images'])],
    ['',                             'Banner total size',         format_bytes($s['banner_bytes'])],
    ['─',                            '─',                         '─'],
    ['Export',                       'ZIP file size',             format_bytes($s['zip_size'])],
];

$colWidths = [0, 0, 0];
foreach ($rows as $row) {
    foreach ($row as $i => $cell) {
        $colWidths[$i] = max($colWidths[$i], strlen((string)$cell));
    }
}

$border = '  +' . str_repeat('─', $colWidths[0] + 2)
        . '+' . str_repeat('─', $colWidths[1] + 2)
        . '+' . str_repeat('─', $colWidths[2] + 2) . '+';

echo $border . "\n";

foreach ($rows as $row) {
    if ($row[0] === '─') {
        echo $border . "\n";
        continue;
    }
    $c0 = str_pad((string)$row[0], $colWidths[0]);
    $c1 = str_pad((string)$row[1], $colWidths[1]);
    $c2 = str_pad((string)$row[2], $colWidths[2], ' ', STR_PAD_LEFT);
    echo "  | {$c0} | {$c1} | {$c2} |\n";
}

echo $border . "\n\n";
echo "  ✓ Export saved to:\n";
echo "    {$finalPath}\n\n";
echo "  See IMPORT.md inside the ZIP for step-by-step migration instructions.\n\n";

// ── Helpers ──────────────────────────────────────────────────────

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}
