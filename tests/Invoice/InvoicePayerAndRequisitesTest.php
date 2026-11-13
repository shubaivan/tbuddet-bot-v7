<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\InvoicePayer;
use App\Invoice\InvoiceRequisitesMissingException;
use App\Invoice\InvoiceService;
use App\Invoice\SellerRequisites;
use PHPUnit\Framework\TestCase;

class InvoicePayerAndRequisitesTest extends TestCase
{
    private static function validPayer(array $override = []): array
    {
        return array_merge([
            'name' => '  ОСББ   «Сіті Парк» ',
            'tax_id' => '00032129',
            'address' => 'м. Черкаси, вул. Тестова, 1',
            'contact_person' => 'Тестова Т. Т.',
            'phone' => '068 517 00 77',
            'email' => 'Buh@Example.com',
        ], $override);
    }

    public function testValidPayerIsNormalised(): void
    {
        $payer = InvoicePayer::fromArray(self::validPayer());

        self::assertSame([], $payer->validate());
        self::assertSame('ОСББ «Сіті Парк»', $payer->name);
        self::assertSame('380685170077', $payer->phone);
        self::assertSame('buh@example.com', $payer->email);
        self::assertFalse($payer->vatPayer);
        self::assertNull($payer->vatIpn);
        self::assertFalse($payer->isFop());
    }

    public function testFopWithVatAndIban(): void
    {
        $payer = InvoicePayer::fromArray(self::validPayer([
            'tax_id' => '1234567899',
            'vat_payer' => true,
            'vat_ipn' => '1234 5678 9012',
            'iban' => 'ua33 3510 0500 0002 6005 8791 3543 4',
        ]));

        self::assertSame([], $payer->validate());
        self::assertTrue($payer->isFop());
        self::assertSame('123456789012', $payer->vatIpn);
        self::assertSame('UA333510050000026005879135434', $payer->iban);
    }

    public function testEveryRequiredFieldIsReported(): void
    {
        $errors = InvoicePayer::fromArray([
            'tax_id' => '44863369',
            'phone' => '123',
            'email' => 'nope',
            'vat_payer' => 'true',
            'vat_ipn' => '32060729',
            'iban' => 'UA00',
        ])->validate();

        self::assertSame(
            ['name', 'tax_id', 'address', 'contact_person', 'phone', 'email', 'vat_ipn', 'iban'],
            array_keys($errors)
        );
    }

    public function testVatIpnIgnoredWhenNotAVatPayer(): void
    {
        $payer = InvoicePayer::fromArray(self::validPayer(['vat_payer' => false, 'vat_ipn' => 'garbage']));

        self::assertSame([], $payer->validate());
        self::assertNull($payer->toArray()['vat_ipn']);
    }

    public function testEmptyRequisitesBlockInvoices(): void
    {
        $empty = new SellerRequisites('', '', '', '', '', '', '', '', '', '', '', '');

        self::assertFalse($empty->isComplete());
        self::assertCount(11, $empty->problems());

        $this->expectException(InvoiceRequisitesMissingException::class);
        $empty->assertComplete();
    }

    public function testPlaceholdersAndBadValuesAreRejected(): void
    {
        $bad = new SellerRequisites(
            name: '!ChangeMe!',
            edrpou: '44863369',
            iban: 'UA333510050000026005879135434',
            bank: 'Укрсиббанк',
            mfo: '300000',
            vatStatus: 'payer',
            vatIpn: '32060729',
            signatory: 'TODO',
            phone: '+380',
            email: 'x',
            address: 'Черкаси',
            paymentTermDays: '0',
        );
        $text = implode("\n", $bad->problems());

        self::assertStringContainsString('INVOICE_SELLER_NAME', $text);
        self::assertStringContainsString('EDRPOU не проходить', $text);
        self::assertStringContainsString('MFO не збігається', $text);
        self::assertStringContainsString('VAT_IPN', $text);
        self::assertStringContainsString('SIGNATORY', $text);
        self::assertStringContainsString('EMAIL некоректний', $text);
        self::assertStringContainsString('PAYMENT_TERM_DAYS', $text);
    }

    public function testSampleRequisitesAreComplete(): void
    {
        self::assertSame([], SellerRequisites::sample()->problems());
    }

    public function testInvoiceNumberFormat(): void
    {
        self::assertSame('ABM-2026-00042', InvoiceService::formatNumber('ABM', 2026, 42));
    }
}
