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
    /** @var list<string> */
    private array $texts = [];

    private function order(?string $attempt, ?string $status = null): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, 42);
        $o->setOrderStatus(OrderStatusEnum::NEW->value);
        $o->setTotalAmount(8250);
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

        return new LiqPayPaymentProcessor($bot, new NullLogger(), $orders, $tgUsers, $em, $activity);
    }

    public function testUnknownOrder(): void
    {
        self::assertFalse($this->processor(null)->process(['order_id' => '999-1', 'status' => 'success'], 'https://api.example'));
    }

    public function testSuccessMarksPaidAndNotifiesOnce(): void
    {
        $order = $this->order('42-200');
        $p = $this->processor($order);

        self::assertTrue($p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250], 'https://api.example'));
        self::assertTrue($order->isPaid());
        self::assertSame(1, $this->managerDms);
        self::assertSame(1, $this->groupPosts);

        // The callback arriving after the pull (or twice) is harmless.
        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1, 'amount' => 8250], 'https://api.example');
        self::assertSame(1, $this->managerDms);
        self::assertSame(1, $this->groupPosts);
    }

    public function testSuccessOfAnotherAttemptOnAPaidOrderIsADoublePaymentAlert(): void
    {
        $order = $this->order('42-200', 'success');
        $this->processor($order)->process(['order_id' => '42-100', 'status' => 'success', 'payment_id' => 2, 'amount' => 8250], 'https://api.example');

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

        $p->process(['order_id' => '42-200', 'status' => 'success', 'payment_id' => 1], 'https://api.example');

        self::assertTrue($order->isPaid());
        self::assertSame(1, $this->groupPosts, 'the manager group still hears about the payment');
    }
}
