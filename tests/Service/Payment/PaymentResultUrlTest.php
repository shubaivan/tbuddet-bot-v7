<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Service\Payment\PaymentResultUrl;
use PHPUnit\Framework\TestCase;

final class PaymentResultUrlTest extends TestCase
{
    public function testBuildPointsToTheLocalisedStatusPageWithAKey(): void
    {
        $service = new PaymentResultUrl('https://artbeton.market/', 's3cret');

        $url = $service->build('uk', 42);

        self::assertStringStartsWith('https://artbeton.market/uk/payment-success?order=42&k=', $url);
        self::assertStringContainsString('k='.$service->key(42), $url);
        self::assertStringStartsWith('https://artbeton.market/en/', $service->build('en', 42));
        // Internal 'ua' (or anything else) is never used as a URL slug.
        self::assertStringStartsWith('https://artbeton.market/uk/', $service->build('ua', 42));
    }

    public function testKeyIsBoundToTheOrderAndTheSecret(): void
    {
        $service = new PaymentResultUrl('https://artbeton.market', 's3cret');
        $other = new PaymentResultUrl('https://artbeton.market', 'other');

        self::assertTrue($service->isValid(42, $service->key(42)));
        self::assertFalse($service->isValid(43, $service->key(42)));
        self::assertFalse($service->isValid(42, $other->key(42)));
        self::assertFalse($service->isValid(42, null));
        self::assertFalse($service->isValid(42, ''));
    }
}
