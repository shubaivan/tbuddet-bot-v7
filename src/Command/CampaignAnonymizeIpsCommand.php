<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Campaign\CampaignStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * QR-scan IP retention: full IPs are kept N days (default 90), then the host
 * part is zeroed (IPv4 /24, IPv6 /48).
 * Cron: 20 3 * * *  → php bin/console app:campaign:anonymize-ips --env=prod
 */
#[AsCommand(name: 'app:campaign:anonymize-ips', description: 'Zero the host part of QR-scan IPs older than N days')]
final class CampaignAnonymizeIpsCommand extends Command
{
    public function __construct(private readonly CampaignStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep full IPs this many days', '90')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $dry = (bool) $input->getOption('dry-run');
        $before = new \DateTimeImmutable(sprintf('-%d days', $days));

        $n = $this->store->anonymizeIpsBefore($before, $dry);
        $output->writeln(sprintf('%s%d qr_scan IPs older than %s %s', $dry ? '[dry-run] ' : '', $n, $before->format('Y-m-d H:i'), $dry ? 'would be anonymized' : 'anonymized'));

        return Command::SUCCESS;
    }
}
