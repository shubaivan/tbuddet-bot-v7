<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Entity\UserOrder;
use App\Invoice\InvoicePublicLink;
use App\Invoice\InvoiceService;
use PHPUnit\Framework\TestCase;

class InvoicePublicLinkTest extends TestCase
{
    private function order(int $id, ?string $number): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, $id);
        if (null !== $number) {
            $o->setInvoiceNumber($number);
        }

        return $o;
    }

    public function testUrlAndValidation(): void
    {
        $links = new InvoicePublicLink('s3cret', 'https://api.artbeton.market/');
        $o = $this->order(42, 'ABM-2026-00007');
        $url = $links->url($o);

        self::assertMatchesRegularExpression('~^https://api\.artbeton\.market/invoice/42/[A-Za-z0-9_-]{32}\.pdf$~', (string) $url);
        $token = basename((string) $url, '.pdf');
        self::assertTrue($links->isValid($o, $token));
        self::assertFalse($links->isValid($o, str_repeat('A', 32)), 'forged token');
        self::assertFalse((new InvoicePublicLink('other', 'x'))->isValid($o, $token), 'other secret');
        self::assertFalse($links->isValid($this->order(43, 'ABM-2026-00007'), $token), 'token bound to order id');
        self::assertFalse($links->isValid($this->order(42, 'ABM-2026-00008'), $token), 'token bound to invoice number');
        self::assertNull($links->url($this->order(44, null)), 'no invoice — no link');
    }

    public function testDeliveryLine(): void
    {
        self::assertSame(
            ['name' => 'Доставка по місту (Черкаси): вул. Гоголя, 7', 'unit' => 'послуга', 'qty' => 1, 'price' => 800, 'sum' => 800],
            InvoiceService::deliveryLine('Доставка по місту (Черкаси): вул. Гоголя, 7', 800),
        );
    }
}
