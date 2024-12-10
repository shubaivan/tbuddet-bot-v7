<?php

namespace App\Controller\API\Request\Purchase;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

class PurchaseProduct
{
    #[Type('int')]
    #[NotBlank]
    #[NotNull]
    protected $quantity;

    /**
     * @var ProductProperties[]
     */
    #[Type('array')]
    protected $product_properties = [];

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getProductProperties(): array
    {
        return $this->product_properties;
    }

    public function getProductPropertiesArray(): array
    {
        $productPropertiesArray = [];
        foreach ($this->product_properties as $key => $productProperties) {
            $propertyName = $productProperties->getPropertyName();
            $propertyValue = $productProperties->getPropertyValue();
            $propertyPriceImpact = $productProperties->getPropertyPriceImpact();

            $productPropertiesArray[$key] = [
                'property_name' => $propertyName,
                'property_value' => $propertyValue,
                'property_price_impact' => $propertyPriceImpact,
            ];
        }

        return $productPropertiesArray;
    }

    public function setProductProperties(array $product_properties): PurchaseProduct
    {
        $this->product_properties = $product_properties;

        return $this;
    }
}