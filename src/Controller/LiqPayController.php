<?php

namespace App\Controller;

use App\Repository\UserOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LiqPayController extends AbstractController
{
    #[Route('/liq/pay', name: 'app_liq_pay')]
    public function index(
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

        $userOrder->setLiqPayStatus($json_decode['status']);
        $em->flush();

        return $this->json(['status' => true]);
    }
}
