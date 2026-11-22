<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\TelegramUser;
use App\Entity\UserOrder;
use App\Repository\TelegramUserRepository;
use App\Repository\UserOrderRepository;
use App\Service\Analytics\ActivityService;
use App\Service\Analytics\TelegramNotifier;
use App\Service\Payment\LiqPayPaymentProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The rules the /liq/pay callback had inline, now shared with the status pull. */
class LiqPayPaymentProcessorTest extends TestCase
{
    private int $groupPosts = 0;
    private int $managerDms = 0;
    private int $tx = 0;
    private int $commits = 0;
    private ?int $failChat = null;
    private ?ArrayAdapter $cache = null;
    private AbstractLogger $logger;
    /** @var list<string> */
    private array $texts = [];

    private function order(?string $attempt, ?string $status = null): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, 42);
        $o->setOrderStatus(OrderStatusEnum::NEW->value);
        $o->setTotalAmount(8250);
        $o->setCurrency('UAH');
        $o->setLiqPayOrderId($attempt);
        $o->setLiqPayStatus($status);

        return $o;
    }

    private function processor(?UserOrder $order): LiqPayPaymentProcessor
    {
        $orders = $this->createMock(UserOrderRepository::class);
        $orders->method('getByIdFromLiqPay')->willReturn($order);

        $manager = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'chatId'))->setValue($manager, 1001);
        $tgUsers = $this->createMock(TelegramUserRepository::class);
        $tgUsers->method('findByRole')->willReturn([$manager]);

        $bot = $this->createMock(Nutgram::class);
        $bot->method('sendMessage')->willReturnCallback(function (...$args) {
            if (null !== $this->failChat && ($args['chat_id'] ?? null) === $this->failChat) {
                throw new \RuntimeException('Forbidden: bot was blocked by the user');
            }
            ++$this->managerDms;
            $this->texts[] = (string) ($args['text'] ?? $args[0] ?? '');

            return null;
        });

        $http = new MockHttpClient(function () {
            ++$this->groupPosts;

            return new MockResponse('{"ok":true}');
        });
        $activity = new ActivityService(new TelegramNotifier($http, 'token', '-100', new NullLogger()), new ArrayAdapter(), new NullLogger());

        $this->tx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(function () { ++$this->tx; });
        $connection->method('isTransactionActive')->willReturnCallback(fn () => $this->tx > 0);
        $connection->method('commit')->willReturnCallback(function () { --$this->tx; ++$this->commits; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects($order ? self::atLeastOnce() : self::never())->method('refresh')->with($order, LockMode::PESSIMISTIC_WRITE);

        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $critical = [];
            /** @var list<string> */
            public array $warning = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (LogLevel::CRITICAL === $level) {
                    $this->critical[] = (string) $message;
                }
                if (LogLevel::WARNING === $level) {
                    $this->warning[] = (string) $message;
                }
            }
        };

        return new LiqPayPaymentProcessor($bot, $this->logger, $orders, $tgUsers, $em, $activity, $this->cache ??= new ArrayAdapter());
    }

    public function testUnknownOrder(): void
    {
        self::assertFalse($this->processor(null)->process(['order_id' => '999-1', 'status' => 'success'], 'https://api.example'));
    }

    public function testSuccessMarksPaidAndNotifiesOnce(): void
    {
        $order = $this->order('42-200');
        $p = $this->processor($order);

        self::assertTrue($p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example'));
        self::assertTrue($order->isPaid());
        self::assertSame(1, $this->managerDms);
        self::assertSame(1, $this->groupPosts);

        // The callback arriving after the pull (or twice) is harmless.
        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example');
        self::assertSame(1, $this->managerDms);
        self::assertSame(1, $this->groupPosts);
    }

    public function testSuccessOfAnotherAttemptOnAPaidOrderIsADoublePaymentAlert(): void
    {
        $order = $this->order('42-200', 'success');
        $this->processor($order)->process(['order_id' => '42-100', 'status' => 'success', 'payment_id' => 2, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example');

        self::assertSame('42-200', $order->getLiqPayOrderId(), 'the paying attempt is not overwritten');
        self::assertSame(1, $this->managerDms, 'managers get the refund alert');
    }

    public function testPaidOrderIsNotDowngraded(): void
    {
        $order = $this->order('42-200', 'success');
        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'failure'], 'https://api.example');

        self::assertSame('success', $order->getLiqPayStatus());
    }

    public function testLateFailureOfAnOlderAttemptIsIgnored(): void
    {
        $order = $this->order('42-200');
        $this->processor($order)->process(['order_id' => '42-100', 'status' => 'failure'], 'https://api.example');

        self::assertNull($order->getLiqPayStatus());
    }

    public function testFailureOfTheCurrentAttemptIsStored(): void
    {
        $order = $this->order('42-200');
        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'failure'], 'https://api.example');

        self::assertSame('failure', $order->getLiqPayStatus());
        self::assertSame(0, $this->groupPosts);
        self::assertSame(0, $this->tx, 'the row lock is released');
    }

    public function testTheSameInFlightAnswerTwiceTellsTheBuyerOnce(): void
    {
        $order = $this->order('42-200');
        $buyer = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'chatId'))->setValue($buyer, 2002);
        $order->setTelegramUserid($buyer);
        $p = $this->processor($order);

        $p->process(['order_id' => '42-200', 'status' => 'wait_secure'], 'https://api.example');
        $p->process(['order_id' => '42-200', 'status' => 'wait_secure'], 'https://api.example');

        self::assertSame('wait_secure', $order->getLiqPayStatus());
        self::assertSame(1, $this->managerDms, 'one «Статус оплати» message to the buyer');
        self::assertSame(0, $this->tx);
    }

    public function testARefundOfAPaidOrderIsToldToTheBuyer(): void
    {
        $order = $this->order('42-200', 'success');
        $buyer = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'chatId'))->setValue($buyer, 2002);
        $order->setTelegramUserid($buyer);

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'reversed'], 'https://api.example');

        self::assertSame('reversed', $order->getLiqPayStatus());
        self::assertSame(['Оплату за замовлення #42 повернено на вашу картку.'], $this->texts);
        self::assertSame(0, $this->groupPosts, 'no «new paid order» for managers');
    }

    public function testADeclinedCardIsExplainedToTheBuyerInWords(): void
    {
        $order = $this->order('42-200');
        $buyer = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'chatId'))->setValue($buyer, 2002);
        $order->setTelegramUserid($buyer);

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'try_again'], 'https://api.example');

        self::assertCount(1, $this->texts);
        self::assertStringContainsString('Банк відхилив оплату за замовлення #42', $this->texts[0]);
        self::assertStringNotContainsString('try_again', $this->texts[0]);
    }

    public function testABlockedBuyerChatDoesNotCostTheManagerNotification(): void
    {
        $order = $this->order('42-200');
        $buyer = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'chatId'))->setValue($buyer, 2002);
        $buyer->setFirstName('Тест');
        $buyer->setLastName(null);
        $buyer->setPhoneNumber(null);
        $order->setTelegramUserid($buyer);
        $p = $this->processor($order);
        $this->failChat = 2002;

        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example');

        self::assertTrue($order->isPaid());
        self::assertSame(1, $this->groupPosts, 'the manager group still hears about the payment');
    }

    /** @dataProvider mismatches */
    public function testASuccessWithTheWrongAmountOrCurrencyIsNotPaid(array $payment, string $reason): void
    {
        $order = $this->order('42-200');
        $p = $this->processor($order);

        self::assertTrue($p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 7] + $payment, 'https://api.example'));

        self::assertFalse($order->isPaid());
        self::assertNull($order->getLiqPayStatus(), 'status untouched');
        self::assertCount(1, $this->logger->critical);
        self::assertSame(1, $this->managerDms, 'managers get the alert');
        self::assertSame(1, $this->groupPosts, 'and the manager group');
        self::assertStringContainsString('НЕ позначено оплаченим', $this->texts[0]);
        self::assertStringContainsString($reason, $this->texts[0]);
        self::assertSame(0, $this->tx, 'the row lock is released');

        // The status pull / a repeated callback brings the same payment again: one alert only.
        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 7] + $payment, 'https://api.example');
        self::assertFalse($order->isPaid());
        self::assertSame(1, $this->managerDms);
        self::assertSame(1, $this->groupPosts);
        self::assertCount(1, $this->logger->critical, 'a repeat is a warning, not another critical');
        self::assertCount(1, $this->logger->warning);
    }

    public function testARefundOfAMismatchedPaymentIsStoredButNeverPays(): void
    {
        $order = $this->order('42-200');
        $p = $this->processor($order);
        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 7, 'amount' => 8250, 'currency' => 'USD'], 'https://api.example');
        $p->process(['order_id' => '42-200', 'status' => 'reversed', 'payment_id' => 7, 'amount' => 8250, 'currency' => 'USD'], 'https://api.example');

        self::assertFalse($order->isPaid());
        self::assertSame('reversed', $order->getLiqPayStatus());
    }

    public function testAMismatchAfterAnInFlightStatusKeepsTheOrderUnpaid(): void
    {
        $order = $this->order('42-200', 'wait_secure');
        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 7, 'amount' => 100, 'currency' => 'UAH'], 'https://api.example');

        self::assertFalse($order->isPaid());
        self::assertSame('wait_secure', $order->getLiqPayStatus());
        self::assertSame(1, $this->managerDms);
    }

    public function testOldUsdOrderIsLabelledInDollarsFromItsStoredAnswer(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency(null);
        $order->setLiqPayResponse('{"status":"invoice_wait","currency":"USD"}');
        self::assertSame('USD', $order->getCurrencyLabel());
    }

    public static function mismatches(): iterable
    {
        yield 'USD instead of UAH' => [['amount' => 8250, 'currency' => 'USD'], 'валюта USD замість UAH'];
        yield 'smaller amount' => [['amount' => 8249.5, 'currency' => 'UAH'], 'сума 8249.5 замість 8250'];
        yield 'bigger amount' => [['amount' => '8270.00', 'currency' => 'UAH'], 'сума 8270 замість 8250'];
        yield 'no amount' => [['currency' => 'UAH'], 'не повідомив суму'];
        yield 'no currency' => [['amount' => 8250], 'не повідомив валюту'];
    }

    public function testAUsdOrderPaidInUsdIsPaid(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency('USD');
        $order->setTotalAmount(120); // goods $100 + Cherkasy delivery $20

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 120.0, 'currency' => 'USD'], 'https://api.example');

        self::assertTrue($order->isPaid());
        self::assertCount(0, $this->logger->critical);
        self::assertStringContainsString('Сума: 120 USD', $this->texts[0]);
    }

    public function testAUsdOrderPaidAsTheSameNumberInUahIsNotPaid(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency('USD');
        $order->setTotalAmount(120);

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 120, 'currency' => 'UAH'], 'https://api.example');

        self::assertFalse($order->isPaid());
        self::assertStringContainsString('валюта UAH замість USD', $this->texts[0]);
    }

    public function testAnOldOrderWithoutCurrencyUsesTheStoredInvoiceSendAnswer(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency(null);
        $order->setLiqPayResponse('{"status":"invoice_wait","currency":"USD","amount":8250,"order_id":"42-200"}');

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'USD'], 'https://api.example');

        self::assertTrue($order->isPaid());
    }

    public function testAnOldOrderWithUnknownCurrencyIsLeftForTheManagers(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency(null);

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example');

        self::assertFalse($order->isPaid());
        self::assertStringContainsString('невідомо, в якій валюті', $this->texts[0]);
    }

    public function testAnInvoiceOrderIsExpectedInHryvnia(): void
    {
        $order = $this->order('42-200');
        $order->setCurrency(null);
        $order->setPaymentMethod('invoice');

        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250, 'currency' => 'UAH'], 'https://api.example');

        self::assertTrue($order->isPaid());
    }

    public function testAFailureIsNotAmountChecked(): void
    {
        $order = $this->order('42-200');
        $this->processor($order)->process(['order_id' => '42-200', 'status' => 'failure', 'amount' => 1, 'currency' => 'USD'], 'https://api.example');

        self::assertSame('failure', $order->getLiqPayStatus());
        self::assertCount(0, $this->logger->critical);
    }
}
