<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Where LiqPay sends the buyer back after the payment page (`result_url`), and
 * the signed key that lets that page read the order's payment status without a
 * login (guest quick buy, or a session that expired while paying).
 *
 * The key is an HMAC over the order id, so the public status endpoint cannot be
 * used to walk through other people's orders.
 */
final class PaymentResultUrl
{
    public function __construct(
        private readonly string $frontendUrl,
        private readonly string $appSecret,
    ) {
    }

    /** @param string $lang FE locale slug: 'uk' or 'en' (never the internal 'ua') */
    public function build(string $lang, int $orderId): string
    {
        return sprintf(
            '%s/%s/payment-success?order=%d&k=%s',
            rtrim($this->frontendUrl, '/'),
            'en' === $lang ? 'en' : 'uk',
            $orderId,
            $this->key($orderId),
        );
    }

    public function key(int $orderId): string
    {
        return substr(hash_hmac('sha256', 'order-status:'.$orderId, $this->appSecret), 0, 20);
    }

    public function isValid(int $orderId, ?string $key): bool
    {
        return null !== $key && '' !== $key && hash_equals($this->key($orderId), $key);
    }
}
