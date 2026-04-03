<?php

namespace App\Controller;

use App\Entity\Enum\RoleEnum;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
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
        $userOrder->setLiqPaystatus($newStatus);
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

            $managerMsg = sprintf(
                "<b>Нове замовлення #%d</b>\nСума: %s\n%s%sКлієнт: %s",
                $userOrder->getId(),
                $userOrder->getTotalAmount(),
                $userOrder->getDeliveryCity() ? 'Місто: ' . $userOrder->getDeliveryCity() . "\n" : '',
                $userOrder->getDeliveryDepartment() ? 'Відділення: ' . $userOrder->getDeliveryDepartment() . "\n" : '',
                $clientInfo ?: 'Невідомий'
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
        }

        return $this->json(['status' => true]);
    }
}
