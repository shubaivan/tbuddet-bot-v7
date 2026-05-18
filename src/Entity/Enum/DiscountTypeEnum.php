<?php

namespace App\Entity\Enum;

enum DiscountTypeEnum: string
{
    case PERCENT = 'percent';
    case FIXED_AMOUNT = 'fixed_amount';

    public function label(): string
    {
        return match ($this) {
            self::PERCENT => 'Відсоток',
            self::FIXED_AMOUNT => 'Фіксована сума',
        };
    }
}
