<?php

declare(strict_types=1);

namespace App\Liqpay;

/**
 * What we keep of a LiqPay payload in the info log: enough to follow an order
 * (attempt, status, money, error code) without the buyer's card mask, phone,
 * name, IP or the rest of the full answer.
 */
final class LiqPayLogSummary
{
    private const FIELDS = [
        'result', 'action', 'status', 'order_id', 'payment_id', 'transaction_id',
        'amount', 'currency', 'paytype', 'err_code', 'err_description', 'code',
    ];

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    public static function of(?array $payload): array
    {
        if (null === $payload) {
            return [];
        }

        return array_intersect_key($payload, array_flip(self::FIELDS));
    }
}
