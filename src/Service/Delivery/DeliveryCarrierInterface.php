<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\Dto\DeliveryCity;
use App\Service\Delivery\Dto\DeliveryDepartment;
use App\Service\Delivery\Dto\DeliveryTrackingStatus;

interface DeliveryCarrierInterface
{
    public function code(): DeliveryCarrierEnum;

    public function isConfigured(): bool;

    /** @return DeliveryCity[] */
    public function searchCities(string $query, int $limit = 20): array;

    /** @return DeliveryDepartment[] */
    public function getDepartments(string $cityRef, int $limit = 50, int $page = 1): array;

    public function getTrackingStatus(string $trackingNumber): ?DeliveryTrackingStatus;
}
