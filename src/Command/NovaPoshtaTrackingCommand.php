<?php

namespace App\Command;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use App\Service\NovaPoshtaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:novaposhta:track',
    description: 'Check Nova Poshta delivery status for shipped orders',
)]
class NovaPoshtaTrackingCommand extends Command
{
    // NP StatusCodes that mean "delivered to department"
    private const DELIVERED_CODES = [7, 8, 9];
    // 7 = picked up, 8 = picked up (date), 9 = delivered

    public function __construct(
        private UserOrderRepository $orderRepository,
        private TelegramUserRepository $telegramUserRepository,
        private NovaPoshtaService $novaPoshtaService,
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
            $tracking = $order->getNovaPoshtaTrackingNumber();
            $status = $this->novaPoshtaService->getTrackingStatus($tracking);

            if (empty($status)) {
                $io->warning(sprintf('Order #%d: no tracking data for %s', $order->getId(), $tracking));
                continue;
            }

            $statusCode = (int)($status['StatusCode'] ?? 0);
            $statusDescription = $status['Status'] ?? '';

            $io->text(sprintf('Order #%d (TTN %s): StatusCode=%d (%s)',
                $order->getId(), $tracking, $statusCode, $statusDescription));

            if (in_array($statusCode, self::DELIVERED_CODES, true)) {
                $order->setOrderStatus(OrderStatusEnum::DELIVERED->value);
                $this->em->flush();

                $io->success(sprintf('Order #%d marked as delivered', $order->getId()));

                // Notify client
                $chatId = $order->getTelegramUserId()?->getChatId();
                if ($chatId) {
                    try {
                        $this->bot->sendMessage(
                            text: sprintf(
                                "Ваше замовлення #%d <b>доставлено</b> у відділення!\nТТН: <code>%s</code>",
                                $order->getId(),
                                $tracking
                            ),
                            chat_id: $chatId,
                            parse_mode: ParseMode::HTML
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to notify client about delivery', [
                            'order_id' => $order->getId(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Notify managers
                $managers = $this->telegramUserRepository->findByRole(RoleEnum::MANAGER);
                foreach ($managers as $manager) {
                    try {
                        $this->bot->sendMessage(
                            text: sprintf(
                                "Замовлення #%d <b>доставлено</b>\nТТН: <code>%s</code>",
                                $order->getId(),
                                $tracking
                            ),
                            chat_id: $manager->getChatId(),
                            parse_mode: ParseMode::HTML
                        );
                    } catch (\Throwable) {}
                }
            }
        }

        $io->success('Tracking check complete.');
        return Command::SUCCESS;
    }
}
