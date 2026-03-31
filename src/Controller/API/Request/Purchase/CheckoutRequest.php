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
}