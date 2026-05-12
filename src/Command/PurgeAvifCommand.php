<?php

namespace App\Command;

use App\Entity\Files;
use App\Service\AvifFallbackGenerator;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:images:purge-avif',
    description: 'Replace every AVIF Files row with a JPG sibling, so all product images are JPG/PNG/WEBP.',
)]
class PurgeAvifCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private AvifFallbackGenerator $generator,
        private FilesystemOperator $defaultStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        /** @var Files[] $avifFiles */
        $avifFiles = $this->em->createQueryBuilder()
            ->select('f')
            ->from(Files::class, 'f')
            ->where('LOWER(f.extension) = :avif')
            ->setParameter('avif', 'avif')
            ->getQuery()
            ->getResult();

        $io->writeln(sprintf('Found %d AVIF rows to process.', count($avifFiles)));

        $promoted = 0;
        $generated = 0;
        $deleted = 0;
        $failed = 0;

        foreach ($avifFiles as $avif) {
            $productId = $avif->getProduct()->getId();
            $avifPath = $avif->getPath();

            // 1. Try to find a JPG/PNG/WEBP variant whose variantOf points at this AVIF.
            $variant = $this->em->getRepository(Files::class)->findOneBy(['variantOf' => $avif]);

            // 2. Fall back to any same-product JPG with matching base path (older backfills
            //    may have created the sibling without linking variantOf).
            if ($variant === null) {
                $base = preg_replace('/\.avif$/i', '', $avifPath);
                $variant = $this->em->createQueryBuilder()
                    ->select('f')
                    ->from(Files::class, 'f')
                    ->where('IDENTITY(f.product) = :pid')
                    ->andWhere('LOWER(f.extension) IN (:exts)')
                    ->andWhere('f.path = :jpgPath OR f.path = :jpegPath OR f.path = :pngPath OR f.path = :webpPath')
                    ->setParameter('pid', $productId)
                    ->setParameter('exts', ['jpg', 'jpeg', 'png', 'webp'])
                    ->setParameter('jpgPath',  $base . '.jpg')
                    ->setParameter('jpegPath', $base . '.jpeg')
                    ->setParameter('pngPath',  $base . '.png')
                    ->setParameter('webpPath', $base . '.webp')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
            }

            // 3. No sibling at all → generate one from the AVIF before deleting it.
            if ($variant === null) {
                if ($dry) {
                    $io->writeln(sprintf(' - would generate JPG for product #%d (%s)', $productId, $avifPath));
                    $generated++;
                    continue;
                }
                try {
                    $variant = $this->generator->generateForFile($avif, flush: true);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Generate failed for product #%d: %s', $productId, $e->getMessage()));
                    $failed++;
                    continue;
                }
                if ($variant === null) {
                    $io->warning(sprintf('Generator returned null for product #%d (%s)', $productId, $avifPath));
                    $failed++;
                    continue;
                }
                $generated++;
            } else {
                $promoted++;
            }

            if ($dry) {
                $io->writeln(sprintf(' - would promote %s and delete %s', $variant->getPath(), $avifPath));
                continue;
            }

            // 4. Detach variant from its parent so it stands alone.
            if ($variant->getVariantOf() !== null) {
                $variant->setVariantOf(null);
            }

            // 5. Delete AVIF file on disk (best effort) and the DB row.
            try {
                if ($this->defaultStorage->has($avifPath)) {
                    $this->defaultStorage->delete($avifPath);
                }
            } catch (\Throwable $e) {
                $io->writeln(sprintf('   <comment>could not delete disk file %s: %s</comment>', $avifPath, $e->getMessage()));
            }

            $this->em->remove($avif);
            $this->em->flush();
            $deleted++;
        }

        $io->success(sprintf(
            '%s: promoted-existing=%d, generated-new=%d, deleted-avif=%d, failed=%d',
            $dry ? 'Dry run' : 'Done',
            $promoted,
            $generated,
            $deleted,
            $failed
        ));

        return Command::SUCCESS;
    }
}
