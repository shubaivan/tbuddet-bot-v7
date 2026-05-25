<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\Dto\DeliveryTrackingStatus;
use App\Service\Delivery\Exception\CarrierNotConfiguredException;

/**
 * Stub. Will be implemented once Lena provides Ukrposhta business-cabinet
 * Bearer tokens (Address Classifier + Shipments API).
 * See docs/ukrposhta-prerequisites.md.
 */
class UkrposhtaCarrier implements DeliveryCarrierInterface
{
    public function __construct(
        private string $ukrposhtaBearerToken = '',
    ) {}

    public function code(): DeliveryCarrierEnum
    {
        return DeliveryCarrierEnum::UKRPOSHTA;
    }

    public function isConfigured(): bool
    {
        return $this->ukrposhtaBearerToken !== '';
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
