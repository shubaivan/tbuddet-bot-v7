<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\UserOrder;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks LiqPay itself for the payment state when our callback did not tell us:
 *  - pull: a declined card in the sandbox (and sometimes in live) sends no callback, so
 *    the result page would wait minutes — `syncIfDue()` asks LiqPay `status` instead;
 * Results go through LiqPayPaymentProcessor — the same rules and notifications as the callback.
 */
class LiqPayStatusSync
{
    /** LiqPay answers that change our order (others, like payment_not_found / invoice_wait, do not). */
    public const APPLICABLE_STATUSES = ['success', 'failure', 'error', 'reversed', ...OrderOnlinePayment::IN_FLIGHT_STATUSES];

    /** Give the callback this long before asking LiqPay ourselves. */
    public const MIN_ATTEMPT_AGE_SECONDS = 20;

    /** At most one LiqPay status request per order per this many seconds (the result page polls). */
    public const THROTTLE_SECONDS = 20;

    /** Do not ask about attempts older than this (the checkout lives 60 min). */
    public const MAX_ATTEMPT_AGE_SECONDS = 2 * 86400;

    public function __construct(
        private readonly LiqPayApi $api,
        private readonly LiqPayPaymentProcessor $processor,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $liqpayServerUrl,
    ) {
    }

    /**
     * Asks LiqPay about one attempt and applies the answer.
     *
     * @return string|null the LiqPay status that was applied, null when nothing changed hands
     */
    public function syncAttempt(string $liqPayOrderId): ?string
    {
        $res = $this->api->status($liqPayOrderId);
        if (null === $res
            || ($res['order_id'] ?? null) !== $liqPayOrderId
            || empty($res['payment_id'])
            || !\in_array($res['status'] ?? null, self::APPLICABLE_STATUSES, true)
        ) {
            return null;
        }

        $this->logger->info('liqpay status pulled', [
            'liqpay_order_id' => $liqPayOrderId,
            'status' => $res['status'],
            'payment_id' => $res['payment_id'],
        ]);
        $this->processor->process($res, $this->baseUrl());

        return (string) $res['status'];
    }

    /**
     * For the payment-result page: the order is waiting for a callback (no status or
     * in flight) and the attempt is old enough → ask LiqPay, throttled per order.
     */
    public function syncIfDue(UserOrder $order): void
    {
        $attempt = (string) $order->getLiqPayOrderId();
        if ($order->isPaid() || '' === $attempt) {
            return;
        }
        $status = $order->getLiqPayStatus();
        if (null !== $status && !\in_array($status, OrderOnlinePayment::IN_FLIGHT_STATUSES, true)) {
            return; // final answer already known
        }
        $age = self::attemptAge($attempt);
        if (null === $age || $age < self::MIN_ATTEMPT_AGE_SECONDS || $age > self::MAX_ATTEMPT_AGE_SECONDS) {
            return;
        }

        try {
            $item = $this->cache->getItem('liqpay_status_pull_'.$order->getId());
            if ($item->isHit()) {
                return;
            }
            $item->set(time())->expiresAfter(self::THROTTLE_SECONDS);
            $this->cache->save($item);

            $this->syncAttempt($attempt);
        } catch (\Throwable $e) {
            $this->logger->error('liqpay status pull failed', ['order_id' => $order->getId(), 'error' => $e->getMessage()]);
        }
    }

    /** Seconds since the attempt "<order id>-<unix time>" was created; null for other formats. */
    public static function attemptAge(string $liqPayOrderId, ?int $now = null): ?int
    {
        if (!preg_match('/^\d+-(\d{9,11})$/', $liqPayOrderId, $m)) {
            return null;
        }

        return ($now ?? time()) - (int) $m[1];
    }

    private function baseUrl(): string
    {
        $parts = parse_url($this->liqpayServerUrl);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
