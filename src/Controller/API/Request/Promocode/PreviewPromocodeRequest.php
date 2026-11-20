<?php

declare(strict_types=1);

namespace App\Controller\API\Request\Promocode;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Type;

/** Quick buy: preview a code against an amount (no cart). The order re-validates it. */
class PreviewPromocodeRequest
{
    #[Type('string')]
    #[NotBlank]
    private ?string $code = null;

    #[Type('numeric')]
    #[PositiveOrZero]
    private int|float $amount = 0;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getAmount(): int|float
    {
        return $this->amount;
    }

    public function setAmount(int|float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }
}
