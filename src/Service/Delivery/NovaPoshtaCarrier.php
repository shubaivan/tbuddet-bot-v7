<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\Dto\DeliveryCity;
use App\Service\Delivery\Dto\DeliveryDepartment;
use App\Service\Delivery\Dto\DeliveryTrackingStatus;
use App\Service\NovaPoshtaService;

class NovaPoshtaCarrier implements DeliveryCarrierInterface
{
    // NP StatusCodes that mean "delivered to department / picked up"
    private const DELIVERED_CODES = [7, 8, 9];

    public function __construct(
        private NovaPoshtaService $service,
        private string $novaPoshtaApiKey,
    ) {}

    public function code(): DeliveryCarrierEnum
    {
        return DeliveryCarrierEnum::NOVA_POSHTA;
    }

    public function isConfigured(): bool
    {
        return $this->novaPoshtaApiKey !== '';
    }

    public function searchCities(string $query, int $limit = 20): array
    {
        return array_map(
            fn(array $row) => new DeliveryCity(
                ref: $row['Ref'] ?? '',
                description: $row['Description'] ?? '',
                area: $row['AreaDescription'] ?? '',
                region: $row['RegionsDescription'] ?? '',
            ),
            $this->service->searchCities($query, $limit),
        );
    }

    public function getDepartments(string $cityRef, int $limit = 50, int $page = 1): array
    {
        return array_map(
            fn(array $row) => new DeliveryDepartment(
                ref: $row['Ref'] ?? '',
                description: $row['Description'] ?? '',
                number: $row['Number'] ?? '',
                shortAddress: $row['ShortAddress'] ?? '',
            ),
            $this->service->getWarehouses($cityRef, $limit, $page),
        );
    }

    public function getTrackingStatus(string $trackingNumber): ?DeliveryTrackingStatus
    {
        $raw = $this->service->getTrackingStatus($trackingNumber);
        if (empty($raw)) {
            return null;
        }

        $code = (int) ($raw['StatusCode'] ?? 0);

        return new DeliveryTrackingStatus(
            statusCode: $code,
            statusDescription: (string) ($raw['Status'] ?? ''),
            isDelivered: in_array($code, self::DELIVERED_CODES, true),
        );
    }
}
