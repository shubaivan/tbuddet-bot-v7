<?php

namespace App\Controller\API\Request\Purchase;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

class ShoppingCartPurchase
{
    /**
     * @var ShoppingCartPurchaseProduct[]
     */
    #[Type('array')]
    #[NotBlank]
    #[NotNull]
    private $purchaseProducts;

    /**
     * @return ShoppingCartPurchaseProduct[]
     */
    public function getPurchaseProducts(): array
    {
        return $this->purchaseProducts;
    }

    public function setPurchaseProducts(array $purchaseProducts): ShoppingCartPurchase
    {
        $this->purchaseProducts = $purchaseProducts;

        return $this;
    }
}