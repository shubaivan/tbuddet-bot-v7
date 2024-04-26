<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LiqPayController extends AbstractController
{
    #[Route('/liq/pay', name: 'app_liq_pay')]
    public function index(LoggerInterface $logger, Request $request): JsonResponse
    {
        $logger->info('content', ['content' => $request->getContent()]);

        return $this->json(['status' => 'ok']);
    }
}
