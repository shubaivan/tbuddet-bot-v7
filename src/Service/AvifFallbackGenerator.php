<?php

namespace App\Service;

use App\Entity\Files;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * Generates JPEG fallbacks for AVIF product images so browsers without
 * AVIF support get a real image instead of a broken placeholder.
 *
 * Reuses the same GD-based decode the Telegram bot uses on the fly
 * (PriceRingConversation), but persists the fallback as a sibling
 * Files row + S3 object so the cost is paid once at upload/backfill
 * rather than on every page render.
 */
class AvifFallbackGenerator
{
    public const FALLBACK_QUALITY = 88;

    public function __construct(
        private FilesystemOperator $defaultStorage,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate a JPEG sibling for an AVIF Files entity. Returns the
     * persisted Files row, or null if generation failed / wasn't needed.
     */
    public function generateForFile(Files $avifFile, bool $flush = true): ?Files
    {
        if (strtolower($avifFile->getExtension()) !== 'avif') {
            return null;
        }

        $sourcePath = $avifFile->getPath();
        try {
            $contents = $this->defaultStorage->read($sourcePath);
        } catch (\Throwable $e) {
            $this->logger->warning('AvifFallback: could not read source from storage', [
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            $this->logger->warning('AvifFallback: GD failed to decode AVIF', ['path' => $sourcePath]);
            return null;
        }

        ob_start();
        $ok = imagejpeg($image, null, self::FALLBACK_QUALITY);
        $jpegContents = ob_get_clean();
        imagedestroy($image);

        if (!$ok || $jpegContents === false || $jpegContents === '') {
            $this->logger->warning('AvifFallback: imagejpeg returned empty', ['path' => $sourcePath]);
            return null;
        }

        $jpegPath = preg_replace('/\.avif$/i', '.jpg', $sourcePath);
        if ($jpegPath === $sourcePath) {
            $jpegPath = $sourcePath . '.jpg';
        }

        try {
            $this->defaultStorage->write($jpegPath, $jpegContents, ['visibility' => 'public']);
        } catch (\Throwable $e) {
            $this->logger->warning('AvifFallback: failed to write JPEG to storage', [
                'path' => $jpegPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $jpegFile = new Files();
        $jpegFile
            ->setPath($jpegPath)
            ->setExtension('jpg')
            ->setOriginalName(preg_replace('/\.avif$/i', '.jpg', $avifFile->getOriginalName()))
            ->setSize((string) strlen($jpegContents))
            ->setProduct($avifFile->getProduct())
            // Mark as a sibling of the AVIF original so admin photo lists show
            // one entry per uploaded image, not original + its programmatic JPG.
            ->setVariantOf($avifFile);

        $this->em->persist($jpegFile);
        if ($flush) {
            $this->em->flush();
        }

        $this->logger->info('AvifFallback: generated JPEG sibling', [
            'product_id' => $avifFile->getProduct()->getId(),
            'avif_path'  => $sourcePath,
            'jpeg_path'  => $jpegPath,
            'size_bytes' => strlen($jpegContents),
        ]);

        return $jpegFile;
    }
}
