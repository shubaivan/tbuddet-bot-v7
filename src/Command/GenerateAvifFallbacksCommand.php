<?php

namespace App\Command;

use App\Entity\Files;
use App\Service\AvifFallbackGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:images:generate-avif-fallbacks',
    description: 'Generate JPEG fallbacks for product images that are AVIF-only.',
)]
class GenerateAvifFallbacksCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private AvifFallbackGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report which products need fallbacks without generating.')
            ->addOption('product-id', null, InputOption::VALUE_OPTIONAL, 'Process only the given product id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $productIdFilter = $input->getOption('product-id');

        $qb = $this->em->createQueryBuilder()
            ->select('f')
            ->from(Files::class, 'f')
            ->where('LOWER(f.extension) = :avif')
            ->setParameter('avif', 'avif');
        if ($productIdFilter) {
            $qb->andWhere('IDENTITY(f.product) = :pid')->setParameter('pid', (int) $productIdFilter);
        }

        /** @var Files[] $avifFiles */
        $avifFiles = $qb->getQuery()->getResult();

        $io->writeln(sprintf('Found %d AVIF file rows to inspect.', count($avifFiles)));

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($avifFiles as $avif) {
            $product = $avif->getProduct();
            $productId = $product->getId();

            // Skip if a sibling JPEG/WEBP already exists for this product.
            $hasFallback = false;
            foreach ($product->getFiles() as $sibling) {
                $ext = strtolower($sibling->getExtension());
                if ($ext === 'jpg' || $ext === 'jpeg' || $ext === 'webp' || $ext === 'png') {
                    $hasFallback = true;
                    break;
                }
            }
            if ($hasFallback) {
                $skipped++;
                continue;
            }

            $io->writeln(sprintf(' - product #%d: %s', $productId, $avif->getPath()));
            if ($dry) {
                $generated++;
                continue;
            }

            try {
                $result = $this->generator->generateForFile($avif);
                if ($result !== null) {
                    $generated++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('   failed for product #%d: %s', $productId, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%s: generated=%d, skipped(has fallback)=%d, failed=%d',
            $dry ? 'Dry run' : 'Done',
            $generated,
            $skipped,
            $failed
        ));

        return Command::SUCCESS;
    }
}
