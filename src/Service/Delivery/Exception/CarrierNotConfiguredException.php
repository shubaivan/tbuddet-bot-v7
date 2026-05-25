<?php

namespace App\Service\Delivery\Exception;

class CarrierNotConfiguredException extends \RuntimeException
{
    public static function forCarrier(string $carrierCode): self
    {
        return new self(sprintf('Delivery carrier "%s" is not configured (API credentials missing).', $carrierCode));
    }
}
