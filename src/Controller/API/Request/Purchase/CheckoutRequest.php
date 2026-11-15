<?php

namespace App\Controller\API\Request\Purchase;

use App\Validator\MatchId;
use App\Validator\UserLanguage;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use App\Entity\PurchaseProduct;

#[UserLanguage]
class CheckoutRequest
{
    #[Type('array')]
    #[NotBlank]
    #[NotNull]
    #[MatchId(actualClass: PurchaseProduct::class)]
    private $purchaseProductIds;

    #[Type('string')]
    private ?string $deliveryCity = null;

    #[Type('string')]
    private ?string $deliveryCityRef = null;

    #[Type('string')]
    private ?string $deliveryDepartment = null;

    #[Type('string')]
    private ?string $deliveryDepartmentRef = null;

    /** nova_poshta | pickup | city_courier. NULL — старий фронт (визначаємо за наявністю міста НП). */
    #[Type('string')]
    #[\Symfony\Component\Validator\Constraints\Choice(choices: \App\Service\Delivery\CityDelivery::METHODS, message: 'Невідомий спосіб доставки.')]
    private ?string $deliveryMethod = null;

    /** Адреса для доставки по місту (обовʼязкова для city_courier, перевіряється в контролері). */
    #[Type('string')]
    private ?string $deliveryAddress = null;

    /** Optional promocode supplied by the buyer. Validated server-side before being applied. */
    #[Type('string')]
    private ?string $promocode = null;

    public function getPurchaseProductIds()
    {
        return $this->purchaseProductIds;
    }

    public function setPurchaseProductIds($purchaseProductIds)
    {
        $this->purchaseProductIds = $purchaseProductIds;

        return $this;
    }

    public function getDeliveryCity(): ?string
    {
        return $this->deliveryCity;
    }

    public function setDeliveryCity(?string $deliveryCity): CheckoutRequest
    {
        $this->deliveryCity = $deliveryCity;

        return $this;
    }

    public function getDeliveryCityRef(): ?string
    {
        return $this->deliveryCityRef;
    }

    public function setDeliveryCityRef(?string $deliveryCityRef): CheckoutRequest
    {
        $this->deliveryCityRef = $deliveryCityRef;

        return $this;
    }

    public function getDeliveryDepartment(): ?string
    {
        return $this->deliveryDepartment;
    }

    public function setDeliveryDepartment(?string $deliveryDepartment): CheckoutRequest
    {
        $this->deliveryDepartment = $deliveryDepartment;

        return $this;
    }

    public function getDeliveryDepartmentRef(): ?string
    {
        return $this->deliveryDepartmentRef;
    }

    public function setDeliveryDepartmentRef(?string $deliveryDepartmentRef): CheckoutRequest
    {
        $this->deliveryDepartmentRef = $deliveryDepartmentRef;

        return $this;
    }

    public function getPromocode(): ?string
    {
        return $this->promocode;
    }

    public function setPromocode(?string $promocode): CheckoutRequest
    {
        $this->promocode = $promocode;

        return $this;
    }

    public function getDeliveryMethod(): ?string
    {
        return $this->deliveryMethod;
    }

    public function setDeliveryMethod(?string $deliveryMethod): self
    {
        $this->deliveryMethod = $deliveryMethod;

        return $this;
    }

    public function getDeliveryAddress(): ?string
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?string $deliveryAddress): self
    {
        $this->deliveryAddress = $deliveryAddress;

        return $this;
    }
}
