<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Analytics\TelegramNotifier;
use App\Service\SupportChat\ConsultantUsage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Щоденна сводка ІІ-консультанта в тему «Консультант»: скільки відповідей за
 * вчора, скільки це коштувало, сума з початку місяця, збої й запити оператора.
 *
 * Крон: 0 6 * * *  → php bin/console app:consultant:daily --env=prod (9:00 за Києвом).
 */
#[AsCommand(name: 'app:consultant:daily', description: 'Daily AI-consultant usage & spend to the managers group (topic «Консультант»)')]
final class ConsultantDailyCommand extends Command
{
    public function __construct(
        private readonly ConsultantUsage $usage,
        private readonly TelegramNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-send', null, InputOption::VALUE_NONE, 'Лише надрукувати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $day = new \DateTimeImmutable('yesterday');
        $d = $this->usage->day($day);

        $monthUsd = 0.0;
        $monthUah = 0.0;
        for ($date = $day->modify('first day of this month'); $date <= $day; $date = $date->modify('+1 day')) {
            $m = $this->usage->day($date);
            $monthUsd += $m['usd'];
            $monthUah += $m['uah'];
        }

        $lines = [
            '🤖 <b>Консультант за '.$day->format('d.m').'</b>',
            'Відповідей: <b>'.$d['replies'].'</b>'.($d['operator'] > 0 ? ' · запитів оператора: '.$d['operator'] : ''),
            'Витрачено: <b>'.$this->uah($d['uah']).'</b> ('.$this->usd($d['usd']).')',
            'З початку місяця: <b>'.$this->uah($monthUah).'</b> ('.$this->usd($monthUsd).')',
        ];
        if ($d['failures'] > 0) {
            $lines[] = '⚠️ Без відповіді через збій Anthropic: '.$d['failures'];
        }
        $lines[] = '<i>Розрахунок за токенами; залишок балансу — console.anthropic.com/settings/billing</i>';

        $text = implode("\n", $lines);
        $output->writeln(strip_tags($text));

        if (!$input->getOption('no-send')) {
            $output->writeln($this->notifier->send($text, TelegramNotifier::TOPIC_CONSULTANT) ? 'sent' : '<error>send failed</error>');
        }

        return Command::SUCCESS;
    }

    private function uah(float $v): string
    {
        return number_format($v, 2, ',', ' ').' грн';
    }

    private function usd(float $v): string
    {
        return '$'.number_format($v, 3, '.', '');
    }
}
