<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * «Сума прописом» for an invoice: 1250.50 → "Одна тисяча двісті п'ятдесят гривень 50 копійок".
 * Supports amounts below one billion hryvnias, which is plenty for a shop invoice.
 */
final class AmountInWords
{
    private const UNITS_M = ['', 'один', 'два', 'три', 'чотири', "п'ять", 'шість', 'сім', 'вісім', "дев'ять"];
    private const UNITS_F = ['', 'одна', 'дві', 'три', 'чотири', "п'ять", 'шість', 'сім', 'вісім', "дев'ять"];
    private const TEENS = [
        'десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять',
        "п'ятнадцять", 'шістнадцять', 'сімнадцять', 'вісімнадцять', "дев'ятнадцять",
    ];
    private const TENS = [
        '', '', 'двадцять', 'тридцять', 'сорок', "п'ятдесят",
        'шістдесят', 'сімдесят', 'вісімдесят', "дев'яносто",
    ];
    private const HUNDREDS = [
        '', 'сто', 'двісті', 'триста', 'чотириста', "п'ятсот",
        'шістсот', 'сімсот', 'вісімсот', "дев'ятсот",
    ];

    /** [one, few, many] forms. */
    private const HRYVNIA = ['гривня', 'гривні', 'гривень'];
    private const KOPIYKA = ['копійка', 'копійки', 'копійок'];
    private const THOUSAND = ['тисяча', 'тисячі', 'тисяч'];
    private const MILLION = ['мільйон', 'мільйони', 'мільйонів'];

    public static function uah(float|int|string $amount): string
    {
        $kopecks = (int) round(((float) $amount) * 100);
        if ($kopecks < 0 || $kopecks >= 100_000_000_000) {
            throw new \InvalidArgumentException('Amount out of range for words: ' . $amount);
        }

        $hryvnias = intdiv($kopecks, 100);
        $kop = $kopecks % 100;

        $words = $hryvnias === 0 ? 'нуль' : self::integerToWords($hryvnias);
        $result = sprintf(
            '%s %s %02d %s',
            $words,
            self::plural($hryvnias, self::HRYVNIA),
            $kop,
            self::plural($kop, self::KOPIYKA)
        );

        return mb_strtoupper(mb_substr($result, 0, 1)) . mb_substr($result, 1);
    }

    public static function plural(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 14) {
            return $forms[2];
        }

        return match ($n % 10) {
            1 => $forms[0],
            2, 3, 4 => $forms[1],
            default => $forms[2],
        };
    }

    private static function integerToWords(int $n): string
    {
        $parts = [];
        $millions = intdiv($n, 1_000_000);
        $thousands = intdiv($n % 1_000_000, 1000);
        $rest = $n % 1000;

        if ($millions > 0) {
            $parts[] = self::triad($millions, false);
            $parts[] = self::plural($millions, self::MILLION);
        }
        if ($thousands > 0) {
            $parts[] = self::triad($thousands, true);
            $parts[] = self::plural($thousands, self::THOUSAND);
        }
        if ($rest > 0) {
            // Гривня is feminine: «одна гривня», «дві гривні».
            $parts[] = self::triad($rest, true);
        }

        return implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private static function triad(int $n, bool $feminine): string
    {
        $words = [self::HUNDREDS[intdiv($n, 100)]];
        $tensUnits = $n % 100;
        if ($tensUnits >= 10 && $tensUnits <= 19) {
            $words[] = self::TEENS[$tensUnits - 10];
        } else {
            $words[] = self::TENS[intdiv($tensUnits, 10)];
            $words[] = ($feminine ? self::UNITS_F : self::UNITS_M)[$tensUnits % 10];
        }

        return implode(' ', array_filter($words, static fn (string $w): bool => $w !== ''));
    }
}
