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
            $image = @imagecreatefromstring($contents);
            if (false === $image) {
                $this->logger->warning('thumbnail: cannot decode image', ['path' => $originalPath]);

                return [];
            }
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
}
