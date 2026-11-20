<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * «Оплатити онлайн» for an order that already exists (pay later): a fresh LiqPay
 * checkout for the SAME order — no new order, the amount is the stored total
 * (promo discount and delivery already in it).
 *
 * LiqPay order_id is "<order id>-<unix time>": a new one per attempt, while the
 * callback (/liq/pay → UserOrderRepository::getByIdFromLiqPay((int) order_id))
 * maps every attempt back to this order.
 */
class OrderOnlinePayment
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly string $liqpayPublicKey,
        private readonly string $liqpayPrivateKey,
        private readonly string $liqpayServerUrl,
        private readonly string $frontendUrl,
    ) {
    }

    /** Paid, cancelled or zero-sum orders cannot be paid (again). */
    public static function canPay(UserOrder $order): bool
    {
        return !$order->isPaid()
            && $order->getOrderStatus() !== OrderStatusEnum::CANCELLED->value
            && $order->getTotalAmount() > 0;
    }

    /**
     * @param string $lang FE locale slug: 'uk' or 'en'
     */
    public function checkoutLink(UserOrder $order, string $lang = 'uk'): string
    {
        $lang = 'en' === $lang ? 'en' : 'uk';
        $liqPayOrderId = sprintf('%d-%d', $order->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);
        $cnb = $liqpay->cnb_form_raw([
            'action' => 'pay',
            'version' => '3',
            'amount' => $order->getTotalAmount(),
            // Invoices are always in hryvnia; card orders keep the currency they were placed in.
            'currency' => self::currency($order, $lang),
            'order_id' => $liqPayOrderId,
            'server_url' => $this->liqpayServerUrl,
            'result_url' => sprintf('%s/%s/payment-success?order=%d', rtrim($this->frontendUrl, '/'), $lang, $order->getId()),
            'description' => self::description($order),
            'language' => $lang,
        ]);

        $order->setLiqPayOrderId($liqPayOrderId);
        $this->em->flush();

        return sprintf('%s?data=%s&signature=%s', $cnb['url'], $cnb['data'], $cnb['signature']);
    }

    public static function currency(UserOrder $order, string $lang): string
    {
        if ($order->isInvoicePayment()) {
            return 'UAH';
        }
        $first = json_decode((string) $order->getLiqPayResponse(), true);
        if (\is_array($first) && \in_array($first['currency'] ?? null, ['UAH', 'USD', 'EUR'], true)) {
            return $first['currency'];
        }

        return 'en' === $lang ? 'USD' : 'UAH';
    }

    public static function description(UserOrder $order): string
    {
        $base = trim((string) $order->getDescription());
        if ($order->isInvoicePayment() && $order->getInvoiceNumber()) {
            $head = sprintf('Оплата згідно рахунку № %s, замовлення #%d', $order->getInvoiceNumber(), $order->getId());

            return $base !== '' ? $head.PHP_EOL.$base : $head;
        }

        return $base !== '' ? $base : sprintf('Замовлення #%d', $order->getId());
    }
}
