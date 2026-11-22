<?php

namespace App\Controller\API\Request\Purchase;

/** What CartCheckout needs from a checkout request, logged-in or guest. */
interface CartCheckoutPayload
{
    public function getDeliveryMethod(): ?string;

    public function getDeliveryCity(): ?string;

    public function getDeliveryCityRef(): ?string;

    public function getDeliveryDepartment(): ?string;

    public function getDeliveryDepartmentRef(): ?string;

    public function getDeliveryAddress(): ?string;

    public function getPromocode(): ?string;

    public function isInvoice(): bool;

    public function getPayer(): ?array;

    public function isRememberPayer(): bool;
}
