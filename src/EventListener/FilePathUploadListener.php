<?php

namespace App\EventListener;

use App\Entity\Files;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FilePathUploadListener
{
    public function __construct(private FilesystemOperator $defaultStorage)
    {
    }

    /**
     * @param LifecycleEventArgs $args
     * @throws \ReflectionException
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();

        $this->uploadFile($entity);
    }

    /**
     * @param PreUpdateEventArgs $args
     * @throws \ReflectionException
     */
    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getObject();

        $this->uploadFile($entity);
    }

    /**
     * @param $entity
     * @throws \ReflectionException
     * @throws \Exception
     */
    private function uploadFile($entity)
    {
        if (!$entity instanceof Files) {
            return;
        }

        $file = $entity->getPath();

        if ($file instanceof UploadedFile) {
            $fileName = $this->generateTimeStamp();
            $ext = strtolower((string) $file->guessExtension());
            $contents = file_get_contents($file->getPathname());
            $originalName = $file->getClientOriginalName();

            // Force every uploaded image into a Telegram-friendly format. AVIF
            // (and any other not-jpg/png/webp guess) is converted to JPG here so
            // the rest of the system never has to worry about format fallbacks.
            $webExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $webExts, true) && $contents !== false) {
                $image = @imagecreatefromstring($contents);
                if ($image !== false) {
                    ob_start();
                    imagejpeg($image, null, 88);
                    $converted = ob_get_clean();
                    imagedestroy($image);
                    if ($converted !== false && $converted !== '') {
                        $contents = $converted;
                        $ext = 'jpg';
                        $originalName = preg_replace('/\.[^.]+$/', '.jpg', $originalName) ?: $originalName . '.jpg';
                    }
                }
            }

            $baseName = $originalName;
            if (in_array($ext, $webExts, true)) {
                $baseName = preg_replace('/\.[^.]+$/', '.' . $ext, $originalName) ?: $originalName;
            }
            $path = $fileName . '_' . $baseName;
            $fileSize = strlen($contents);

            $has = $this->defaultStorage->has($path);
            if (!$has) {
                $this->defaultStorage->write(
                    $path,
                    $contents,
                    ['visibility' => 'public']
                );
            }
            $entity
                ->setPath($path)
                ->setExtension($ext)
                ->setOriginalName($originalName)
                ->setSize((string) $fileSize);

        } elseif ($file instanceof File) {
            $entity->setPath($file->getFilename());
        }
    }

    private function generateTimeStamp()
    {
        return (new \DateTime())->format('Ymd_H:i:s');
    }
}
