<?php

namespace App\Service\Delivery\Dto;

final readonly class DeliveryDepartment
{
    public function __construct(
        public string $ref,
        public string $description,
        public string $number = '',
        public string $shortAddress = '',
    ) {}

    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'description' => $this->description,
            'number' => $this->number,
            'shortAddress' => $this->shortAddress,
        ];
    }
}
