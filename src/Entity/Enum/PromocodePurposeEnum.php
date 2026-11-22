<?php

namespace App\Entity\Enum;

/**
 * Why a promocode exists — lets the system tell a hand-issued/campaign code apart
 * from an auto-generated personal "first order" code.
 *
 * FIRST_ORDER codes are minted by {@see \App\Service\Promocode\FirstOrderPromocodeService}
 * and rotated every 30 days for buyers who have not ordered yet (personal, one buyer).
 * WELCOME is the one public «first order» code for buyers without an account (the guest
 * checkout offers it once the phone has no orders behind it), e.g. WELCOME10.
 * Both work only for a first-time buyer ({@see \App\Entity\Promocode::isFirstOrderOnly()}).
 */
enum PromocodePurposeEnum: string
{
    case GENERIC = 'generic';
    case FIRST_ORDER = 'first_order';
    case WELCOME = 'welcome';

    public function label(): string
    {
        return match ($this) {
            self::GENERIC => 'Звичайний',
            self::FIRST_ORDER => 'Перше замовлення',
            self::WELCOME => 'Перше замовлення (для всіх)',
        };
    }
}
