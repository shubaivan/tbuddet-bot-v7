<?php

namespace App\Controller\API\Request\Purchase;

use App\Entity\Product;
use App\Validator\MatchId;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

class ShoppingCartPurchaseProduct extends PurchaseProduct
{
    #[Type('int')]
    #[NotBlank]
    #[NotNull]
    #[MatchId(actualClass: Product::class)]
    protected $product_id;

    public function getProductId()
    {
        return $this->product_id;
    }

    public function setProductId($product_id)
    {
        $this->product_id = $product_id;

        return $this;
    }
}