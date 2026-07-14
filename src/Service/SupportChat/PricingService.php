<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

/**
 * Cost of AI processing (Claude tokens → UAH) for the support-chat consultant.
 *
 * Claude Haiku 4.5 pricing: $1.00 / 1M input tokens, $5.00 / 1M output tokens.
 * The USD→UAH rate is taken from env (USD_UAH_RATE) because it drifts.
 */
final class PricingService
{
    private const USD_PER_MTOK_IN = 1.00;
    private const USD_PER_MTOK_OUT = 5.00;

    public function __construct(private readonly float $usdUahRate)
    {
    }

    public function costUsd(int $inputTokens, int $outputTokens): float
    {
        return $inputTokens / 1_000_000 * self::USD_PER_MTOK_IN
            + $outputTokens / 1_000_000 * self::USD_PER_MTOK_OUT;
    }

    public function costUah(int $inputTokens, int $outputTokens): float
    {
        return $this->costUsd($inputTokens, $outputTokens) * $this->usdUahRate;
    }

    /** Formatted string for the manager message, e.g. "0,14 грн". */
    public function formatUah(int $inputTokens, int $outputTokens): string
    {
        $uah = $this->costUah($inputTokens, $outputTokens);

        return number_format($uah, 2, ',', ' ').' грн';
    }
}
