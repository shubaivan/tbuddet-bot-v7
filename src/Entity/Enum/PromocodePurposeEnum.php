<?php

namespace App\Entity\Enum;

/**
 * Why a promocode exists — lets the system tell a hand-issued/campaign code apart
 * from an auto-generated personal "first order" code.
 *
 * FIRST_ORDER codes are minted by {@see \App\Service\Promocode\FirstOrderPromocodeService}
 * and rotated every 30 days for buyers who have not yet placed a paid order.
 */
enum PromocodePurposeEnum: string
{
    case GENERIC = 'generic';
    case FIRST_ORDER = 'first_order';

    public function label(): string
    {
        return match ($this) {
            self::GENERIC => 'Звичайний',
            self::FIRST_ORDER => 'Перше замовлення',
        };
    }
}
