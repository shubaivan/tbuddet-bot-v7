<?php

namespace App\Controller\API\Request\Promocode;

use App\Entity\PurchaseProduct;
use App\Validator\MatchId;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

class ValidatePromocodeRequest
{
    #[Type('string')]
    #[NotBlank]
    #[NotNull]
    private ?string $code = null;

    #[Type('array')]
    #[NotBlank]
    #[NotNull]
    #[MatchId(actualClass: PurchaseProduct::class)]
    private array $purchaseProductIds = [];

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getPurchaseProductIds(): array
    {
        return $this->purchaseProductIds;
    }

    public function setPurchaseProductIds(array $purchaseProductIds): self
    {
        $this->purchaseProductIds = $purchaseProductIds;

        return $this;
    }
}
