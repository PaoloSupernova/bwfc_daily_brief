<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use RuntimeException;
use ZipArchive;

/**
 * Exports the full application state (database + assets + config template)
 * to a self-contained ZIP file that can be imported on any server.
 *
 * Returns a summary array with counts for display in both the CLI table
 * and the admin UI table.
 */
final class Exporter
{
    /** Tables exported in dependency order (parents before children). */
    private const TABLES = [
        'sections',
        'users',
        'outlets',
        'style_rules',
        'prompt_templates',
        'briefs',
        'brief_articles',
        'audit_log',
        'rss_cache',
    ];

    private const BANNER_DIR = PUBLIC_PATH . '/img/banners';
    private const MIGRATIONS = [
        'migration_002_sections_and_routing.sql',
        'migration_003_related_articles.sql',
    ];

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Generate the export ZIP and return the path + summary stats.
     *
     * @return array{ path: string, filename: string, summary: array<string, mixed> }
     */
    public function generate(): array
    {
        $summary = $this->collectSummary();
        $zipPath = $this->buildZip($summary);

        $summary['zip_size'] = filesize($zipPath);
        $summary['zip_path'] = $zipPath;

        return [
            'path'     => $zipPath,
            'filename' => basename($zipPath),
            'summary'  => $summary,
        ];
    }

    // ----------------------------------------------------------------
    // Summary stats
    // ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function collectSummary(): array
    {
        $row = fn(string $sql, array $p = []) => Database::selectOne($sql, $p);
        $val = fn(?array $r, string $k) => (int)($r[$k] ?? 0);

        $briefCounts   = $row('SELECT SUM(status="sent") AS sent, SUM(status="draft") AS draft FROM briefs');
        $articleCounts = $row('SELECT COUNT(*) AS total, SUM(parent_article_id IS NOT NULL) AS related FROM brief_articles');
        $sections      = $val($row('SELECT COUNT(*) AS n FROM sections WHERE deleted_at IS NULL'), 'n');
        $outlets       = $val($row('SELECT COUNT(*) AS n FROM outlets'), 'n');
        $users         = $val($row('SELECT COUNT(*) AS n FROM users'), 'n');
        $templates     = $val($row('SELECT COUNT(*) AS n FROM prompt_templates'), 'n');
        $auditEntries  = $val($row('SELECT COUNT(*) AS n FROM audit_log'), 'n');

        $banners = 0;
        $bannerBytes = 0;
        if (is_dir(self::BANNER_DIR)) {
            foreach (glob(self::BANNER_DIR . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    $banners++;
                    $bannerBytes += filesize($f) ?: 0;
                }
            }
        }

        return [
            'briefs_sent'     => $val($briefCounts, 'sent'),
            'briefs_draft'    => $val($briefCounts, 'draft'),
            'articles'        => $val($articleCounts, 'total') - $val($articleCounts, 'related'),
            'related_links'   => $val($articleCounts, 'related'),
            'sections'        => $sections,
            'outlets'         => $outlets,
            'users'           => $users,
            'prompt_templates'=> $templates,
            'audit_entries'   => $auditEntries,
            'banner_images'   => $banners,
            'banner_bytes'    => $bannerBytes,
        ];
    }

    // ----------------------------------------------------------------
    // ZIP assembly
    // ----------------------------------------------------------------

    private function buildZip(array $summary): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required but not installed.');
        }

        $date    = date('Y-m-d');
        $dir     = sys_get_temp_dir() . '/bwfc_export_' . uniqid('', true);
        $zipFile = sys_get_temp_dir() . '/bwfc-export-' . $date . '.zip';

        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create temp export directory.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create ZIP file at: ' . $zipFile);
        }

        $prefix = 'bwfc-export-' . $date . '/';

        // --- IMPORT guide ---
        $zip->addFromString($prefix . 'IMPORT.md', $this->buildImportGuide($date));

        // --- .env template ---
        $zip->addFromString($prefix . '.env.example', $this->buildEnvTemplate());

        // --- schema.sql (original) ---
        $schemaPath = BASE_PATH . '/database/schema.sql';
        if (file_exists($schemaPath)) {
            $zip->addFile($schemaPath, $prefix . 'database/schema.sql');
        }

        // --- migration files ---
        foreach (self::MIGRATIONS as $migration) {
            $mPath = BASE_PATH . '/database/' . $migration;
            if (file_exists($mPath)) {
                $zip->addFile($mPath, $prefix . 'database/' . $migration);
            }
        }

        // --- data.sql ---
        $zip->addFromString($prefix . 'database/data.sql', $this->buildDataSql());

        // --- banner images ---
        if (is_dir(self::BANNER_DIR)) {
            foreach (glob(self::BANNER_DIR . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    $zip->addFile($f, $prefix . 'files/banners/' . basename($f));
                }
            }
        }

        $zip->close();

        // Clean up temp dir
        @rmdir($dir);

        return $zipFile;
    }

    // ----------------------------------------------------------------
    // SQL data dump
    // ----------------------------------------------------------------

    private function buildDataSql(): string
    {
        $pdo = Database::connection();
        $lines = [];

        $lines[] = '-- BWFC Daily Brief — data export';
        $lines[] = '-- Generated: ' . date('Y-m-d H:i:s T');
        $lines[] = '--';
        $lines[] = '-- Run this AFTER importing schema.sql and all migrations.';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
        $lines[] = 'SET time_zone = "+00:00";';
        $lines[] = '';

        foreach (self::TABLES as $table) {
            // Check the table exists before trying to dump it
            $exists = $pdo->query("SHOW TABLES LIKE '{$table}'")->rowCount() > 0;
            if (!$exists) {
                continue;
            }

            $rows = Database::select("SELECT * FROM `{$table}`");
            if (count($rows) === 0) {
                $lines[] = "-- (no rows in `{$table}`)";
                $lines[] = '';
                continue;
            }

            $lines[] = "-- Table: `{$table}` (" . count($rows) . ' rows)';
            $lines[] = "TRUNCATE TABLE `{$table}`;";

            $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $lines[] = "INSERT INTO `{$table}` ({$columns}) VALUES";

            $valueRows = [];
            foreach ($rows as $row) {
                $values = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string)$v;
                    return $pdo->quote((string)$v);
                }, array_values($row));
                $valueRows[] = '  (' . implode(', ', $values) . ')';
            }

            $lines[] = implode(",\n", $valueRows) . ';';
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // ----------------------------------------------------------------
    // .env template
    // ----------------------------------------------------------------

    private function buildEnvTemplate(): string
    {
        $keys = [
            'APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE',
            'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET',
            'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'ANTHROPIC_MAX_TOKENS',
        ];

        $lines   = [];
        $lines[] = '# BWFC Daily Brief — environment configuration';
        $lines[] = '# Copied from export on ' . date('Y-m-d') . '. Review all values before going live.';
        $lines[] = '';

        $groups = [
            'Application' => ['APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE'],
            'Database'    => ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'],
            'Anthropic'   => ['ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'ANTHROPIC_MAX_TOKENS'],
        ];

        foreach ($groups as $label => $groupKeys) {
            $lines[] = "# {$label}";
            foreach ($groupKeys as $k) {
                $v = (string)(env($k, '') ?? '');
                // Mask the API key partially for safety
                if ($k === 'ANTHROPIC_API_KEY' && strlen($v) > 12) {
                    $v = substr($v, 0, 12) . str_repeat('*', strlen($v) - 12);
                }
                $lines[] = $k . '=' . $v;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ----------------------------------------------------------------
    // IMPORT.md guide
    // ----------------------------------------------------------------

    private function buildImportGuide(string $date): string
    {
        return <<<MD
# BWFC Daily Brief — Migration Guide
Generated: {$date}

Follow these steps in order on your new server.

---

## Step 1 — Server requirements
- PHP 8.1+ with extensions: pdo_mysql, zip, curl, mbstring, gd
- MySQL 8.0+
- Composer

## Step 2 — Copy the application files
Upload the full project folder to your server (e.g. `/var/www/bwfc-daily-brief`).
Do NOT upload your old `.env` file — use the `.env.example` from this ZIP instead.

## Step 3 — Install PHP dependencies
```bash
cd /var/www/bwfc-daily-brief
composer install --no-dev
```

## Step 4 — Create the database
```sql
CREATE DATABASE bwfc_daily_brief CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Step 5 — Import the schema
```bash
mysql -u root -p bwfc_daily_brief < database/schema.sql
mysql -u root -p bwfc_daily_brief < database/migration_002_sections_and_routing.sql
mysql -u root -p bwfc_daily_brief < database/migration_003_related_articles.sql
```

## Step 6 — Import your data
```bash
mysql -u root -p bwfc_daily_brief < database/data.sql
```

## Step 7 — Configure environment
```bash
cp .env.example .env
nano .env   # fill in DB credentials, restore full ANTHROPIC_API_KEY
```

The API key in `.env.example` has been partially masked. You will need to paste
your full key from the Anthropic console: https://console.anthropic.com/

## Step 8 — Copy banner images
```bash
cp -r files/banners/* public/img/banners/
```

## Step 9 — Set permissions
```bash
chmod -R 755 public/
chown -R www-data:www-data /var/www/bwfc-daily-brief
```

## Step 10 — Test
Visit the site in a browser. You should see all your existing briefs on the dashboard.

---

If anything goes wrong, check your web server error log and confirm all
environment variables in `.env` are correct.
MD;
    }
}
