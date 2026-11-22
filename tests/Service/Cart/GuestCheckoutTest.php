<?php

declare(strict_types=1);

namespace App\Tests\Service\Cart;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Controller\API\Request\Guest\GuestCheckoutRequest;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Product;
use App\Entity\Promocode;
use App\Entity\PurchaseProduct;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoiceService;
use App\Repository\ProductRepository;
use App\Repository\PurchaseProductRepository;
use App\Service\Analytics\ActivityService;
use App\Service\Analytics\TelegramNotifier;
use App\Service\Campaign\CampaignTracker;
use App\Service\Cart\CartCheckout;
use App\Service\Cart\CartTotalCalculator;
use App\Service\Cart\CheckoutRejected;
use App\Service\Cart\GuestCartLines;
use App\Service\Cart\GuestContact;
use App\Service\Delivery\CityDelivery;
use App\Service\Payment\PaymentResultUrl;
use App\Service\Promocode\PromocodeService;
use App\Service\Promocode\PromocodeValidationResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Guest cart checkout: the browser sends the cart, the server prices it from the
 * catalogue and places the order through the same CartCheckout as the logged-in cart.
 */
class GuestCheckoutTest extends TestCase
{
    /** 8 250 ₴ / 202 $, colour «Індивідуальний» +50 ₴ / «Individual» +20 $ (as InvoiceAlwaysInHryvniaTest). */
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

    private function lines(?Product $product = null): GuestCartLines
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('find')->willReturnCallback(fn (int $id) => 178 === $id ? ($product ?? $this->cube()) : null);

        return new GuestCartLines($repo);
    }

    public function testTheCartIsPricedFromTheCatalogueNotFromTheBrowser(): void
    {
        // Added on /en (English option, a forged surcharge of 0), paid on /uk.
        $lines = $this->lines()->build([
            ['product_id' => 178, 'quantity' => 2, 'product_properties' => [
                ['property_name' => 'Color', 'property_value' => 'Natural', 'property_price_impact' => 0],
                // Same option name twice: the last choice wins, never both surcharges.
                ['property_name' => 'Color', 'property_value' => 'Individual', 'property_price_impact' => 0],
            ]],
        ], UserLanguageEnum::UA);

        self::assertCount(1, $lines);
        self::assertNull($lines[0]->getId(), 'not saved by the builder');
        self::assertNull($lines[0]->getShoppingCart(), 'a guest line has no shopping cart');
        self::assertSame([['property_name' => 'Колір', 'property_value' => 'Індивідуальний', 'property_price_impact' => 50]], $lines[0]->getProductProperties());

        $calculator = new CartTotalCalculator($this->createMock(PurchaseProductRepository::class));
        self::assertSame(16600, $calculator->calculateLines($lines, UserLanguageEnum::UA));
        self::assertSame(8300, $calculator->unitPrice($lines[0], UserLanguageEnum::UA));
    }

    public function testUnknownProductOrOptionIsACartOutdated422(): void
    {
        foreach ([
            [['product_id' => 999, 'quantity' => 1]],
            [['product_id' => 178, 'quantity' => 1, 'product_properties' => [['property_name' => 'Колір', 'property_value' => 'Рожевий']]]],
        ] as $items) {
            try {
                $this->lines()->build($items, UserLanguageEnum::UA);
                self::fail('must be refused');
            } catch (CheckoutRejected $e) {
                self::assertSame(422, $e->status);
                self::assertSame('cart_outdated', $e->payload['error']);
                self::assertNotEmpty($e->payload['error_message']);
            }
        }
    }

    public function testAProductWithoutAPriceCannotBeBought(): void
    {
        $free = $this->cube();
        (new \ReflectionProperty(Product::class, 'price'))->setValue($free, ['ua' => '0', 'en' => '0']);
        $noUa = $this->cube();
        // Priced in dollars only: an invoice (always hryvnia) would charge 1 ₴ for it.
        (new \ReflectionProperty(Product::class, 'price'))->setValue($noUa, ['en' => '202']);
        try {
            $this->lines($noUa)->build([['product_id' => 178, 'quantity' => 1]], UserLanguageEnum::EN);
            self::fail('no hryvnia price must be refused');
        } catch (CheckoutRejected $e) {
            self::assertSame('cart_outdated', $e->payload['error']);
        }

        $this->expectException(CheckoutRejected::class);
        $this->lines($free)->build([['product_id' => 178, 'quantity' => 1]], UserLanguageEnum::UA);
    }

    public function testRequestNormalisesPhoneNameEmail(): void
    {
        $r = (new GuestCheckoutRequest())->setPhone('+380 67 123-45-67')->setName("  Олена \n Петрівна ")->setEmail('  ');
        self::assertSame('380671234567', $r->getPhone());
        self::assertSame('Олена Петрівна', $r->getName());
        self::assertNull($r->getEmail());
        self::assertSame('a@b.ua', $r->setEmail(' A@B.ua ')->getEmail());
    }

    /**
     * @param list<string> $sent filled with the manager messages
     * @param list<object> $persisted filled with what the checkout saves
     */
    private function checkout(array &$sent, array &$persisted, PromocodeService $promocodes, InvoiceCheckout $invoiceCheckout, InvoiceService $invoiceService): CartCheckout
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });
        $em->method('flush')->willReturnCallback(function () use (&$persisted): void {
            foreach ($persisted as $o) {
                if ($o instanceof UserOrder && null === $o->getId()) {
                    (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, 501);
                }
            }
        });

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true)['text'] ?? '';

            return new MockResponse('{"ok":true}');
        });
        $activity = new ActivityService(new TelegramNotifier($http, 'token', '-100', new NullLogger()), new ArrayAdapter(), new NullLogger());

        return new CartCheckout(
            new NullLogger(),
            'pub',
            'priv',
            'https://api.example.test/liq/pay',
            new PaymentResultUrl('https://shop.example.test', 'secret'),
            $em,
            new CartTotalCalculator($this->createMock(PurchaseProductRepository::class)),
            $promocodes,
            $activity,
            new CityDelivery(300),
            $invoiceService,
            $invoiceCheckout,
            $this->createMock(CampaignTracker::class),
        );
    }

    public function testGuestInvoiceOrderWithCityDeliveryAndPromocode(): void
    {
        $code = (new Promocode())->setCode('QR15')->setDiscountType(DiscountTypeEnum::PERCENT)->setValue(10)->setCurrency(CurrencyEnum::UAH);
        $promocodes = $this->createMock(PromocodeService::class);
        // The guest is identified by the phone for the per-buyer limit.
        $promocodes->expects(self::once())->method('validate')
            ->with('QR15', 16600, CurrencyEnum::UAH, null, null, '380671234567')
            ->willReturn(PromocodeValidationResult::success($code, 1660));
        $promocodes->expects(self::once())->method('redeem')
            ->with($code, self::isInstanceOf(UserOrder::class), null, null, '380671234567', 1660)
            ->willReturn(true);

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('isAvailable')->willReturn(true);
        $realLines = (new \ReflectionClass(InvoiceService::class))->newInstanceWithoutConstructor();
        $invoiceService->method('buildLines')->willReturnCallback(fn (iterable $pp, UserLanguageEnum $l) => $realLines->buildLines($pp, $l));

        $invoiceCheckout = $this->createMock(InvoiceCheckout::class);
        $invoiceCheckout->expects(self::once())->method('prepare')
            ->willReturnCallback(function (UserOrder $order, array $payer, array $lines, ?User $remember): void {
                self::assertNull($remember, 'a guest has no account to remember the payer on');
                // The real safety net: order total = lines − discount.
                InvoiceCheckout::assertTotalMatchesLines($order, $lines);
                self::assertSame(300, end($lines)['sum'], 'city delivery is an invoice line');
                $order->setPaymentMethod('invoice');
            });
        $invoiceCheckout->method('complete')->willReturn(['number' => 'ABM-2026-0001', 'public_url' => 'https://x/invoice/1']);

        $request = (new GuestCheckoutRequest())
            ->setItems([['product_id' => 178, 'quantity' => 2, 'product_properties' => [['property_name' => 'Color', 'property_value' => 'Individual']]]])
            ->setPhone('380671234567')->setName('Олена <b>')->setEmail('olena@example.test')
            ->setDeliveryMethod('city_courier')->setDeliveryAddress('вул. Хрещатик, 1, кв. 2')
            ->setPromocode('QR15')
            ->setPaymentMethod('invoice')
            ->setPayer(['name' => 'ТОВ Тест']);

        $sent = [];
        $persisted = [];
        // The cart was filled on /en (English option); city delivery exists on /uk only.
        $lines = $this->lines()->build($request->getItems(), UserLanguageEnum::UA);
        $result = $this->checkout($sent, $persisted, $promocodes, $invoiceCheckout, $invoiceService)
            ->place($request, $lines, UserLanguageEnum::UA, null, new GuestContact('380671234567', 'Олена <b>', 'olena@example.test'));

        $order = $result['order'];
        self::assertSame('ABM-2026-0001', $result['invoice']['number']);
        self::assertNull($order->getClientUserId());
        self::assertSame('380671234567', $order->getPhone());
        self::assertSame('Олена <b>', $order->getContactName());
        self::assertSame('olena@example.test', $order->getContactEmail());
        self::assertSame(16600, $order->getSubtotalAmount());
        self::assertSame(1660, $order->getDiscountAmount());
        self::assertSame(16600 - 1660 + 300, (int) $order->getTotalAmount());
        self::assertSame(300, $order->getDeliveryAmount());
        self::assertContains($lines[0], $persisted, 'the guest line is saved with the order');
        self::assertSame($order, $lines[0]->getUserOrder());

        self::assertCount(1, $sent, 'one «new order» message to the managers');
        self::assertStringContainsString('гість', $sent[0]);
        self::assertStringContainsString('Олена &lt;b&gt;', $sent[0], 'the typed name is escaped in the Telegram HTML');
        self::assertStringContainsString('380671234567', $sent[0]);
    }

    public function testRejectionsHappenBeforeAnythingIsSaved(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('isAvailable')->willReturn(false);
        $promocodes = $this->createMock(PromocodeService::class);
        $promocodes->method('validate')->willReturn(PromocodeValidationResult::failure(\App\Entity\Enum\PromocodeErrorEnum::NOT_FOUND));
        $guest = new GuestContact('380671234567', 'Олена');
        $items = [['product_id' => 178, 'quantity' => 1]];

        $cases = [
            ['invoice_unavailable', (new GuestCheckoutRequest())->setPaymentMethod('invoice'), 422],
            ['delivery_address_invalid', (new GuestCheckoutRequest())->setDeliveryMethod('city_courier')->setDeliveryAddress('x'), 422],
            // Nova Poshta without the department: no order without an address.
            ['delivery_address_invalid', (new GuestCheckoutRequest())->setDeliveryMethod('nova_poshta')->setDeliveryCity('Київ'), 422],
            ['promocode_invalid', (new GuestCheckoutRequest())->setPromocode('NOPE'), 400],
        ];
        foreach ($cases as [$error, $request, $status]) {
            $sent = [];
            $persisted = [];
            try {
                $this->checkout($sent, $persisted, $promocodes, $this->createMock(InvoiceCheckout::class), $invoiceService)
                    ->place($request, $this->lines()->build($items, UserLanguageEnum::UA), UserLanguageEnum::UA, null, $guest);
                self::fail($error.' must be refused');
            } catch (CheckoutRejected $e) {
                self::assertSame($error, $e->payload['error']);
                self::assertSame($status, $e->status);
                self::assertSame([], $persisted, $error.': nothing saved');
                self::assertSame([], $sent, $error.': nobody notified');
            }
        }
    }

    /** The logged-in cart goes through the same service, unchanged: account, remembered payer, account promocode identity. */
    public function testLoggedInCartStillUsesTheAccount(): void
    {
        $user = (new User())->setFirstName('Іван')->setLastName('Тест')->setPhone('380501112233');
        $promocodes = $this->createMock(PromocodeService::class);
        $promocodes->expects(self::never())->method('validate');

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('isAvailable')->willReturn(true);
        $realLines = (new \ReflectionClass(InvoiceService::class))->newInstanceWithoutConstructor();
        $invoiceService->method('buildLines')->willReturnCallback(fn (iterable $pp, UserLanguageEnum $l) => $realLines->buildLines($pp, $l));
        $invoiceCheckout = $this->createMock(InvoiceCheckout::class);
        $invoiceCheckout->expects(self::once())->method('prepare')
            ->willReturnCallback(function (UserOrder $order, array $payer, array $lines, ?User $remember) use ($user): void {
                self::assertSame($user, $remember);
                InvoiceCheckout::assertTotalMatchesLines($order, $lines);
                $order->setPaymentMethod('invoice');
            });
        $invoiceCheckout->method('complete')->willReturn(['number' => 'ABM-2026-0002']);

        $saved = (new PurchaseProduct())->setProduct($this->cube())->setQuantity(1)->setProductProperties([]);
        (new \ReflectionProperty(PurchaseProduct::class, 'id'))->setValue($saved, 7);

        $request = (new \App\Controller\API\Request\Purchase\CheckoutRequest())
            ->setPurchaseProductIds([7])->setDeliveryCity('Київ')->setDeliveryDepartment('Відділення №1')
            ->setPaymentMethod('invoice')->setRememberPayer(true);

        $sent = [];
        $persisted = [];
        $result = $this->checkout($sent, $persisted, $promocodes, $invoiceCheckout, $invoiceService)
            ->place($request, [$saved], UserLanguageEnum::UA, $user);

        self::assertSame($user, $result['order']->getClientUserId());
        self::assertNull($result['order']->getContactName());
        self::assertSame('nova_poshta', $result['order']->getDeliveryMethod());
        self::assertSame(8250, (int) $result['order']->getTotalAmount());
        self::assertNotContains($saved, $persisted, 'a saved cart row is not persisted again');
        self::assertStringContainsString('Іван Тест, 380501112233', $sent[0]);
        self::assertStringNotContainsString('гість', $sent[0]);
    }

    public function testEitherTheAccountOrTheGuest(): void
    {
        $this->expectException(\LogicException::class);
        $sent = [];
        $persisted = [];
        $this->checkout($sent, $persisted, $this->createMock(PromocodeService::class), $this->createMock(InvoiceCheckout::class), $this->createMock(InvoiceService::class))
            ->place(new GuestCheckoutRequest(), [], UserLanguageEnum::UA, null, null);
    }
}
