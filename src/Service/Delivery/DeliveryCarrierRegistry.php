<?php

namespace App\Service\Delivery;

use App\Entity\Enum\DeliveryCarrierEnum;

class DeliveryCarrierRegistry
{
    /** @var array<string, DeliveryCarrierInterface> */
    private array $carriers = [];

    /**
     * @param iterable<DeliveryCarrierInterface> $carriers
     */
    public function __construct(iterable $carriers)
    {
        foreach ($carriers as $carrier) {
            $this->carriers[$carrier->code()->value] = $carrier;
        }
    }

    public function get(DeliveryCarrierEnum $code): DeliveryCarrierInterface
    {
        if (!isset($this->carriers[$code->value])) {
            throw new \InvalidArgumentException(sprintf('No carrier registered for "%s".', $code->value));
        }

        return $this->carriers[$code->value];
    }

    public function tryGet(string $code): ?DeliveryCarrierInterface
    {
        return $this->carriers[$code] ?? null;
    }

    /** @return DeliveryCarrierInterface[] */
    public function all(): array
    {
        return array_values($this->carriers);
    }

    /** @return DeliveryCarrierInterface[] */
    public function configured(): array
    {
        return array_values(array_filter($this->carriers, fn($c) => $c->isConfigured()));
    }
}
