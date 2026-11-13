<?php

namespace App\Entity\Enum;

enum OrderStatusEnum: string
{
    /** Invoice issued to a legal entity; becomes NEW once a manager marks it paid. */
    case AWAITING_INVOICE_PAYMENT = 'awaiting_invoice_payment';
    case NEW = 'new';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AWAITING_INVOICE_PAYMENT => 'Очікує оплати по рахунку',
            self::NEW => 'Нове',
            self::PROCESSING => 'В обробці',
            self::SHIPPED => 'Відправлено',
            self::DELIVERED => 'Доставлено',
            self::CANCELLED => 'Скасовано',
        };
    }
}
