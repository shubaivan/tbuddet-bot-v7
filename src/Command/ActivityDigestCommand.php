<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Analytics\ActivityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Надсилає погодинний дайджест активності сайту менеджерам і скидає лічильники.
 * Крон: 0 * * * *  → php bin/console app:activity:digest --env=prod
 */
#[AsCommand(name: 'app:activity:digest', description: 'Send hourly site-activity digest to the managers group')]
final class ActivityDigestCommand extends Command
{
    public function __construct(private readonly ActivityService $activity)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->activity->sendDigest();
        $output->writeln($sent ? 'digest sent' : 'nothing to send');

        return Command::SUCCESS;
    }
}
