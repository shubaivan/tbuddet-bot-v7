<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Liqpay\LiqPay;
use Psr\Log\LoggerInterface;

/**
 * The two server-to-server LiqPay calls the payment guard needs
 * (https://www.liqpay.ua/en/doc/api/information/status, …/invoice/cancel).
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

    /** Cancels the LiqPay invoice (`invoice_send`) of this attempt; true when LiqPay confirmed it. */
    public function cancelInvoice(string $liqPayOrderId): bool
    {
        $res = $this->request(['action' => 'invoice_cancel', 'version' => '3', 'order_id' => $liqPayOrderId]);

        return 'ok' === ($res['result'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function request(array $params): ?array
    {
        try {
            $res = (new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey))->api('request', $params, 5);
        } catch (\Throwable $e) {
            $this->logger->error('liqpay api call failed', ['action' => $params['action'], 'order_id' => $params['order_id'], 'error' => $e->getMessage()]);

            return null;
        }

        return \is_array($res) ? $res : null;
    }
}
