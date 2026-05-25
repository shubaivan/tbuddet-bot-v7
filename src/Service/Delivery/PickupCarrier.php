<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\Dto\DeliveryTrackingStatus;

/**
 * In-store pickup ("Самовивіз із Черкас"). No external API — customer takes the
 * order at the warehouse. Implemented so the catalog of options is uniform.
 */
class PickupCarrier implements DeliveryCarrierInterface
{
    public function code(): DeliveryCarrierEnum
    {
        return DeliveryCarrierEnum::PICKUP;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function searchCities(string $query, int $limit = 20): array
    {
        return [];
    }

    public function getDepartments(string $cityRef, int $limit = 50, int $page = 1): array
    {
        return [];
    }

    public function getTrackingStatus(string $trackingNumber): ?DeliveryTrackingStatus
    {
        return null;
    }
}
