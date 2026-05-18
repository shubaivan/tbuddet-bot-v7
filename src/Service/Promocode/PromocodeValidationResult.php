<?php

namespace App\Service\Promocode;

use App\Entity\Enum\PromocodeErrorEnum;
use App\Entity\Promocode;

/**
 * Outcome of PromocodeService::validate(). On success ($ok=true) callers may
 * use $discount to display the preview and pass $promocode to redeem().
 * On failure $error explains why and is suitable for translation at the UI layer.
 */
final class PromocodeValidationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $discount,
        public readonly ?Promocode $promocode,
        public readonly ?PromocodeErrorEnum $error,
    ) {
    }

    public static function success(Promocode $promocode, int $discount): self
    {
        return new self(true, $discount, $promocode, null);
    }

    public static function failure(PromocodeErrorEnum $error): self
    {
        return new self(false, 0, null, $error);
    }
}
