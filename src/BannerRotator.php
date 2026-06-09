<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Rotates through available banner images based on brief ID.
 *
 * Banners are JPG/PNG files dropped into public/img/banners/.
 * Adding or removing files changes the rotation automatically, no database,
 * no code changes. Files sort alphabetically, so banner-1.jpg, banner-2.jpg...
 * define the rotation order.
 */
final class BannerRotator
{
    private const BANNER_DIR_RELATIVE = '/img/banners';

    /**
     * Get the banner file path (absolute) for a given brief ID.
     * Returns null if no banners are available.
     */
    public static function forBriefId(int $briefId): ?string
    {
        $banners = self::availableBanners();
        if (count($banners) === 0) {
            return null;
        }
        $index = ($briefId - 1) % count($banners);
        if ($index < 0) $index = 0;
        return $banners[$index];
    }

    /**
     * Get the banner URL (web-accessible path) for a given brief ID.
     * Returns null if no banners are available.
     */
    public static function urlForBriefId(int $briefId, string $basePath = ''): ?string
    {
        $file = self::forBriefId($briefId);
        if ($file === null) return null;
        $filename = basename($file);
        return rtrim($basePath, '/') . self::BANNER_DIR_RELATIVE . '/' . $filename;
    }

    /**
     * Return all available banner absolute paths, sorted alphabetically.
     *
     * @return array<int, string>
     */
    public static function availableBanners(): array
    {
        $dir = PUBLIC_PATH . self::BANNER_DIR_RELATIVE;
        if (!is_dir($dir)) return [];

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $path = $dir . '/' . $entry;
            if (!is_file($path)) continue;
            if (!preg_match('/\.(jpe?g|png|webp)$/i', $entry)) continue;
            $files[] = $path;
        }
        sort($files); // Alphabetical so banner-1, banner-2 rotate predictably
        return $files;
    }

    /**
     * Get a base64 data URI for embedding in PDF (mPDF handles paths but data URIs
     * are more portable). Returns empty string on failure.
     */
    public static function asDataUri(string $filePath): string
    {
        if (!is_file($filePath)) return '';
        $mime = self::detectMime($filePath);
        $data = @file_get_contents($filePath);
        if ($data === false) return '';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    private static function detectMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
