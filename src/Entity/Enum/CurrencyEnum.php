<?php

namespace App\Entity\Enum;

enum CurrencyEnum: string
{
    case UAH = 'UAH';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::UAH => 'грн',
            self::USD => '$',
        };
    }

    /**
     * Currency used at checkout for the given UI language — matches the rule
     * already applied in ShoppingCartController and PriceRingConversation
     * (UA → UAH, otherwise USD).
     */
    public static function fromUserLanguage(UserLanguageEnum $language): self
    {
        return $language === UserLanguageEnum::UA ? self::UAH : self::USD;
    }
}
