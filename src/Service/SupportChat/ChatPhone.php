<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

/**
 * Телефон із «входу» в чат. Той самий формат, що на фронті (isValidPhone):
 * 0XXXXXXXXX, +380XXXXXXXXX або іноземний з «+»; номер з 1–2 різних цифр
 * (0111111111) — не справжній.
 */
final class ChatPhone
{
    /** Нормалізований номер (+380XXXXXXXXX / +<digits>) або null, якщо номер не схожий на справжній. */
    public static function normalize(string $raw): ?string
    {
        $p = trim($raw);
        if ('' === $p || mb_strlen($p) > 40 || !preg_match('/^\+?[\d\s\-()]+$/', $p)) {
            return null;
        }
        $d = (string) preg_replace('/\D/', '', $p);

        if (preg_match('/^0[1-9]\d{8}$/', $d)) {
            $norm = '+38'.$d;
        } elseif (preg_match('/^380[1-9]\d{8}$/', $d)) {
            $norm = '+'.$d;
        } elseif (str_starts_with($p, '+') && !str_starts_with($d, '380') && preg_match('/^[1-9]\d{7,14}$/', $d)) {
            $norm = '+'.$d;
        } else {
            return null;
        }

        return \count(array_unique(str_split(substr($d, -9)))) > 2 ? $norm : null;
    }
}
