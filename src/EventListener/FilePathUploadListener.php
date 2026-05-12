<?php

namespace App\EventListener;

use App\Entity\Files;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FilePathUploadListener
{
    public function __construct(
        private FilesystemOperator $defaultStorage,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private string $projectDir,
        private string $telegramWarmupChatId,
    ) {
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

            // Eagerly upload to Telegram so the bot can use file_id immediately,
            // without forcing the first user to wait on a URL-fetch / disk-upload.
            $this->warmTelegramFileId($entity, $contents, $originalName);

        } elseif ($file instanceof File) {
            $entity->setPath($file->getFilename());
        }
    }

    private function generateTimeStamp()
    {
        return (new \DateTime())->format('Ymd_H:i:s');
    }

    /**
     * Send the just-saved image to a private warmup chat so we capture the
     * file_id Telegram returns, persist it on the entity, and delete the
     * warmup message. After this the bot can render the image instantly,
     * with no upload or URL fetch on the first user encounter.
     *
     * Best-effort: any failure (no chat id configured, Telegram unreachable,
     * etc.) leaves telegram_file_id null and the bot falls back to InputFile
     * upload on first send. That's the correct degradation path.
     */
    private function warmTelegramFileId(Files $entity, string $contents, string $originalName): void
    {
        if ($this->telegramWarmupChatId === '' || $contents === '') {
            return;
        }

        // Write bytes to a temp file so InputFile can stream them; using the
        // already-persisted disk path would also work but this avoids depending
        // on Flysystem's local path resolution.
        $tmp = tempnam(sys_get_temp_dir(), 'tg_warm_');
        if ($tmp === false) {
            return;
        }
        try {
            file_put_contents($tmp, $contents);

            $msg = $this->bot->sendPhoto(
                chat_id: $this->telegramWarmupChatId,
                photo: InputFile::make($tmp, $originalName),
                caption: 'warmup',
            );

            $sizes = $msg->photo ?? [];
            $largest = $sizes ? end($sizes) : null;
            $fileId = $largest->file_id ?? null;
            if ($fileId) {
                $entity->setTelegramFileId($fileId);
            }

            if (isset($msg->message_id)) {
                try {
                    $this->bot->deleteMessage((int) $this->telegramWarmupChatId, $msg->message_id);
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram file_id warmup failed on upload', [
                'error' => $e->getMessage(),
                'path'  => $entity->getPath(),
            ]);
        } finally {
            @unlink($tmp);
        }
    }
}
