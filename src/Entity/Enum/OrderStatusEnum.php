<?php

namespace App\Entity\Enum;

enum OrderStatusEnum: string
{
    case NEW = 'new';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Нове',
            self::PROCESSING => 'В обробці',
            self::SHIPPED => 'Відправлено',
            self::DELIVERED => 'Доставлено',
            self::CANCELLED => 'Скасовано',
        };
    }
}
