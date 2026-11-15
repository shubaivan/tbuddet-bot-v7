<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\AmountInWords;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    /** @dataProvider amounts */
    public function testUah(float|int|string $amount, string $expected): void
    {
        self::assertSame($expected, AmountInWords::uah($amount));
    }

    public static function amounts(): iterable
    {
        yield [0, 'Нуль гривень 00 копійок'];
        yield [1, 'Одна гривня 00 копійок'];
        yield [2, 'Дві гривні 00 копійок'];
        yield [11, 'Одинадцять гривень 00 копійок'];
        yield [21.01, 'Двадцять одна гривня 01 копійка'];
        yield [6450, "Шість тисяч чотириста п'ятдесят гривень 00 копійок"];
        yield [1000, 'Одна тисяча гривень 00 копійок'];
        yield [2000, 'Дві тисячі гривень 00 копійок'];
        yield [12345.67, 'Дванадцять тисяч триста сорок п\'ять гривень 67 копійок'];
        yield [1_000_000, 'Один мільйон гривень 00 копійок'];
        yield [2_514_002.5, "Два мільйони п'ятсот чотирнадцять тисяч дві гривні 50 копійок"];
    }
}
