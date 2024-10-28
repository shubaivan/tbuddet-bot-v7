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
            $ext = $file->guessExtension();
            $path = $fileName.'_'.$file->getClientOriginalName();
            $fileSize = $file->getSize();

            $has = $this->defaultStorage->has($path);
            if (!$has) {
                $contents = file_get_contents($file->getPathname());
                $this->defaultStorage->write(
                    $path,
                    $contents,
                    ['visibility' => 'public']
                );
            }
            $entity
                ->setPath($path)
                ->setExtension($ext)
                ->setOriginalName($file->getClientOriginalName())
                ->setSize($fileSize);

        } elseif ($file instanceof File) {
            $entity->setPath($file->getFilename());
        }
    }

    private function generateTimeStamp()
    {
        return (new \DateTime())->format('Ymd_H:i:s');
    }
}
