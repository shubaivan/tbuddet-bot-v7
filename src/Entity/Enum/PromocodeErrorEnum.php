<?php

namespace App\Entity\Enum;

enum PromocodeErrorEnum: string
{
    case NOT_FOUND = 'not_found';
    case INACTIVE = 'inactive';
    case NOT_YET_VALID = 'not_yet_valid';
    case EXPIRED = 'expired';
    case BELOW_MIN_ORDER = 'below_min_order';
    case NOT_ASSIGNED_TO_YOU = 'not_assigned_to_you';
    case EXHAUSTED = 'exhausted';
    case USER_LIMIT_REACHED = 'user_limit_reached';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case FIRST_ORDER_ONLY = 'first_order_only';
    case PHONE_REQUIRED = 'phone_required';

    public function userMessage(): string
    {
        return match ($this) {
            self::NOT_FOUND => 'Промокод не знайдено',
            self::INACTIVE => 'Промокод неактивний',
            self::NOT_YET_VALID => 'Промокод ще не активний',
            self::EXPIRED => 'Термін дії промокоду минув',
            self::BELOW_MIN_ORDER => 'Сума замовлення менша за мінімальну для цього промокоду',
            self::NOT_ASSIGNED_TO_YOU => 'Цей промокод не для вас',
            self::EXHAUSTED => 'Ліміт використань промокоду вичерпано',
            self::USER_LIMIT_REACHED => 'Ви вже використали цей промокод максимальну кількість разів',
            self::CURRENCY_MISMATCH => 'Промокод не підходить для цієї валюти',
            self::FIRST_ORDER_ONLY => 'Цей промокод діє лише на перше замовлення',
            self::PHONE_REQUIRED => 'Вкажіть телефон — цей промокод діє лише на перше замовлення',
        };
    }
}
