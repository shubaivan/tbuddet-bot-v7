<?php

declare(strict_types=1);

namespace App\Service\Image;

use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * WebP thumbnails for product photos, so a 280px catalog card does not download
 * a 1200×1200 original (Lighthouse «properly size images», mobile LCP).
 *
 * Stored next to the originals in the public default storage:
 *   <name>.jpg  →  thumbs/400/<name>.webp, thumbs/800/<name>.webp
 * nginx serves /assets/ as static files, so no PHP runs per request. The shop
 * front-end asks for a thumb via srcset and falls back to the original when a
 * thumb is missing — generating them is an optimisation, never a dependency.
 */
final class ProductThumbnailer
{
    /** Card / gallery widths (CSS px × DPR are covered by srcset). */
    public const WIDTHS = [400, 800];
    public const QUALITY = 78;
    /**
     * Above this many pixels decoding needs >100 MB — skip (the original is
     * still shown). A fatal out-of-memory would not be caught and would fail
     * the admin upload itself.
     */
    public const MAX_PIXELS = 25_000_000;

    private const SOURCE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function thumbPath(string $originalPath, int $width): string
    {
        $base = preg_replace('/\.[^.\/]+$/', '', ltrim($originalPath, '/')) ?? $originalPath;

        return sprintf('thumbs/%d/%s.webp', $width, $base);
    }

    public static function isThumbPath(string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), 'thumbs/');
    }

    public function supports(string $path): bool
    {
        if (self::isThumbPath($path)) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::SOURCE_EXTS, true) && function_exists('imagewebp');
    }

    /**
     * Writes every missing thumb for one original (all of them with $force).
     *
     * @return list<string> paths written
     */
    public function generate(string $originalPath, ?string $contents = null, bool $force = false): array
    {
        if (!$this->supports($originalPath)) {
            return [];
        }

        $todo = [];
        foreach (self::WIDTHS as $width) {
            $target = self::thumbPath($originalPath, $width);
            if ($force || !$this->defaultStorage->fileExists($target)) {
                $todo[$width] = $target;
            }
        }
        if ([] === $todo) {
            return [];
        }

        try {
            $contents ??= $this->defaultStorage->read($originalPath);
            $size = @getimagesizefromstring($contents);
            if (false === $size || $size[0] * $size[1] > self::MAX_PIXELS) {
                $this->logger->info('thumbnail: skipped (unreadable or too large)', ['path' => $originalPath]);

                return [];
            }
            $image = @imagecreatefromstring($contents);
            if (false === $image) {
                $this->logger->warning('thumbnail: cannot decode image', ['path' => $originalPath]);

                return [];
            }
            $image = $this->applyExifOrientation($image, $contents);
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            $written = [];
            $srcWidth = imagesx($image);
            foreach ($todo as $width => $target) {
                // Never upscale: a small original is re-encoded at its own size.
                $scaled = $srcWidth > $width ? imagescale($image, $width, -1, IMG_BICUBIC) : $image;
                if (false === $scaled) {
                    continue;
                }
                ob_start();
                imagewebp($scaled, null, self::QUALITY);
                $bytes = (string) ob_get_clean();
                if ($scaled !== $image) {
                    unset($scaled);
                }
                if ('' === $bytes) {
                    continue;
                }
                $this->defaultStorage->write($target, $bytes, ['visibility' => 'public']);
                $written[] = $target;
            }
            unset($image);

            return $written;
        } catch (\Throwable $e) {
            // Best effort: the shop falls back to the original image.
            $this->logger->warning('thumbnail: generation failed', ['path' => $originalPath, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Phone photos keep «which way is up» in EXIF; browsers apply it to the
     * original JPEG, GD does not, and WebP drops EXIF — so rotate the pixels.
     */
    private function applyExifOrientation(\GdImage $image, string $contents): \GdImage
    {
        if (!function_exists('exif_read_data') || !str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            return $image;
        }
        fwrite($stream, $contents);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = (int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1);

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (false === $rotated) {
            return $image;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }
}
