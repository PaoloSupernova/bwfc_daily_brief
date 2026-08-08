<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Downloads and locally caches article images so they render reliably in every
 * output — the browser preview, the Outlook HTML, and the mPDF export — without
 * depending on the source site allowing hotlinks at render time.
 *
 * Images are fetched once (no Referer, so hotlink protection is bypassed),
 * normalised to a downscaled JPEG via GD (guarantees mPDF compatibility and
 * keeps files small), and stored under public/img/cache/<sha1-of-url>.jpg.
 * De-duplicated by URL hash, so re-caching the same URL is a no-op.
 */
final class ImageCache
{
    private const MAX_WIDTH = 600;
    private const MAX_DOWNLOAD_BYTES = 8 * 1024 * 1024; // 8 MB
    private const JPEG_QUALITY = 82;

    private static function dir(): string
    {
        return (defined('PUBLIC_PATH') ? PUBLIC_PATH : __DIR__ . '/../public') . '/img/cache';
    }

    /**
     * Cache a remote image and return its web-relative path (e.g.
     * "img/cache/abc.jpg"), or null if it couldn't be fetched/decoded.
     */
    public static function cache(?string $remoteUrl): ?string
    {
        $remoteUrl = trim((string)$remoteUrl);
        if ($remoteUrl === '' || !preg_match('#^https?://#i', $remoteUrl)) {
            return null;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $hash = sha1($remoteUrl);
        $rel = 'img/cache/' . $hash . '.jpg';
        $path = $dir . '/' . $hash . '.jpg';

        if (is_file($path)) {
            return $rel; // already cached
        }

        $bytes = self::download($remoteUrl);
        if ($bytes === null) {
            return null;
        }

        $jpeg = self::normaliseToJpeg($bytes);
        if ($jpeg === null) {
            return null;
        }

        if (@file_put_contents($path, $jpeg) === false) {
            return null;
        }

        return $rel;
    }

    /**
     * Return a base64 data URI for a cached image path, or null if missing.
     * Used by BriefRenderer to embed images directly (bulletproof in PDF/email).
     */
    public static function dataUri(?string $relPath): ?string
    {
        $relPath = trim((string)$relPath);
        if ($relPath === '') {
            return null;
        }
        $file = (defined('PUBLIC_PATH') ? PUBLIC_PATH : __DIR__ . '/../public') . '/' . ltrim($relPath, '/');
        if (!is_file($file)) {
            return null;
        }
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            return null;
        }
        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_REFERER => '',   // no referer → bypasses most hotlink protection
            CURLOPT_USERAGENT => (string)env('FETCH_USER_AGENT', 'Mozilla/5.0 (compatible; BWFC-DailyBrief/1.0)'),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $code >= 400) {
            return null;
        }
        if (strlen($body) < 100 || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            return null;
        }
        return $body;
    }

    /** Decode any supported image, flatten onto white, downscale, re-encode JPEG. */
    private static function normaliseToJpeg(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null; // not a decodable image
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return null;
        }

        $targetW = $w > self::MAX_WIDTH ? self::MAX_WIDTH : $w;
        $targetH = (int)round($h * ($targetW / $w));
        if ($targetH < 1) {
            $targetH = 1;
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white); // flatten any transparency onto white
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        return $jpeg !== false && $jpeg !== '' ? $jpeg : null;
    }
}
