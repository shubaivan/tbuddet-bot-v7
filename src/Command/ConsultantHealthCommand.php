<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChat\ConsultantHealth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Health-check ІІ-консультанта: виклик Anthropic за ~копійку і повідомлення
 * в тему «Консультант» лише коли стан змінився (не працює / знову працює).
 * Перший запуск лише запам'ятовує стан.
 *
 * Крон: 7-59/15 * * * *  → sudo -u www-data php8.5 bin/console app:consultant:health --env=prod
 */
#[AsCommand(name: 'app:consultant:health', description: 'Probe Anthropic for the AI consultant; alert topic «Консультант» on state change only')]
final class ConsultantHealthCommand extends Command
{
    public function __construct(private readonly ConsultantHealth $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-send', null, InputOption::VALUE_NONE, 'Перевірити й надрукувати, без повідомлень і без зміни стану');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $observed = $this->health->probe();
        $stamp = date('Y-m-d H:i:s');

        if ($input->getOption('no-send')) {
            $current = $this->health->current();
            $output->writeln(\sprintf('%s observed=%s stored=%s (no-send)', $stamp, $observed, $current['state'] ?? 'none'));

            return Command::SUCCESS;
        }

        $r = $this->health->observe($observed);
        $output->writeln(\sprintf(
            '%s observed=%s previous=%s state=%s%s',
            $stamp,
            $observed,
            $r['previous'] ?? 'none',
            $r['state'],
            null !== $r['notified'] ? ' notify='.$r['notified'].($r['sent'] ? ' sent' : ' SEND-FAILED') : '',
        ));

        return null !== $r['notified'] && !$r['sent'] ? Command::FAILURE : Command::SUCCESS;
    }
}
