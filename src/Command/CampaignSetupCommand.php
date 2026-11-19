<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Campaign;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Enum\PromocodePurposeEnum;
use App\Entity\Promocode;
use App\Repository\CampaignRepository;
use App\Repository\PromocodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotent upsert of a QR campaign and (optionally) its percent promocode.
 *
 *   php bin/console app:campaign:setup ch27 --name="Черкаси, бізнес-подія 27–28.11" \
 *       --code=ARTBETON15 --percent=15 --valid-to=2027-11-18 --max-per-user=1 --dry-run
 *
 * Existing promocode: only the fields passed are updated, times_used is never touched.
 */
#[AsCommand(name: 'app:campaign:setup', description: 'Create or update a QR campaign (+ its promocode)')]
final class CampaignSetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignRepository $campaigns,
        private readonly PromocodeRepository $promocodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Campaign slug used in /q/{slug} (a-z0-9_-)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Campaign name shown in the admin')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Site path to land on', null)
            ->addOption('attribution-days', null, InputOption::VALUE_REQUIRED, 'First-touch window, days', null)
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Create/keep the campaign switched off')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Promocode to attach (created if missing)')
            ->addOption('percent', null, InputOption::VALUE_REQUIRED, 'Percent discount for a new/updated code')
            ->addOption('valid-to', null, InputOption::VALUE_REQUIRED, 'Code valid to (inclusive date, Y-m-d)')
            ->addOption('max-per-user', null, InputOption::VALUE_REQUIRED, 'Max uses per buyer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        $slug = Campaign::normalizeSlug((string) $input->getArgument('slug'));
        if (!Campaign::isValidSlug($slug)) {
            $output->writeln('<error>Bad slug: use 1–32 chars a-z 0-9 _ -</error>');

            return Command::INVALID;
        }

        $promocode = null;
        $code = $input->getOption('code');
        if (null !== $code && '' !== $code) {
            $code = strtoupper(trim((string) $code));
            if (1 !== preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
                $output->writeln('<error>Code must be Latin A-Z 0-9 _ - (3–32): Cyrillic and % break lookup</error>');

                return Command::INVALID;
            }
            $promocode = $this->promocodes->findActiveByCode($code);
            $isNew = null === $promocode;
            if ($isNew) {
                if (null === $input->getOption('percent')) {
                    $output->writeln('<error>New code needs --percent</error>');

                    return Command::INVALID;
                }
                $promocode = (new Promocode())
                    ->setCode($code)
                    ->setCurrency(CurrencyEnum::UAH)
                    ->setPurpose(PromocodePurposeEnum::GENERIC)
                    ->setIsActive(true);
            }
            if (null !== $input->getOption('percent')) {
                $percent = (int) $input->getOption('percent');
                if ($percent < 1 || $percent > 90) {
                    $output->writeln('<error>--percent must be 1..90</error>');

                    return Command::INVALID;
                }
                $promocode->setDiscountType(DiscountTypeEnum::PERCENT)->setValue($percent);
            }
            if (null !== $input->getOption('valid-to')) {
                $validTo = \DateTime::createFromFormat('!Y-m-d', (string) $input->getOption('valid-to'));
                if (false === $validTo) {
                    $output->writeln('<error>--valid-to must be Y-m-d</error>');

                    return Command::INVALID;
                }
                $promocode->setValidTo($validTo->setTime(23, 59, 59));
            }
            if (null !== $input->getOption('max-per-user')) {
                $promocode->setMaxUsesPerUser(max(1, (int) $input->getOption('max-per-user')));
            }
            $output->writeln(sprintf(
                '%s promocode %s: %s%%, %s, valid to %s, max/user %s, active %s, used %d',
                $isNew ? 'CREATE' : 'UPDATE',
                $promocode->getCode(),
                $promocode->getValue(),
                $promocode->getCurrency()->value,
                $promocode->getValidTo()?->format('Y-m-d H:i:s') ?? '∞',
                $promocode->getMaxUsesPerUser() ?? '∞',
                $promocode->isActive() ? 'yes' : 'NO',
                $promocode->getTimesUsed(),
            ));
            if (!$dry) {
                $this->em->persist($promocode);
            }
        }

        $campaign = $this->campaigns->findBySlug($slug);
        $isNewCampaign = null === $campaign;
        $campaign ??= (new Campaign())->setSlug($slug);
        if (null !== $input->getOption('name')) {
            $campaign->setName((string) $input->getOption('name'));
        }
        if (null !== $input->getOption('target')) {
            $campaign->setTargetPath((string) $input->getOption('target'));
        }
        if (null !== $input->getOption('attribution-days')) {
            $campaign->setAttributionDays((int) $input->getOption('attribution-days'));
        }
        if ($isNewCampaign || $input->getOption('inactive')) {
            $campaign->setIsActive(!$input->getOption('inactive'));
        }
        if (null !== $promocode) {
            $campaign->setPromocode($promocode);
        }

        $output->writeln(sprintf(
            '%s campaign %s "%s": target %s, code %s, attribution %d days, active %s',
            $isNewCampaign ? 'CREATE' : 'UPDATE',
            $campaign->getSlug(),
            $campaign->getName(),
            $campaign->getTargetPath(),
            $campaign->getPromocode()?->getCode() ?? '—',
            $campaign->getAttributionDays(),
            $campaign->isActive() ? 'yes' : 'NO',
        ));

        if ($dry) {
            $output->writeln('<comment>dry-run: nothing written</comment>');

            return Command::SUCCESS;
        }

        $this->em->persist($campaign);
        $this->em->flush();
        $output->writeln(sprintf('<info>saved: campaign #%d, QR URL = /q/%s</info>', $campaign->getId(), $campaign->getSlug()));

        return Command::SUCCESS;
    }
}
