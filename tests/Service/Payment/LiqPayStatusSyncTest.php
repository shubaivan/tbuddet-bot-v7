<?php

declare(strict_types=1);

namespace App\Tests\Service\Payment;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\UserOrder;
use App\Service\Payment\LiqPayApi;
use App\Service\Payment\LiqPayPaymentProcessor;
use App\Service\Payment\LiqPayStatusSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class LiqPayStatusSyncTest extends TestCase
{
    private function order(?string $attempt, ?string $status = null, ?array $liqPayResponse = null): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, 42);
        $o->setOrderStatus(OrderStatusEnum::NEW->value);
        $o->setTotalAmount(8250);
        $o->setLiqPayOrderId($attempt);
        $o->setLiqPayStatus($status);
        if (null !== $liqPayResponse) {
            $o->setLiqPayResponse(json_encode($liqPayResponse));
        }

        return $o;
    }

    /** A processor double that applies the status to the order like the real one does for the current attempt. */
    private function processor(UserOrder $order, ?array &$seen = null): LiqPayPaymentProcessor
    {
        $p = $this->createMock(LiqPayPaymentProcessor::class);
        $p->method('process')->willReturnCallback(function (array $payload, string $baseUrl) use ($order, &$seen) {
            $seen[] = [$payload, $baseUrl];
            $order->setLiqPayStatus($payload['status']);

            return true;
        });

        return $p;
    }

    private function sync(LiqPayApi $api, LiqPayPaymentProcessor $processor, ?ArrayAdapter $cache = null): LiqPayStatusSync
    {
        return new LiqPayStatusSync($api, $processor, $cache ?? new ArrayAdapter(), new NullLogger(), 'https://api.example:8443/liq/pay');
    }

    public function testAttemptAge(): void
    {
        self::assertSame(30, LiqPayStatusSync::attemptAge('88-1795214515', 1795214545));
        self::assertNull(LiqPayStatusSync::attemptAge('88'));
        self::assertNull(LiqPayStatusSync::attemptAge('ring-88-1795214515'));
    }

    public function testSyncAttemptAppliesAFoundPaymentWithTheApiHost(): void
    {
        $order = $this->order('42-1795214515');
        $api = $this->createMock(LiqPayApi::class);
        $api->method('status')->with('42-1795214515')->willReturn(['status' => 'failure', 'order_id' => '42-1795214515', 'payment_id' => 77, 'amount' => 8250]);

        self::assertSame('failure', $this->sync($api, $this->processor($order, $seen))->syncAttempt('42-1795214515'));
        self::assertSame('failure', $order->getLiqPayStatus());
        self::assertSame('https://api.example:8443', $seen[0][1]);
    }

    public function testSyncAttemptIgnoresNotFoundAndForeignAnswers(): void
    {
        $order = $this->order('42-1795214515');
        $api = $this->createMock(LiqPayApi::class);
        $api->method('status')->willReturnOnConsecutiveCalls(
            ['status' => 'error', 'err_code' => 'payment_not_found', 'result' => 'error'],
            ['status' => 'success', 'order_id' => '43-1795214515', 'payment_id' => 1],
            ['status' => 'invoice_wait', 'order_id' => '42-1795214515', 'payment_id' => 1],
            null,
        );
        $processor = $this->createMock(LiqPayPaymentProcessor::class);
        $processor->expects(self::never())->method('process');
        $sync = $this->sync($api, $processor);

        for ($i = 0; $i < 4; ++$i) {
            self::assertNull($sync->syncAttempt('42-1795214515'));
        }
    }

    public function testSyncIfDueAsksOnlyWhenWaitingAndThrottles(): void
    {
        $old = sprintf('42-%d', time() - 60);
        $api = $this->createMock(LiqPayApi::class);
        $api->expects(self::once())->method('status')->with($old)->willReturn(['status' => 'error', 'err_code' => 'payment_not_found']);
        $sync = $this->sync($api, $this->createMock(LiqPayPaymentProcessor::class));

        $sync->syncIfDue($this->order($old));
        $sync->syncIfDue($this->order($old)); // throttled
    }

    public function testSyncIfDueSkipsFreshKnownAndPaidOrders(): void
    {
        $api = $this->createMock(LiqPayApi::class);
        $api->expects(self::never())->method('status');
        $sync = $this->sync($api, $this->createMock(LiqPayPaymentProcessor::class));

        $sync->syncIfDue($this->order(sprintf('42-%d', time() - 5)));            // callback still has time
        $sync->syncIfDue($this->order(sprintf('42-%d', time() - 60), 'failure')); // final answer known
        $sync->syncIfDue($this->order(sprintf('42-%d', time() - 60), 'success')); // paid
        $sync->syncIfDue($this->order(sprintf('42-%d', time() - 3 * 86400)));    // long expired
        $sync->syncIfDue($this->order(null));
    }
}
