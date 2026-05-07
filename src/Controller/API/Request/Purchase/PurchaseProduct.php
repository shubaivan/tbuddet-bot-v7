<?php

namespace App\Controller\API\Request\Purchase;

use App\Validator\UserLanguage;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Valid;

#[UserLanguage]
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
    #[Valid]
    protected $product_properties = [];

    #[Type('string')]
    protected ?string $delivery_method = null;

    #[Type('string')]
    protected ?string $delivery_city = null;

    #[Type('string')]
    protected ?string $delivery_city_ref = null;

    #[Type('string')]
    protected ?string $delivery_department = null;

    #[Type('string')]
    protected ?string $delivery_department_ref = null;

    #[Type('string')]
    protected ?string $phone = null;

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getDeliveryMethod(): ?string
    {
        return $this->delivery_method;
    }

    public function getDeliveryCity(): ?string
    {
        return $this->delivery_city;
    }

    public function getDeliveryCityRef(): ?string
    {
        return $this->delivery_city_ref;
    }

    public function getDeliveryDepartment(): ?string
    {
        return $this->delivery_department;
    }

    public function getDeliveryDepartmentRef(): ?string
    {
        return $this->delivery_department_ref;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
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

        return array_values($productPropertiesArray);
    }

    public function setProductProperties(array $product_properties): PurchaseProduct
    {
        $this->product_properties = $product_properties;

        return $this;
    }
}