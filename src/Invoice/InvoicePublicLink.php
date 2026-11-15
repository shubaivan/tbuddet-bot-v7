<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\UserOrder;

/**
 * Публічне посилання на PDF рахунку — щоб покупець міг переслати його
 * бухгалтеру в Telegram / Viber / WhatsApp без входу на сайт.
 *
 * Токен — HMAC(id + номер рахунку) на секреті застосунку: не вгадується,
 * нічого не зберігає в БД, лише читання. Новий номер рахунку → нове посилання.
 */
final class InvoicePublicLink
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $appSecret,
        private readonly string $apiBaseUrl,
    ) {
    }

    public static function token(int $orderId, string $invoiceNumber, string $secret): string
    {
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'invoice-public:'.$orderId.':'.$invoiceNumber, $secret, true)), '+/', '-_'), '='), 0, 32);
    }

    public function isValid(UserOrder $order, string $token): bool
    {
        $number = $order->getInvoiceNumber();

        return null !== $number && null !== $order->getId()
            && hash_equals(self::token($order->getId(), $number, $this->appSecret), $token);
    }

    public function url(UserOrder $order): ?string
    {
        $number = $order->getInvoiceNumber();
        if (null === $number || null === $order->getId()) {
            return null;
        }

        return rtrim($this->apiBaseUrl, '/').'/invoice/'.$order->getId().'/'.self::token($order->getId(), $number, $this->appSecret).'.pdf';
    }
}
