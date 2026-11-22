<?php

declare(strict_types=1);

namespace App\Service\Cart;

/**
 * The checkout refused the order before anything was saved (delivery, promocode,
 * invoice availability…). Carries the exact JSON body + status the API answers.
 */
final class CheckoutRejected extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload, public readonly int $status)
    {
        parent::__construct((string) ($payload['error'] ?? 'checkout_rejected'));
    }
}
