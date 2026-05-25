<?php

namespace App\Service\Delivery\Dto;

final readonly class DeliveryTrackingStatus
{
    public function __construct(
        public int $statusCode,
        public string $statusDescription,
        public bool $isDelivered,
    ) {}
}
