<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\UaRequisitesValidator as V;
use PHPUnit\Framework\TestCase;

class UaRequisitesValidatorTest extends TestCase
{
    /** @dataProvider validEdrpou */
    public function testRealEdrpouCodesPass(string $code): void
    {
        self::assertTrue(V::isValidEdrpou($code));
        self::assertTrue(V::isValidBuyerTaxId($code));
    }

    public static function validEdrpou(): iterable
    {
        yield 'ТОВ «Арт Бетон Маркет» (public offer)' => ['44863368'];
        yield 'ПриватБанк' => ['14360570'];
        yield 'Укрсиббанк (alternate weights, >30M<60M branch not hit)' => ['09807750'];
        yield 'Ощадбанк (leading zeros)' => ['00032129'];
        yield 'Укртелеком (30M..60M weights)' => ['21560766'];
    }

    public function testWrongCheckDigitAndFormatFail(): void
    {
        self::assertFalse(V::isValidEdrpou('44863369'));
        self::assertFalse(V::isValidEdrpou('4486336'));
        self::assertFalse(V::isValidEdrpou('4486336a'));
    }

    public function testRnokpp(): void
    {
        // 1,2,…,9 weighted by -1,5,7,9,4,6,10,5,7 = 295; 295 % 11 % 10 = 9.
        self::assertTrue(V::isValidRnokpp('1234567899'));
        self::assertFalse(V::isValidRnokpp('1234567890'));
        self::assertTrue(V::isValidBuyerTaxId('1234567899'));
        self::assertFalse(V::isValidBuyerTaxId('123456789'));
    }

    public function testIban(): void
    {
        self::assertTrue(V::isValidUaIban('UA333510050000026005879135434'));
        self::assertTrue(V::isValidUaIban('ua33 3510 0500 0002 6005 8791 3543 4'));
        self::assertFalse(V::isValidUaIban('UA333510050000026005879135435'));
        self::assertFalse(V::isValidUaIban('PL61109010140000071219812874'));
    }

    public function testVatIpnIsTwelveDigits(): void
    {
        self::assertTrue(V::isValidVatIpn('448633623020'));
        self::assertFalse(V::isValidVatIpn('32060729'));
    }
}
