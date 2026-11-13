<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum PaymentMethodEnum: string
{
    /** LiqPay (card, Privat24, Apple/Google Pay). */
    case CARD = 'card';
    /** «Рахунок на оплату» PDF, bank transfer by a legal entity / ФОП. */
    case INVOICE = 'invoice';

    public function label(): string
    {
        return match ($this) {
            self::CARD => 'Картка (LiqPay)',
            self::INVOICE => 'Рахунок (безготівково)',
        };
    }
}
