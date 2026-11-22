<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\UserOrder;
use App\Repository\UserOrderRepository;
use App\Service\Payment\LiqPayStatusSync;
use App\Service\Payment\OrderOnlinePayment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orders whose last LiqPay attempt never got a final callback (buyer closed the tab,
 * declined card without callback, lost callback): ask LiqPay `status` and apply the
 * answer with the callback's rules (a found payment notifies managers as usual).
 * Attempts older than the window (--hours, default = LiqPayStatusSync::MAX_ATTEMPT_AGE_SECONDS,
 * i.e. the 7-day first link + 1 day) are never asked about. --exclude-orders skips given
 * order ids (prod: #42 and #54 are old test/abandoned attempts that must stay untouched).
 * Prod cron (/etc/cron.d/artbeton-liqpay-sync, as www-data, hourly):
 *   php8.5 bin/console app:liqpay:sync-pending --env=prod --exclude-orders=42,54
 */
#[AsCommand(name: 'app:liqpay:sync-pending', description: 'Ask LiqPay for the status of unpaid orders whose callback never came')]
final class LiqPaySyncPendingCommand extends Command
{
    public function __construct(
        private readonly UserOrderRepository $orders,
        private readonly LiqPayStatusSync $statusSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Only attempts created within this many hours', (string) intdiv(LiqPayStatusSync::MAX_ATTEMPT_AGE_SECONDS, 3600))
            ->addOption('exclude-orders', null, InputOption::VALUE_REQUIRED, 'Comma-separated order ids never to ask about', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the orders, do not ask LiqPay');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Never wider than the result page's own limit: an older attempt is out of scope.
        $maxAge = min(max(1, (int) $input->getOption('hours')) * 3600, LiqPayStatusSync::MAX_ATTEMPT_AGE_SECONDS);
        $dry = (bool) $input->getOption('dry-run');
        $excluded = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('exclude-orders')))));

        /** @var UserOrder[] $candidates */
        $candidates = $this->orders->createQueryBuilder('o')
            ->andWhere('o.liq_pay_order_id IS NOT NULL')
            ->andWhere('o.paid_at IS NULL')
            ->andWhere('o.liq_pay_status IS NULL OR o.liq_pay_status IN (:inFlight)')
            ->andWhere('o.order_status != :cancelled')
            ->setParameter('inFlight', OrderOnlinePayment::IN_FLIGHT_STATUSES)
            ->setParameter('cancelled', OrderStatusEnum::CANCELLED->value)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        $checked = $changed = 0;
        foreach ($candidates as $order) {
            if (\in_array((int) $order->getId(), $excluded, true)) {
                continue;
            }
            $attempt = (string) $order->getLiqPayOrderId();
            $age = LiqPayStatusSync::attemptAge($attempt);
            if (null === $age || $age < LiqPayStatusSync::MIN_ATTEMPT_AGE_SECONDS || $age > $maxAge) {
                continue;
            }
            ++$checked;
            if ($dry) {
                $output->writeln(sprintf('[dry-run] order #%d attempt %s (%d min old)', $order->getId(), $attempt, intdiv($age, 60)));
                continue;
            }
            $status = $this->statusSync->syncAttempt($attempt);
            if (null !== $status) {
                ++$changed;
                $output->writeln(sprintf('order #%d attempt %s → %s', $order->getId(), $attempt, $status));
            }
        }

        $output->writeln(sprintf('%s%d attempt(s) checked, %d updated', $dry ? '[dry-run] ' : '', $checked, $changed));

        return Command::SUCCESS;
    }
}
