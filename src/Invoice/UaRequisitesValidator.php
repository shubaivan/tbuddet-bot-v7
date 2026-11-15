<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * Checksum validation for Ukrainian requisites used on a «Рахунок на оплату».
 *
 * - ЄДРПОУ (legal entity, 8 digits) — State Statistics Service algorithm.
 * - РНОКПП / ІПН (individual entrepreneur, 10 digits) — weights -1,5,7,9,4,6,10,5,7.
 * - IBAN UA (29 chars) — ISO 13616 mod-97.
 */
final class UaRequisitesValidator
{
    public static function isValidEdrpou(string $code): bool
    {
        if (!preg_match('/^\d{8}$/', $code)) {
            return false;
        }

        $digits = array_map('intval', str_split($code));
        $value = (int) $code;
        $weights = ($value < 30000000 || $value > 60000000)
            ? [1, 2, 3, 4, 5, 6, 7]
            : [7, 1, 2, 3, 4, 5, 6];

        $check = self::weightedMod11($digits, $weights);
        if ($check === 10) {
            $check = self::weightedMod11($digits, array_map(static fn (int $w): int => $w + 2, $weights));
            if ($check === 10) {
                $check = 0;
            }
        }

        return $check === $digits[7];
    }

    public static function isValidRnokpp(string $code): bool
    {
        if (!preg_match('/^\d{10}$/', $code)) {
            return false;
        }

        $digits = array_map('intval', str_split($code));
        $weights = [-1, 5, 7, 9, 4, 6, 10, 5, 7];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $digits[$i] * $w;
        }

        return (($sum % 11) % 10) === $digits[9];
    }

    /** Buyer tax id on an invoice: ЄДРПОУ (company) or РНОКПП (ФОП). */
    public static function isValidBuyerTaxId(string $code): bool
    {
        return self::isValidEdrpou($code) || self::isValidRnokpp($code);
    }

    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function isValidUaIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);
        if (!preg_match('/^UA\d{27}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /** 12-digit ІПН of a VAT payer. Only the format can be checked offline. */
    public static function isValidVatIpn(string $ipn): bool
    {
        return (bool) preg_match('/^\d{12}$/', $ipn);
    }

    /**
     * @param int[] $digits
     * @param int[] $weights
     */
    private static function weightedMod11(array $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $digits[$i] * $w;
        }

        return $sum % 11;
    }
}
