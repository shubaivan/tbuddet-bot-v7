<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Entity\UserOrder;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use App\Service\Analytics\ActivityService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Applies one LiqPay payment state to its order: the signed /liq/pay callback and
 * the status pull (LiqPayStatusSync, when the callback never came) go through the
 * same rules — paid orders are never downgraded, late results of older attempts are
 * ignored, a second success is a double payment (alert, no state change), and the
 * buyer/managers are notified exactly once when the order becomes paid.
 */
class LiqPayPaymentProcessor
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly LoggerInterface $logger,
        private readonly UserOrderRepository $orderRepository,
        private readonly TelegramUserRepository $telegramUserRepository,
        private readonly EntityManagerInterface $em,
        private readonly ActivityService $activity,
    ) {
    }

    /**
     * @param array<string, mixed> $json_decode LiqPay payment data (callback `data` or `status` API answer)
     * @param string               $baseUrl     scheme + host of this API, for the admin order link
     *
     * @return bool false when the order of this payment does not exist
     */
    public function process(array $json_decode, string $baseUrl): bool
    {
        $order_id = (int) ($json_decode['order_id'] ?? 0);
        $userOrder = $this->orderRepository->getByIdFromLiqPay($order_id);
        if (!$userOrder) {
            $this->logger->error('liqpay error order_id was not found', [
                'order_id' => $order_id,
            ]);

            return false;
        }

        // The callback and the status pull may arrive at the same time: decide on the
        // freshly read row, locked until the new status is written.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($userOrder, LockMode::PESSIMISTIC_WRITE); // SELECT … FOR UPDATE
            $result = $this->apply($userOrder, $json_decode, $baseUrl, $order_id);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
        $this->releaseLock();

        return $result;
    }

    private function releaseLock(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    private function apply(UserOrder $userOrder, array $json_decode, string $baseUrl, int $order_id): bool
    {
        $previousStatus = $userOrder->getLiqPaystatus();
        $newStatus = $json_decode['status'];

        // The same answer about the same attempt again (repeated callback, or the pull
        // while a payment is in flight): nothing changes, nobody is told again.
        if ($previousStatus === $newStatus && ($json_decode['order_id'] ?? null) === $userOrder->getLiqPayOrderId()) {
            return true;
        }

        // Several checkouts per order are possible (retry, pay later). Once LiqPay
        // confirmed the payment, no other attempt may downgrade it — only a refund
        // (`reversed`) changes a paid order.
        if ($previousStatus === 'success' && $newStatus !== 'success' && $newStatus !== 'reversed') {
            $this->logger->warning('liqpay callback ignored for paid order', [
                'order_id' => $order_id,
                'new_status' => $newStatus,
                'liqpay_order_id' => $json_decode['order_id'] ?? null,
            ]);

            return true;
        }

        // A late failure/expiry of an OLDER checkout must not overwrite the status of
        // the current attempt (each retry gets a new "<id>-<time>" order_id).
        $currentAttempt = $userOrder->getLiqPayOrderId();
        if ($newStatus !== 'success' && $newStatus !== 'reversed'
            && null !== $currentAttempt && '' !== $currentAttempt
            && ($json_decode['order_id'] ?? null) !== $currentAttempt) {
            $this->logger->warning('liqpay callback of an older attempt ignored', [
                'order_id' => $order_id,
                'new_status' => $newStatus,
                'liqpay_order_id' => $json_decode['order_id'] ?? null,
                'current_attempt' => $currentAttempt,
            ]);

            return true;
        }

        // Money arrived twice: a second checkout paid after the first one, or a card
        // payment after the manager already confirmed the bank transfer → refund alert.
        $paidAgain = $newStatus === 'success'
            && ($userOrder->getPaidAt() !== null
                || ($previousStatus === 'success' && ($json_decode['order_id'] ?? null) !== $userOrder->getLiqPayOrderId()));
        if ($paidAgain) {
            $this->releaseLock();
            $this->logger->error('liqpay possible double payment', [
                'order_id' => $order_id,
                'liqpay_order_id' => $json_decode['order_id'] ?? null,
                'payment_id' => $json_decode['payment_id'] ?? null,
                'amount' => $json_decode['amount'] ?? null,
            ]);
            $alert = sprintf(
                "⚠️ <b>Можлива подвійна оплата — замовлення #%d</b>\nLiqPay %s, payment_id %s, сума %s\nЗамовлення вже було оплачене%s. Перевірте в кабінеті LiqPay і зробіть повернення.\n\n<a href=\"%s\">Відкрити замовлення</a>",
                $userOrder->getId(),
                htmlspecialchars((string) ($json_decode['order_id'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($json_decode['payment_id'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($json_decode['amount'] ?? ''), ENT_QUOTES),
                $userOrder->getPaidAt() !== null ? ' (банківський переказ підтверджено менеджером)' : '',
                $baseUrl.'/admin/orders/'.$userOrder->getId(),
            );
            foreach ($this->telegramUserRepository->findByRole(RoleEnum::MANAGER) as $manager) {
                try {
                    $this->bot->sendMessage(text: $alert, chat_id: $manager->getChatId(), parse_mode: ParseMode::HTML);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send double-payment alert', ['manager_id' => $manager->getId(), 'error' => $e->getMessage()]);
                }
            }
            try {
                $this->activity->orderPaid($userOrder->getId(), $alert);
            } catch (\Throwable) {
            }

            return true;
        }

        $userOrder->setLiqPaystatus($newStatus);
        if ($newStatus === 'success' && isset($json_decode['order_id'])) {
            // Remember WHICH attempt paid: a repeated callback of it is harmless,
            // a success from any other attempt is a double payment (see above).
            $userOrder->setLiqPayOrderId((string) $json_decode['order_id']);
        }
        // Invoice order paid online by card («Оплатити онлайн»): same as a confirmed
        // bank transfer — out of «Очікує оплати по рахунку» into the normal queue.
        if ($newStatus === 'success'
            && $userOrder->isInvoicePayment()
            && $userOrder->getOrderStatus() === OrderStatusEnum::AWAITING_INVOICE_PAYMENT->value
        ) {
            $userOrder->setOrderStatus(OrderStatusEnum::NEW->value);
        }
        $this->em->flush();
        $this->releaseLock();

        $this->logger->info('liqpay_callback_processed', [
            'order_id' => $order_id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'liqpay_order_id' => $json_decode['order_id'] ?? null,
            'amount' => $json_decode['amount'] ?? null,
            'payment_id' => $json_decode['payment_id'] ?? null,
            'action' => $json_decode['action'] ?? null,
        ]);

        // Only notify user if status actually changed to success (prevent duplicate messages)
        $alreadyNotified = $previousStatus === 'success';

        if (!$alreadyNotified
            && $userOrder->getTelegramUserid()
            && $userOrder->getTelegramUserid()->getChatId()
        ) {
            if ($newStatus === 'success') {
                $msg = 'Отримали підтвердження оплати! <b>Дякуємо</b>. З Вами зв\'яжеться наш менеджер';
            } else {
                $msg = 'Статус оплати: <b>'.$newStatus.'</b>';
            }
            // A blocked bot must not cost the managers their «paid» notification below.
            try {
                $this->bot->sendMessage(
                    text: $msg,
                    chat_id: $userOrder->getTelegramUserid()->getChatId(),
                    parse_mode: ParseMode::HTML
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to notify buyer about payment status', ['order_id' => $order_id, 'error' => $e->getMessage()]);
            }
        }

        // Notify managers about new paid order (only once, not on duplicate callbacks)
        if (!$alreadyNotified && $newStatus === 'success') {
            $clientInfo = '';
            if ($userOrder->getClientUserId()) {
                $cu = $userOrder->getClientUserId();
                $clientInfo = trim($cu->getFirstName() . ' ' . $cu->getLastName()) . ', ' . $cu->getPhone();
            } elseif ($userOrder->getTelegramUserid()) {
                $tu = $userOrder->getTelegramUserid();
                $clientInfo = trim($tu->getFirstName() . ' ' . ($tu->getLastName() ?? ''));
                if ($tu->getPhoneNumber()) {
                    $clientInfo .= ', ' . $tu->getPhoneNumber();
                }
            }

            $orderUrl = $baseUrl . '/admin/orders/' . $userOrder->getId();

            // When a promocode was applied, spell out the original amount and the
            // discount so the manager sees what the customer actually paid vs. the
            // catalogue price. Otherwise keep the single-line "Сума".
            if ($userOrder->getPromocodeCodeUsed() && $userOrder->getDiscountAmount() > 0) {
                $subtotal = $userOrder->getSubtotalAmount()
                    ?? ($userOrder->getTotalAmount() + $userOrder->getDiscountAmount());
                $amountInfo = sprintf(
                    "Сума до сплати: %s грн\nПочаткова сума: %s грн\nПромокод: %s (знижка %s грн)\n",
                    $userOrder->getTotalAmount(),
                    $subtotal,
                    $userOrder->getPromocodeCodeUsed(),
                    $userOrder->getDiscountAmount()
                );
            } else {
                $amountInfo = sprintf("Сума: %s грн\n", $userOrder->getTotalAmount());
            }
            if ($userOrder->isInvoicePayment()) {
                $amountInfo = sprintf("🧾 Рахунок № %s оплачено карткою онлайн (LiqPay)\n", htmlspecialchars((string) $userOrder->getInvoiceNumber(), ENT_QUOTES)).$amountInfo;
            }
            if ($userOrder->getDeliveryAmount() > 0) {
                $amountInfo .= sprintf("У т.ч. доставка: %s грн\n🚚 %s\n", $userOrder->getDeliveryAmount(), htmlspecialchars((string) $userOrder->getDeliveryAddress(), ENT_QUOTES));
            }

            $managerMsg = sprintf(
                "<b>Нове замовлення #%d</b>\n%s%s%sКлієнт: %s\n\n<a href=\"%s\">Відкрити замовлення</a>",
                $userOrder->getId(),
                $amountInfo,
                $userOrder->getDeliveryCity() ? 'Місто: ' . $userOrder->getDeliveryCity() . "\n" : '',
                $userOrder->getDeliveryDepartment() ? 'Відділення: ' . $userOrder->getDeliveryDepartment() . "\n" : '',
                $clientInfo ?: 'Невідомий',
                $orderUrl
            );

            $managers = $this->telegramUserRepository->findByRole(RoleEnum::MANAGER);
            foreach ($managers as $manager) {
                try {
                    $this->bot->sendMessage(
                        text: $managerMsg,
                        chat_id: $manager->getChatId(),
                        parse_mode: ParseMode::HTML
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to notify manager', [
                        'manager_id' => $manager->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Той самий факт оплати — одним постом у групу «Заявки ArtBeton».
            $extraHtml = rtrim($amountInfo)
                . ($clientInfo ? "\nКлієнт: " . $clientInfo : '')
                . sprintf("\n\n<a href=\"%s\">Відкрити замовлення</a>", $orderUrl);
            try {
                $this->activity->orderPaid($userOrder->getId(), $extraHtml);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to post the payment to the manager group', ['order_id' => $order_id, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }
}
