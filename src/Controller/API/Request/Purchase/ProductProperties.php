<?php

namespace App\Controller\API\Request\Purchase;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

class ProductProperties
{
    #[Type('int')]
    #[NotBlank(allowNull: false)]
    private string $property_name;

    #[Type('int')]
    #[NotBlank(allowNull: false)]
    private string $property_value;

    #[Type('int')]
    #[NotBlank(allowNull: false)]
    private int $property_price_impact;

    public function getPropertyName(): string
    {
        return $this->property_name;
    }

    public function setPropertyName(string $property_name): ProductProperties
    {
        $this->property_name = $property_name;

        return $this;
    }

    public function getPropertyValue(): string
    {
        return $this->property_value;
    }

    public function setPropertyValue(string $property_value): ProductProperties
    {
        $this->property_value = $property_value;

        return $this;
    }

    public function getPropertyPriceImpact(): int
    {
        return $this->property_price_impact;
    }

    public function setPropertyPriceImpact(int $property_price_impact): ProductProperties
    {
        $this->property_price_impact = $property_price_impact;

        return $this;
    }
}