<?php

declare(strict_types=1);

namespace App\Tests\Liqpay;

use App\Liqpay\LiqPayLogSummary;
use PHPUnit\Framework\TestCase;

class LiqPayLogSummaryTest extends TestCase
{
    public function testKeepsOnlyTheFieldsNeededToFollowTheOrder(): void
    {
        $summary = LiqPayLogSummary::of([
            'action' => 'pay',
            'status' => 'try_again',
            'order_id' => '42-1700000000',
            'payment_id' => 123,
            'amount' => 8250,
            'currency' => 'UAH',
            'err_code' => 'limit',
            'sender_card_mask2' => '424242*42',
            'sender_phone' => '380630000000',
            'sender_first_name' => 'Іван',
            'ip' => '1.2.3.4',
            'description' => 'Замовлення',
        ]);

        self::assertSame([
            'action' => 'pay',
            'status' => 'try_again',
            'order_id' => '42-1700000000',
            'payment_id' => 123,
            'amount' => 8250,
            'currency' => 'UAH',
            'err_code' => 'limit',
        ], $summary);
        self::assertSame([], LiqPayLogSummary::of(null));
    }
}
