<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

/**
 * Чиста логіка health-check консультанта: класифікація помилки Anthropic і
 * перехід стану. Без I/O — щоб усе тестувалось юніт-тестами.
 *
 * Стани: ok | balance | key | other. Тривога — лише на зміні стану.
 * «other» (мережа, 5xx, overloaded) — лише після 2 збоїв поспіль, щоб не
 * смикати групу через разовий збій Anthropic.
 */
final class ConsultantHealthState
{
    public const OK = 'ok';
    public const BALANCE = 'balance';
    public const KEY = 'key';
    public const OTHER = 'other';

    /** Скільки збоїв «other» поспіль потрібно для тривоги. */
    public const OTHER_THRESHOLD = 2;

    /** Класифікує текст/виняток відповіді Anthropic. */
    public static function classify(\Throwable|string $error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;
        $m = mb_strtolower($message);

        if (str_contains($m, 'credit balance')) {
            return self::BALANCE;
        }

        if ($error instanceof \Anthropic\Core\Exceptions\AuthenticationException
            || $error instanceof \Anthropic\Core\Exceptions\PermissionDeniedException
            || str_contains($m, 'invalid x-api-key')
            || str_contains($m, 'authentication_error')
            || str_contains($m, 'permission_error')
            || str_contains($m, 'api key')
        ) {
            return self::KEY;
        }

        return self::OTHER;
    }

    /**
     * Наступний стан за поточним збереженим і щойно спостереженим результатом.
     *
     * @param array{state:string, pending:int}|null $current null — ще не ініціалізовано
     *
     * @return array{state:array{state:string, pending:int}, notify:?string} notify — стан, про який треба повідомити (ok = відновлення)
     */
    public static function next(?array $current, string $observed): array
    {
        // Перший запуск — тихо запам'ятовуємо, що бачимо.
        if (null === $current) {
            return ['state' => ['state' => $observed, 'pending' => 0], 'notify' => null];
        }

        $state = $current['state'];
        $pending = $current['pending'];

        if (self::OK === $observed) {
            return [
                'state' => ['state' => self::OK, 'pending' => 0],
                'notify' => self::OK !== $state ? self::OK : null,
            ];
        }

        if (self::OTHER === $observed) {
            // Вже в стані збою (баланс/ключ/other) — разовий мережевий збій нічого не змінює.
            if (self::OK !== $state) {
                return ['state' => ['state' => $state, 'pending' => 0], 'notify' => null];
            }
            ++$pending;
            if ($pending >= self::OTHER_THRESHOLD) {
                return ['state' => ['state' => self::OTHER, 'pending' => 0], 'notify' => self::OTHER];
            }

            return ['state' => ['state' => self::OK, 'pending' => $pending], 'notify' => null];
        }

        // balance / key — однозначні, тривога одразу (або при зміні виду збою).
        return [
            'state' => ['state' => $observed, 'pending' => 0],
            'notify' => $observed !== $state ? $observed : null,
        ];
    }

    public static function message(string $kind): string
    {
        return match ($kind) {
            self::OK => '✅ <b>Консультант знову працює.</b> Баланс/ключ у порядку, відповідає покупцям.',
            self::BALANCE => '⚠️ <b>Консультант не працює</b>: закінчився баланс Anthropic.'
                ."\nПокупці бачать заглушку «зв'яжіться з оператором»."
                ."\nЩо робити: поповнити баланс — https://platform.claude.com/settings/billing",
            self::KEY => '⚠️ <b>Консультант не працює</b>: недійсний ключ API.'
                ."\nПокупці бачать заглушку «зв'яжіться з оператором»."
                ."\nЩо робити: створити новий ключ — https://platform.claude.com/settings/keys і передати розробнику",
            default => '⚠️ <b>Консультант не працює</b>: збій Anthropic (не відповідає вже ~30 хв).'
                ."\nПокупці бачать заглушку «зв'яжіться з оператором»."
                ."\nЩо робити: зазвичай минає саме; якщо довго — перевірити баланс https://platform.claude.com/settings/billing",
        };
    }
}
