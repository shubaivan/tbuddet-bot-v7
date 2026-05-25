<?php

namespace App\Service\Delivery\Dto;

final readonly class DeliveryCity
{
    public function __construct(
        public string $ref,
        public string $description,
        public string $area = '',
        public string $region = '',
    ) {}

    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'description' => $this->description,
            'area' => $this->area,
            'region' => $this->region,
        ];
    }
}
