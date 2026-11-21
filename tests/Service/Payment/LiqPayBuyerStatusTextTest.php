<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Service\Payment\LiqPayBuyerStatusText;
use PHPUnit\Framework\TestCase;

class LiqPayBuyerStatusTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function statuses(): iterable
    {
        yield 'declined card' => ['try_again', 'Банк відхилив оплату за замовлення #7'];
        yield 'failure' => ['failure', 'Оплата за замовлення #7 не пройшла'];
        yield 'error' => ['error', 'Оплата за замовлення #7 не пройшла'];
        yield 'refund' => ['reversed', 'Оплату за замовлення #7 повернено'];
        yield 'processing' => ['processing', 'Платіж за замовлення #7 обробляється'];
        yield 'wait_secure' => ['wait_secure', 'обробляється банком'];
        yield '3ds' => ['3ds_verify', 'чекає на ваше підтвердження'];
        yield 'otp' => ['otp_verify', 'чекає на ваше підтвердження'];
        yield 'invoice_wait' => ['invoice_wait', 'очікує на оплату'];
        yield 'sandbox' => ['sandbox', 'Тестову оплату'];
        yield 'unknown' => ['something_new', 'Статус оплати за замовлення #7 оновлено'];
    }

    /** @dataProvider statuses */
    public function testHumanText(string $status, string $expected): void
    {
        $text = LiqPayBuyerStatusText::forBuyer($status, 7);

        self::assertStringContainsString($expected, $text);
        self::assertStringNotContainsString($status, $text, 'the raw LiqPay code is not shown');
    }
}
