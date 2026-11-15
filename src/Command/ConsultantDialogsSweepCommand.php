<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SupportChat\ChatDialogReport;
use App\Service\SupportChat\ChatDialogStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Закриває діалоги консультанта після 20 хв тиші й шле «💬 Діалог завершено»
 * (якщо віджет не встиг надіслати beacon при закритті). Кожен діалог — рівно раз.
 *
 * Крон: *\/5 * * * *  → sudo -u www-data php8.5 bin/console app:consultant:dialogs-sweep --env=prod
 */
#[AsCommand(name: 'app:consultant:dialogs-sweep', description: 'Finish idle consultant dialogs and post the dialog report to topic «Консультант»')]
final class ConsultantDialogsSweepCommand extends Command
{
    public function __construct(
        private readonly ChatDialogStore $dialogs,
        private readonly ChatDialogReport $report,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, які діалоги завершилися б');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = $this->dialogs->idle();
        foreach ($ids as $id) {
            if ($input->getOption('dry-run')) {
                $output->writeln('idle '.$id);
                continue;
            }
            $output->writeln(date('Y-m-d H:i:s').' '.$id.' '.($this->report->finish($id, 'idle') ? 'reported' : 'closed (no questions / already reported)'));
        }

        return Command::SUCCESS;
    }
}
