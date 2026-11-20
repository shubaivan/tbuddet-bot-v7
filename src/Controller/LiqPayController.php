<?php

namespace App\Controller;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\RoleEnum;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use App\Service\Analytics\ActivityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class LiqPayController extends AbstractController
{
    #[Route('/liq/pay', name: 'app_liq_pay')]
    public function index(
        Nutgram $bot,
        LoggerInterface $logger,
        Request $request,
        UserOrderRepository $orderRepository,
        TelegramUserRepository $telegramUserRepository,
        EntityManagerInterface $em,
        ActivityService $activity,
        string $liqpayPrivateKey,
    ): JsonResponse
    {
        $logger->info('liqpay_response_content', ['content' => $request->getContent()]);
        $sign = base64_encode( sha1(
            $liqpayPrivateKey .
            $request->request->get('data') .
            $liqpayPrivateKey
            , 1 ));
        if ($request->request->get('signature') !== $sign) {
            $logger->error('liqpay sign error', [
                'actual_sign' => $request->request->get('signature'),
                'expected_sign' => $sign,
            ]);

            return $this->json(['sign' => false], Response::HTTP_UNAUTHORIZED);
        }
        $base64_decode = base64_decode($request->request->get('data'));
        $json_decode = json_decode($base64_decode, true);
        if (!isset($json_decode['order_id'])) {
            $logger->error('liqpay error order_id was not present', [
                'json_decode' => $json_decode,
            ]);

            return $this->json(['sign' => false], Response::HTTP_BAD_REQUEST);
        }
        $order_id = (int) $json_decode['order_id'];
        $userOrder = $orderRepository->getByIdFromLiqPay($order_id);

        if (!$userOrder) {
            $logger->error('liqpay error order_id was not found', [
                'order_id' => $order_id,
            ]);

            return $this->json(['sign' => false], Response::HTTP_BAD_REQUEST);
        }

        $previousStatus = $userOrder->getLiqPaystatus();
        $newStatus = $json_decode['status'];

        // Several checkouts per order are possible (retry, pay later). Once LiqPay
        // confirmed the payment, no other attempt may downgrade it — only a refund
        // (`reversed`) changes a paid order.
        if ($previousStatus === 'success' && $newStatus !== 'success' && $newStatus !== 'reversed') {
            $logger->warning('liqpay callback ignored for paid order', [
                'order_id' => $order_id,
                'new_status' => $newStatus,
                'liqpay_order_id' => $json_decode['order_id'] ?? null,
            ]);

            return $this->json(['status' => true]);
        }

        // A late failure/expiry of an OLDER checkout must not overwrite the status of
        // the current attempt (each retry gets a new "<id>-<time>" order_id).
        $currentAttempt = $userOrder->getLiqPayOrderId();
        if ($newStatus !== 'success' && $newStatus !== 'reversed'
            && null !== $currentAttempt && '' !== $currentAttempt
            && ($json_decode['order_id'] ?? null) !== $currentAttempt) {
            $logger->warning('liqpay callback of an older attempt ignored', [
                'order_id' => $order_id,
                'new_status' => $newStatus,
                'liqpay_order_id' => $json_decode['order_id'] ?? null,
                'current_attempt' => $currentAttempt,
            ]);

            return $this->json(['status' => true]);
        }

        // Money arrived twice: a second checkout paid after the first one, or a card
        // payment after the manager already confirmed the bank transfer → refund alert.
        $paidAgain = $newStatus === 'success'
            && ($userOrder->getPaidAt() !== null
                || ($previousStatus === 'success' && ($json_decode['order_id'] ?? null) !== $userOrder->getLiqPayOrderId()));
        if ($paidAgain) {
            $logger->error('liqpay possible double payment', [
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
                $request->getSchemeAndHttpHost().'/admin/orders/'.$userOrder->getId(),
            );
            foreach ($telegramUserRepository->findByRole(RoleEnum::MANAGER) as $manager) {
                try {
                    $bot->sendMessage(text: $alert, chat_id: $manager->getChatId(), parse_mode: ParseMode::HTML);
                } catch (\Throwable $e) {
                    $logger->error('Failed to send double-payment alert', ['manager_id' => $manager->getId(), 'error' => $e->getMessage()]);
                }
            }
            try {
                $activity->orderPaid($userOrder->getId(), $alert);
            } catch (\Throwable) {
            }

            return $this->json(['status' => true]);
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
        $em->flush();

        $logger->info('liqpay_callback_processed', [
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
            $bot->sendMessage(
                text: $msg,
                chat_id: $userOrder->getTelegramUserid()->getChatId(),
                parse_mode: ParseMode::HTML
            );
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

            $orderUrl = $request->getSchemeAndHttpHost() . '/admin/orders/' . $userOrder->getId();

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

            $managers = $telegramUserRepository->findByRole(RoleEnum::MANAGER);
            foreach ($managers as $manager) {
                try {
                    $bot->sendMessage(
                        text: $managerMsg,
                        chat_id: $manager->getChatId(),
                        parse_mode: ParseMode::HTML
                    );
                } catch (\Throwable $e) {
                    $logger->error('Failed to notify manager', [
                        'manager_id' => $manager->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Той самий факт оплати — одним постом у групу «Заявки ArtBeton».
            $extraHtml = rtrim($amountInfo)
                . ($clientInfo ? "\nКлієнт: " . $clientInfo : '')
                . sprintf("\n\n<a href=\"%s\">Відкрити замовлення</a>", $orderUrl);
            $activity->orderPaid($userOrder->getId(), $extraHtml);
        }

        return $this->json(['status' => true]);
    }
}
