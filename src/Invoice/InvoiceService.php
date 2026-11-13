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
            $price = (int) $product->getPrice($language);
            $options = [];
            foreach ($purchaseProduct->getProductProperties() as $prop) {
                $price += (int) ($prop['property_price_impact'] ?? 0);
                if (!empty($prop['property_name']) && !empty($prop['property_value'])) {
                    $options[] = $prop['property_name'] . ': ' . $prop['property_value'];
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
        $order->setInvoiceDueDate($now->setTime(0, 0)->modify(sprintf('+%d days', $this->seller->paymentTermDays())));
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
        $lines = [
            ['name' => 'Обмежувач руху «Півсфера» Ø500 мм (Колір: Сірий)', 'unit' => 'шт.', 'qty' => 3, 'price' => 2150, 'sum' => 6450],
        ];

        return $this->htmlToPdf($this->renderHtml(
            seller: SellerRequisites::sample(),
            number: self::formatNumber('ЗРАЗОК', (int) $issued->format('Y'), 1),
            issuedAt: $issued,
            dueDate: $issued->setTime(0, 0)->modify('+5 days'),
            payer: [
                'name' => 'ОСББ «ЗРАЗОК»',
                'tax_id' => '00032129',
                'address' => '00000, м. Зразкове, вул. Тестова, 1',
                'contact_person' => 'Тестова Т. Т.',
                'phone' => '380000000000',
                'email' => 'payer@example.com',
                'vat_payer' => false,
                'vat_ipn' => null,
                'iban' => 'UA213223130000026007233566001',
            ],
            lines: $lines,
            discount: 0,
            promocode: null,
            total: 6450,
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
        $vat = $seller->isVatPayer() ? round($total * self::VAT_RATE / (100 + self::VAT_RATE), 2) : null;

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
            'vat' => $vat,
            'vatRate' => self::VAT_RATE,
            'totalInWords' => AmountInWords::uah($total),
            'orderId' => $orderId,
            'sample' => $sample,
        ]);
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
