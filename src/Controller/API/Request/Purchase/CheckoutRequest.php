<?php

namespace App\Controller\API\Request\Purchase;

use App\Validator\MatchId;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use App\Entity\PurchaseProduct;

class CheckoutRequest
{
    #[Type('array')]
    #[NotBlank]
    #[NotNull]
    #[MatchId(actualClass: PurchaseProduct::class)]
    private $purchaseProductIds;

    public function getPurchaseProductIds()
    {
        return $this->purchaseProductIds;
    }

    public function setPurchaseProductIds($purchaseProductIds)
    {
        $this->purchaseProductIds = $purchaseProductIds;

        return $this;
    }
}