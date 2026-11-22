<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\UserOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks LiqPay itself for the payment state when our callback did not tell us:
 *  - pull: a declined card in the sandbox (and sometimes in live) sends no callback, so
 *    the result page would wait minutes — `syncIfDue()` asks LiqPay `status` instead;
 *  - guard: before any new checkout for an existing order (`beforeNewAttempt()`) the
 *    previous attempt is checked (paid / in flight → no new checkout) and its LiqPay
 *    invoice (`invoice_send`, a link sent by phone) is cancelled, so the old invoice and
 *    the new checkout cannot both be paid.
 * Results go through LiqPayPaymentProcessor — the same rules and notifications as the callback.
 */
class LiqPayStatusSync
{
    /**
     * LiqPay answers that change our order (others, like payment_not_found / invoice_wait, do not).
     * `try_again` is what a declined card gets (seen in the sandbox: err_code "limit"); LiqPay sends
     * no callback for it because the buyer may still retry on the same LiqPay page.
     */
    public const APPLICABLE_STATUSES = ['success', 'failure', 'error', 'reversed', 'try_again', ...OrderOnlinePayment::IN_FLIGHT_STATUSES];

    /** Give the callback this long before asking LiqPay ourselves. */
    public const MIN_ATTEMPT_AGE_SECONDS = 20;

    /** At most one LiqPay status request per order per this many seconds (the result page polls). */
    public const THROTTLE_SECONDS = 20;

    /**
     * Do not ask about attempts older than this: the first link of an order lives
     * OrderOnlinePayment::FIRST_CHECKOUT_TTL_DAYS (a week), plus a day for a late callback.
     */
    public const MAX_ATTEMPT_AGE_SECONDS = (OrderOnlinePayment::FIRST_CHECKOUT_TTL_DAYS + 1) * 86400;

    public function __construct(
        private readonly LiqPayApi $api,
        private readonly LiqPayPaymentProcessor $processor,
        private readonly EntityManagerInterface $em,
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

    /**
     * Call right before a new checkout for an existing order is created (cabinet re-pay,
     * guest re-pay, «Оплатити онлайн» from an invoice, admin payment link). Afterwards the
     * caller's usual isPaid()/canPay() checks refuse the new checkout when the previous
     * attempt turned out paid or in flight.
     */
    public function beforeNewAttempt(UserOrder $order): void
    {
        if ($order->isPaid()) {
            return;
        }
        $current = (string) $order->getLiqPayOrderId();
        $invoiceAttempt = self::openInvoiceAttempt($order);

        try {
            foreach (array_unique(array_filter([$current, $invoiceAttempt])) as $attempt) {
                $this->syncAttempt($attempt);
                if ($order->isPaid() || !OrderOnlinePayment::canPay($order)) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            // LiqPay unreachable: keep the old behaviour (new checkout; a second success is
            // still caught by the callback's double-payment alert).
            $this->logger->error('liqpay status check before re-pay failed', ['order_id' => $order->getId(), 'error' => $e->getMessage()]);
        }

        if (null !== $invoiceAttempt) {
            if ($this->api->cancelInvoice($invoiceAttempt)) {
                $response = json_decode((string) $order->getLiqPayResponse(), true);
                $response['invoice_cancelled_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
                $order->setLiqPayResponse((string) json_encode($response, \JSON_UNESCAPED_UNICODE));
                $this->em->flush();
                $this->logger->info('liqpay invoice of the previous attempt cancelled', ['order_id' => $order->getId(), 'liqpay_order_id' => $invoiceAttempt]);
            } else {
                $this->logger->warning('liqpay invoice of the previous attempt NOT cancelled', ['order_id' => $order->getId(), 'liqpay_order_id' => $invoiceAttempt]);
            }
        }
    }

    /** The attempt whose LiqPay invoice (`invoice_send`) may still be paid, or null. */
    public static function openInvoiceAttempt(UserOrder $order): ?string
    {
        $response = json_decode((string) $order->getLiqPayResponse(), true);
        if (!\is_array($response)
            || 'invoice_wait' !== ($response['status'] ?? null)
            || isset($response['invoice_cancelled_at'])
            || !\is_string($response['order_id'] ?? null)
            || '' === $response['order_id']
        ) {
            return null;
        }

        return $response['order_id'];
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
