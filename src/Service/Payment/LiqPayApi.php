<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Liqpay\LiqPay;
use Psr\Log\LoggerInterface;

/**
 * Server-to-server LiqPay calls (https://www.liqpay.ua/en/doc/api/information/status).
 * A null answer means LiqPay could not be asked (network, empty body).
 */
class LiqPayApi
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] private readonly string $liqpayPublicKey,
        #[\SensitiveParameter] private readonly string $liqpayPrivateKey,
    ) {
    }

    /** @return array<string, mixed>|null payment data, or {"status":"error","err_code":"payment_not_found"} */
    public function status(string $liqPayOrderId): ?array
    {
        return $this->request(['action' => 'status', 'version' => '3', 'order_id' => $liqPayOrderId]);
    }

    /** @return array<string, mixed>|null */
    private function request(array $params): ?array
    {
        try {
            $res = (new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey))->api('request', $params, 10);
        } catch (\Throwable $e) {
            $this->logger->error('liqpay api call failed', ['action' => $params['action'], 'order_id' => $params['order_id'], 'error' => $e->getMessage()]);

            return null;
        }

        return \is_array($res) ? $res : null;
    }
}
