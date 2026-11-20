<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Image\ProductThumbnailer;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off backfill (and safe to re-run) of WebP thumbnails for every product
 * photo in the default storage. New uploads get theirs in FilePathUploadListener.
 *
 * Prod: run as www-data so the files are owned by the web user:
 *   sudo -u www-data php8.5 bin/console app:images:thumbnails --env=prod
 */
#[AsCommand(name: 'app:images:thumbnails', description: 'Generate WebP thumbnails (400/800 px) for product photos')]
final class GenerateProductThumbnailsCommand extends Command
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly ProductThumbnailer $thumbnailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be generated')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-generate thumbs that already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $originals = 0;
        $written = 0;

        /** @var StorageAttributes $item */
        foreach ($this->defaultStorage->listContents('', false) as $item) {
            $path = $item->path();
            if (!$item->isFile() || !$this->thumbnailer->supports($path)) {
                continue;
            }
            ++$originals;
            if ($dryRun) {
                continue;
            }
            $written += count($this->thumbnailer->generate($path, null, $force));
        }

        $output->writeln(sprintf(
            '%s%d originals, %d thumbnails written',
            $dryRun ? '[dry-run] ' : '',
            $originals,
            $written,
        ));

        return Command::SUCCESS;
    }
}
