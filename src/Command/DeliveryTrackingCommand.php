<?php

namespace App\Command;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use App\Service\Delivery\DeliveryCarrierRegistry;
use App\Service\Delivery\Exception\CarrierNotConfiguredException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Iterates shipped orders with a tracking number, polls each order's carrier
 * for delivery status, and marks orders DELIVERED when the carrier reports so.
 *
 * Keeps the legacy `app:novaposhta:track` alias so the prod cron entry does
 * not break on deploy.
 */
#[AsCommand(
    name: 'app:delivery:track',
    description: 'Poll delivery carriers for tracking status updates',
    aliases: ['app:novaposhta:track'],
)]
class DeliveryTrackingCommand extends Command
{
    public function __construct(
        private UserOrderRepository $orderRepository,
        private TelegramUserRepository $telegramUserRepository,
        private DeliveryCarrierRegistry $carriers,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orders = $this->orderRepository->findShippedWithTracking();

        if (empty($orders)) {
            $io->info('No shipped orders with tracking numbers.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Checking %d orders...', count($orders)));

        foreach ($orders as $order) {
            $carrierCode = $order->getDeliveryCarrier();
            $carrierEnum = DeliveryCarrierEnum::tryFrom($carrierCode);
            if ($carrierEnum === null) {
                $io->warning(sprintf('Order #%d: unknown carrier "%s"', $order->getId(), $carrierCode));
                continue;
            }

            $carrier = $this->carriers->tryGet($carrierEnum->value);
            if ($carrier === null || !$carrier->isConfigured()) {
                $io->warning(sprintf('Order #%d: carrier "%s" not configured, skipping', $order->getId(), $carrierCode));
                continue;
            }

            // Each carrier owns its own tracking number column; Phase 0 still has
            // only nova_poshta_tracking_number wired. When ukrposhta/meest land,
            // pick the right field per carrier here.
            $tracking = $order->getNovaPoshtaTrackingNumber();
            if ($tracking === null || $tracking === '') {
                continue;
            }

            try {
                $status = $carrier->getTrackingStatus($tracking);
            } catch (CarrierNotConfiguredException $e) {
                $io->warning(sprintf('Order #%d: %s', $order->getId(), $e->getMessage()));
                continue;
            }

            if ($status === null) {
                $io->warning(sprintf('Order #%d: no tracking data for %s', $order->getId(), $tracking));
                continue;
            }

            $io->text(sprintf(
                'Order #%d (%s, %s): StatusCode=%d (%s)',
                $order->getId(),
                $carrierEnum->label(),
                $tracking,
                $status->statusCode,
                $status->statusDescription,
            ));

            if ($status->isDelivered) {
                $order->setOrderStatus(OrderStatusEnum::DELIVERED->value);
                $this->em->flush();

                $io->success(sprintf('Order #%d marked as delivered', $order->getId()));

                $this->notifyDelivered($order, $tracking);
            }
        }

        $io->success('Tracking check complete.');
        return Command::SUCCESS;
    }

    private function notifyDelivered($order, string $tracking): void
    {
        $chatId = $order->getTelegramUserId()?->getChatId();
        if ($chatId) {
            try {
                $this->bot->sendMessage(
                    text: sprintf(
                        "Ваше замовлення #%d <b>доставлено</b> у відділення!\nТТН: <code>%s</code>",
                        $order->getId(),
                        $tracking,
                    ),
                    chat_id: $chatId,
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to notify client about delivery', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $managers = $this->telegramUserRepository->findByRole(RoleEnum::MANAGER);
        foreach ($managers as $manager) {
            try {
                $this->bot->sendMessage(
                    text: sprintf(
                        "Замовлення #%d <b>доставлено</b>\nТТН: <code>%s</code>",
                        $order->getId(),
                        $tracking,
                    ),
                    chat_id: $manager->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable) {}
        }
    }
}
