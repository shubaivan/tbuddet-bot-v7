<?php

declare(strict_types=1);

namespace App\Service\Buyer;

/**
 * One key per phone number, whatever way it was typed or stored: the last 9 digits
 * (380671234567, +380 67 123 45 67, 0671234567 → 671234567). Ukrainian numbers are
 * 9 digits after 380 / 0; the stored formats differ between the bot (Telegram sends
 * "380…" or "+380…"), the web profile and the checkout ("380…").
 */
final class PhoneKey
{
    public static function of(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        return \strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    /** The same key in SQL (PostgreSQL), for a column or expression. */
    public static function sql(string $column): string
    {
        return sprintf("NULLIF(RIGHT(REGEXP_REPLACE(COALESCE(%s, ''), '\\D', '', 'g'), 9), '')", $column);
    }
}
