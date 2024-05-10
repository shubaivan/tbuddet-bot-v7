<?php

namespace App\Controller;

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

        $userOrder->setLiqPaystatus($json_decode['status']);
        $em->flush();

        if ($userOrder->getTelegramUserid()->getChatId()) {
            if ($json_decode['status'] === 'success') {
                $msg = 'Отримали підтвердження оплати! <b>Дякуємо</b>. З Вами зв\'яжеться наш менеджер';
            } else {
                $msg = 'Статус оплати <b>'.$json_decode['status'].'</b>.';
            }
            /** @var Message $message */
            $message = $bot->sendMessage(
                text: $msg,
                chat_id: $userOrder->getTelegramUserid()->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        return $this->json(['status' => true]);
    }
}
