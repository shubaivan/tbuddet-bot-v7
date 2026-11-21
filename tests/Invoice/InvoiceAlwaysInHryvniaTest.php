<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Entity\Product;
use App\Entity\PurchaseProduct;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoiceService;
use App\Invoice\InvoiceTotalMismatchException;
use App\Repository\PurchaseProductRepository;
use App\Service\Cart\CartTotalCalculator;
use PHPUnit\Framework\TestCase;

/**
 * An invoice is always in hryvnia. On /en the cart and both quick buys used to put
 * the USD number into the order total next to hryvnia invoice lines, and
 * «Оплатити онлайн» then charged that USD number as UAH (8 250 ₴ cube → 202 ₴).
 */
class InvoiceAlwaysInHryvniaTest extends TestCase
{
    /** Product 178 as on the dev stand: 8 250 ₴ / 202 $, colour «Індивідуальний» +50 ₴ / «Individual» +20 $. */
    private function cube(): Product
    {
        $p = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($p, 178);
        (new \ReflectionProperty(Product::class, 'product_name'))->setValue($p, ['ua' => 'Вазон «Куб»', 'en' => 'Planter «Cube»']);
        (new \ReflectionProperty(Product::class, 'price'))->setValue($p, ['ua' => '8250', 'en' => '202']);
        $p->setProductProperties([
            ['ua' => ['property_name' => 'Колір', 'property_value' => 'Натуральний', 'property_price_impact' => '0'],
             'en' => ['property_name' => 'Color', 'property_value' => 'Natural', 'property_price_impact' => '0']],
            ['ua' => ['property_name' => 'Колір', 'property_value' => 'Індивідуальний', 'property_price_impact' => '50'],
             'en' => ['property_name' => 'Color', 'property_value' => 'Individual', 'property_price_impact' => '20']],
        ]);

        return $p;
    }

    /** A cart line added on /en: options and their surcharge stored in English / USD. */
    private function enCartLine(int $qty = 2): PurchaseProduct
    {
        $line = (new PurchaseProduct())->setProduct($this->cube())->setQuantity($qty)->setProductProperties([
            ['property_name' => 'Color', 'property_value' => 'Individual', 'property_price_impact' => 20],
        ]);
        (new \ReflectionProperty(PurchaseProduct::class, 'id'))->setValue($line, 7);

        return $line;
    }

    private function enOption(): ProductProperties
    {
        return (new ProductProperties())->setPropertyName('Color')->setPropertyValue('Individual')->setPropertyPriceImpact(20);
    }

    public function testUnitPriceInHryvniaTakesTheUkrainianTwinOfAnEnglishOption(): void
    {
        $cube = $this->cube();
        self::assertSame(8300, $cube->unitPriceIn([$this->enOption()], UserLanguageEnum::UA));
        self::assertSame(222, $cube->unitPriceIn([$this->enOption()], UserLanguageEnum::EN));
        self::assertSame(8300, $cube->unitPriceIn([['property_name' => 'Колір', 'property_value' => 'Індивідуальний', 'property_price_impact' => 50]], UserLanguageEnum::UA));
        // Unknown option: keeps the stored surcharge.
        self::assertSame(8255, $cube->unitPriceIn([['property_name' => 'X', 'property_value' => 'Y', 'property_price_impact' => 5]], UserLanguageEnum::UA));
        self::assertSame('Індивідуальний', $cube->optionIn('Color', 'Individual', UserLanguageEnum::UA)['property_value'] ?? null);
        // Order description / LiqPay text of an invoice: the Ukrainian option with its hryvnia surcharge.
        self::assertSame(['Колір', 'Індивідуальний', 50], $cube->optionTextIn('Color', 'Individual', 20, UserLanguageEnum::UA));
        self::assertSame(['Color', 'Individual', 20], $cube->optionTextIn('Color', 'Individual', 20, UserLanguageEnum::EN));
        self::assertSame(['X', 'Y', 5], $cube->optionTextIn('X', 'Y', 5, UserLanguageEnum::UA));
    }

    public function testEnglishCartInvoiceTotalIsTheHryvniaSumOfTheLines(): void
    {
        $line = $this->enCartLine(2);
        $repo = $this->createMock(PurchaseProductRepository::class);
        $repo->method('find')->willReturn($line);

        // Invoice checkout on /en: priced in UAH with catalogue option prices.
        $subtotal = (new CartTotalCalculator($repo))->calculate([7], UserLanguageEnum::UA, true);
        $lines = (new \ReflectionClass(InvoiceService::class))->newInstanceWithoutConstructor()->buildLines([$line], UserLanguageEnum::UA);

        self::assertSame(16600, $subtotal);
        self::assertSame(16600, array_sum(array_column($lines, 'sum')));
        self::assertSame('Вазон «Куб» (Колір: Індивідуальний)', $lines[0]['name']);
        // Card checkout on /en is unchanged: USD with the stored surcharge.
        self::assertSame(444, (new CartTotalCalculator($repo))->calculate([7], UserLanguageEnum::EN));
    }

    public function testTheSafetyNetRefusesAUsdTotalNextToHryvniaLines(): void
    {
        $lines = [['name' => 'Вазон', 'unit' => 'шт.', 'qty' => 1, 'price' => 8250, 'sum' => 8250], InvoiceService::deliveryLine('Доставка', 300)];

        $ok = (new UserOrder())->setTotalAmount(8250 + 300 - 825);
        $ok->setDiscountAmount(825);
        InvoiceCheckout::assertTotalMatchesLines($ok, $lines);

        $usd = (new UserOrder())->setTotalAmount(202);
        try {
            InvoiceCheckout::assertTotalMatchesLines($usd, $lines);
            self::fail('a USD total next to hryvnia lines must be refused');
        } catch (InvoiceTotalMismatchException $e) {
            self::assertSame(422, $e->getStatusCode(), 'a plain 422 for the buyer, not a 500');
            self::assertStringContainsString('order total 202', $e->details);
        }
    }
}
