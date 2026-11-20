<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\PaymentMethodEnum;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Invoice\InvoiceCheckout;
use App\Invoice\InvoicePublicLink;
use App\Service\Payment\OrderOnlinePayment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class InvoiceResendAndPayLaterTest extends TestCase
{
    private function invoiceOrder(int $id = 42, int $total = 2279): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, $id);
        $o->setPaymentMethod(PaymentMethodEnum::INVOICE->value);
        $o->setOrderStatus(OrderStatusEnum::AWAITING_INVOICE_PAYMENT->value);
        $o->setInvoiceNumber('ABM-2026-00007');
        $o->setTotalAmount($total);
        $o->setSubtotalAmount(2540);
        $o->setDiscountAmount(261);
        $o->setDescription('Ваше замовлення: Півсфера: в кількості: 2 одиниць');

        return $o;
    }

    public function testPayUrlSharesTheInvoiceToken(): void
    {
        $links = new InvoicePublicLink('s3cret', 'https://api.artbeton.market/');
        $o = $this->invoiceOrder();
        $pay = (string) $links->payUrl($o);

        self::assertMatchesRegularExpression('~^https://api\.artbeton\.market/invoice/42/([A-Za-z0-9_-]{32})/pay$~', $pay);
        self::assertTrue($links->isValid($o, explode('/', $pay)[5]));
        self::assertSame(basename((string) $links->url($o), '.pdf'), explode('/', $pay)[5]);
    }

    public function testCanPay(): void
    {
        $o = $this->invoiceOrder();
        self::assertTrue(OrderOnlinePayment::canPay($o), 'unpaid invoice order');

        $paidByBank = $this->invoiceOrder();
        $paidByBank->setPaidAt(new \DateTimeImmutable());
        self::assertFalse(OrderOnlinePayment::canPay($paidByBank), 'manager confirmed the transfer');

        $paidByCard = $this->invoiceOrder();
        $paidByCard->setLiqPayStatus('success');
        self::assertFalse(OrderOnlinePayment::canPay($paidByCard), 'already paid online');

        $cancelled = $this->invoiceOrder();
        $cancelled->setOrderStatus(OrderStatusEnum::CANCELLED->value);
        self::assertFalse(OrderOnlinePayment::canPay($cancelled), 'cancelled');

        $failedCard = new UserOrder();
        $failedCard->setTotalAmount(500);
        $failedCard->setLiqPayStatus('failure');
        self::assertTrue(OrderOnlinePayment::canPay($failedCard), 'declined card order can be retried');
    }

    public function testCurrency(): void
    {
        self::assertSame('UAH', OrderOnlinePayment::currency($this->invoiceOrder(), 'en'), 'invoices are always UAH');

        $card = new UserOrder();
        $card->setLiqPayResponse(json_encode(['result' => 'ok', 'currency' => 'USD']));
        self::assertSame('USD', OrderOnlinePayment::currency($card, 'uk'), 'keeps the currency the order was placed in');

        self::assertSame('UAH', OrderOnlinePayment::currency(new UserOrder(), 'uk'));
        self::assertSame('USD', OrderOnlinePayment::currency(new UserOrder(), 'en'));
    }

    public function testCheckoutLinkReusesTheOrderAndItsDiscountedTotal(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $svc = new OrderOnlinePayment(new NullLogger(), $em, 'pub', 'priv', 'https://api.example/liq/pay', 'https://shop.example/');

        $o = $this->invoiceOrder();
        $link = $svc->checkoutLink($o, 'uk');

        self::assertStringStartsWith('https://www.liqpay.ua/api/3/checkout?data=', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $data = json_decode(base64_decode((string) $q['data']), true);
        self::assertSame(base64_encode(sha1('priv'.$q['data'].'priv', true)), $q['signature']);

        self::assertSame('pay', $data['action']);
        self::assertEquals(2279, $data['amount'], 'stored total (promo discount already applied)');
        self::assertSame('UAH', $data['currency']);
        self::assertMatchesRegularExpression('/^42-\d+$/', $data['order_id']);
        self::assertSame(42, (int) $data['order_id'], 'callback maps (int) order_id back to the same order');
        self::assertSame($data['order_id'], $o->getLiqPayOrderId());
        self::assertSame('https://shop.example/uk/payment-success?order=42', $data['result_url']);
        self::assertStringStartsWith('Оплата згідно рахунку № ABM-2026-00007, замовлення #42', $data['description']);
    }

    public function testHasChannel(): void
    {
        $o = $this->invoiceOrder();
        self::assertFalse(InvoiceCheckout::hasChannel($o), 'no email, no Telegram');

        $tgOnly = $this->invoiceOrder();
        $u = (new User())->setTelegramChatId(123456);
        $u->setEmail('tg_123456@telegram.local');
        $tgOnly->setClientUserId($u);
        self::assertTrue(InvoiceCheckout::hasChannel($tgOnly), 'Telegram-linked account');

        $mail = $this->invoiceOrder();
        $mail->setInvoicePayer(['email' => 'buh@firma.ua']);
        self::assertTrue(InvoiceCheckout::hasChannel($mail), 'payer email');
    }
}
