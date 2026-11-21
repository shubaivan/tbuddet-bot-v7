<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\PurchaseProduct;
use App\Entity\UserOrder;
use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * «Рахунок на оплату» for legal entities: numbering, goods-line snapshot, PDF.
 * Never renders a real invoice while SellerRequisites are incomplete.
 */
class InvoiceService
{
    public const VAT_RATE = 20;
    private const TZ = 'Europe/Kyiv';

    public function __construct(
        private readonly SellerRequisites $seller,
        private readonly Connection $connection,
        private readonly Environment $twig,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->seller->isComplete();
    }

    /** @return list<string> */
    public function requisitesProblems(): array
    {
        return $this->seller->problems();
    }

    /**
     * Snapshot of what is printed on the invoice. Prices come from the same
     * formula as CartTotalCalculator (base price + property price impacts).
     *
     * @param iterable<PurchaseProduct> $purchaseProducts
     *
     * @return list<array{name: string, unit: string, qty: int, price: int, sum: int}>
     */
    public function buildLines(iterable $purchaseProducts, UserLanguageEnum $language = UserLanguageEnum::UA): array
    {
        $lines = [];
        foreach ($purchaseProducts as $purchaseProduct) {
            $product = $purchaseProduct->getProduct();
            // Option surcharges in the invoice language too (an option picked on /en is
            // priced as its hryvnia twin) — the same number as CartTotalCalculator for invoices.
            $price = $product->unitPriceIn($purchaseProduct->getProductProperties(), $language);
            $options = [];
            foreach ($purchaseProduct->getProductProperties() as $prop) {
                if (!empty($prop['property_name']) && !empty($prop['property_value'])) {
                    $twin = $product->optionIn((string) $prop['property_name'], (string) $prop['property_value'], $language);
                    $options[] = ($twin['property_name'] ?? $prop['property_name']) . ': ' . ($twin['property_value'] ?? $prop['property_value']);
                }
            }

            $name = (string) $product->getProductName($language);
            if ($options !== []) {
                $name .= ' (' . implode(', ', $options) . ')';
            }

            $qty = (int) $purchaseProduct->getQuantity();
            $lines[] = ['name' => $name, 'unit' => 'шт.', 'qty' => $qty, 'price' => $price, 'sum' => $price * $qty];
        }

        return $lines;
    }

    /** Рядок доставки в рахунку (послуга, 1 × сума). */
    public static function deliveryLine(string $name, int $amount): array
    {
        return ['name' => $name, 'unit' => 'послуга', 'qty' => 1, 'price' => $amount, 'sum' => $amount];
    }

    /**
     * Assigns the number and dates. Call once, right after the order is persisted.
     *
     * @throws InvoiceRequisitesMissingException
     */
    public function issue(UserOrder $order, ?\DateTimeImmutable $now = null): void
    {
        $this->seller->assertComplete();
        if ($order->getInvoiceNumber() !== null) {
            return;
        }

        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(self::TZ));
        $seq = (int) $this->connection->fetchOne("SELECT nextval('invoice_number_seq')");

        $order->setInvoiceNumber(self::formatNumber($this->seller->numberPrefix(), (int) $now->format('Y'), $seq));
        $order->setInvoiceIssuedAt($now);
        $order->setInvoiceDueDate(self::dueDate($now, $this->seller->paymentTermDays()));
    }

    /**
     * Kyiv calendar date + N bank days (Mon–Fri), as a plain date at midnight in the
     * default timezone — the same value Doctrine's date_immutable gives on reload, so
     * Twig's |date never shifts it a day back (a Kyiv-midnight object is 22:00 UTC of
     * the previous day).
     */
    public static function dueDate(\DateTimeImmutable $issuedAt, int $bankDays): \DateTimeImmutable
    {
        $kyiv = $issuedAt->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0)->modify(sprintf('+%d weekdays', $bankDays));

        return new \DateTimeImmutable($kyiv->format('Y-m-d'));
    }

    public static function formatNumber(string $prefix, int $year, int $seq): string
    {
        return sprintf('%s-%d-%05d', $prefix, $year, $seq);
    }

    /** @throws InvoiceRequisitesMissingException */
    public function renderPdf(UserOrder $order): string
    {
        $this->seller->assertComplete();

        return $this->htmlToPdf($this->renderHtml(
            seller: $this->seller,
            number: (string) $order->getInvoiceNumber(),
            issuedAt: $order->getInvoiceIssuedAt() ?? new \DateTimeImmutable(),
            dueDate: $order->getInvoiceDueDate(),
            payer: $order->getInvoicePayer(),
            lines: $order->getInvoiceLines(),
            discount: $order->getDiscountAmount(),
            promocode: $order->getPromocodeCodeUsed(),
            total: (int) $order->getTotalAmount(),
            orderId: $order->getId(),
            sample: false,
        ));
    }

    /** Dummy data, watermarked «ЗРАЗОК» — for previews only, never sent to a customer. */
    public function renderSamplePdf(): string
    {
        $issued = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        // Same shape as a real order (ABM-2026-00001): goods + city delivery,
        // a 15% promocode on the goods, VAT split of the payable amount.
        $lines = [
            ['name' => 'Обмежувач руху «Півсфера» Ø500 мм (Колір: Натуральний)', 'unit' => 'шт.', 'qty' => 3, 'price' => 580, 'sum' => 1740],
            self::deliveryLine('Доставка по місту (Черкаси): м. Черкаси, вул. Зразкова, 1', 800),
        ];

        return $this->htmlToPdf($this->renderHtml(
            seller: SellerRequisites::sample(),
            number: self::formatNumber('ЗРАЗОК', (int) $issued->format('Y'), 1),
            issuedAt: $issued,
            dueDate: self::dueDate($issued, 5),
            payer: [
                'name' => 'ОСББ «ЗРАЗОК»',
                'tax_id' => '00032129',
                'address' => '00000, м. Зразкове, вул. Тестова, 1',
                'contact_person' => 'Тестова Т. Т.',
                'phone' => '380000000000',
                'email' => '',
                'vat_payer' => false,
                'vat_ipn' => null,
                'iban' => 'UA213223130000026007233566001',
            ],
            lines: $lines,
            discount: 261,
            promocode: 'ARTBETON15',
            total: 2279,
            orderId: 0,
            sample: true,
        ));
    }

    private function renderHtml(
        SellerRequisites $seller,
        string $number,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $dueDate,
        array $payer,
        array $lines,
        int $discount,
        ?string $promocode,
        int $total,
        ?int $orderId,
        bool $sample,
    ): string {
        $subtotal = array_sum(array_column($lines, 'sum'));
        $vatSplit = $seller->isVatPayer() ? self::vatSplit($total) : null;

        return $this->twig->render('invoice/invoice.pdf.html.twig', [
            'seller' => $seller,
            'number' => $number,
            'issuedAt' => $issuedAt->setTimezone(new \DateTimeZone(self::TZ)),
            'dueDate' => $dueDate,
            'payer' => $payer,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'promocode' => $promocode,
            'total' => $total,
            'vat' => $vatSplit['vat'] ?? null,
            'net' => $vatSplit['net'] ?? null,
            'vatRate' => self::VAT_RATE,
            'logo' => self::logoDataUri(),
            'totalInWords' => AmountInWords::uah($total),
            'orderId' => $orderId,
            'sample' => $sample,
        ]);
    }

    /**
     * Prices on the site include VAT. The invoice shows the payable amount (after
     * the discount) split the way accountants expect: net + VAT = gross.
     *
     * @return array{net: float, vat: float, gross: float}
     */
    public static function vatSplit(int|float $gross): array
    {
        $gross = round((float) $gross, 2);
        $vat = round($gross * self::VAT_RATE / (100 + self::VAT_RATE), 2);

        return ['net' => round($gross - $vat, 2), 'vat' => $vat, 'gross' => $gross];
    }

    /** Site logo (public/logo-new.svg rendered to PNG) inlined, so dompdf needs no remote fetch. */
    private static function logoDataUri(): ?string
    {
        $path = \dirname(__DIR__, 2).'/templates/invoice/abm-logo.png';
        $png = is_file($path) ? file_get_contents($path) : false;

        return false === $png ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    private function htmlToPdf(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
