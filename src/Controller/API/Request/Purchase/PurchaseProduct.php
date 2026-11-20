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
    use InvoicePaymentFields;

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

    /** Адреса для доставки по місту (delivery_method = city_courier). */
    #[Type('string')]
    protected ?string $delivery_address = null;

    #[Type('string')]
    protected ?string $contact_phone = null;

    /** Optional promocode entered in the quick-buy modal. */
    #[Type('string')]
    protected ?string $promocode = null;

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

    public function getDeliveryAddress(): ?string
    {
        return $this->delivery_address;
    }

    public function setDeliveryAddress(?string $delivery_address): self
    {
        $this->delivery_address = $delivery_address;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contact_phone;
    }

    public function getPromocode(): ?string
    {
        return $this->promocode;
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

    // Сетери потрібні серіалізатору: без них поля «Купити зараз» (доставка НП, телефон, промокод)
    // мовчки губились — protected-властивості він не заповнює (знайдено 15.11.2026).
    public function setDeliveryMethod(?string $delivery_method): self
    {
        $this->delivery_method = $delivery_method;

        return $this;
    }
    public function setDeliveryCity(?string $delivery_city): self
    {
        $this->delivery_city = $delivery_city;

        return $this;
    }
    public function setDeliveryCityRef(?string $delivery_city_ref): self
    {
        $this->delivery_city_ref = $delivery_city_ref;

        return $this;
    }
    public function setDeliveryDepartment(?string $delivery_department): self
    {
        $this->delivery_department = $delivery_department;

        return $this;
    }
    public function setDeliveryDepartmentRef(?string $delivery_department_ref): self
    {
        $this->delivery_department_ref = $delivery_department_ref;

        return $this;
    }
    public function setContactPhone(?string $contact_phone): self
    {
        $this->contact_phone = $contact_phone;

        return $this;
    }
    public function setPromocode(?string $promocode): self
    {
        $this->promocode = $promocode;

        return $this;
    }
}
