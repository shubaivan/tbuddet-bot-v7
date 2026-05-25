<?php

namespace App\Entity\Enum;

enum DeliveryCarrierEnum: string
{
    case NOVA_POSHTA = 'nova_poshta';
    case UKRPOSHTA = 'ukrposhta';
    case MEEST = 'meest';
    case JUSTIN = 'justin';
    case PICKUP = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::NOVA_POSHTA => 'Нова Пошта',
            self::UKRPOSHTA => 'Укрпошта',
            self::MEEST => 'Meest',
            self::JUSTIN => 'Justin',
            self::PICKUP => 'Самовивіз із Черкас',
        };
    }
}
