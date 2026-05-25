<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\Dto\DeliveryTrackingStatus;
use App\Service\Delivery\Exception\CarrierNotConfiguredException;

/**
 * Stub. Awaiting partner contract + API credentials from Meest.
 * See docs/delivery-partnership-meest.md.
 */
class MeestCarrier implements DeliveryCarrierInterface
{
    public function __construct(
        private string $meestApiKey = '',
    ) {}

    public function code(): DeliveryCarrierEnum
    {
        return DeliveryCarrierEnum::MEEST;
    }

    public function isConfigured(): bool
    {
        return $this->meestApiKey !== '';
    }

    public function searchCities(string $query, int $limit = 20): array
    {
        $this->ensureConfigured();
        return [];
    }

    public function getDepartments(string $cityRef, int $limit = 50, int $page = 1): array
    {
        $this->ensureConfigured();
        return [];
    }

    public function getTrackingStatus(string $trackingNumber): ?DeliveryTrackingStatus
    {
        $this->ensureConfigured();
        return null;
    }

    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw CarrierNotConfiguredException::forCarrier($this->code()->value);
        }
    }
}
