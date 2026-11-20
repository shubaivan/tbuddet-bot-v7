<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\Enum\PaymentMethodEnum;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Service\EmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pay-by-invoice for every entry point: the cart checkout and both quick-buy
 * flows (logged-in and guest). One place decides how the buyer gets the PDF:
 * email when given, otherwise the customer bot in Telegram; it is always
 * downloadable from the success page / the order history via the public link.
 */
class InvoiceCheckout
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceNotifier $invoiceNotifier,
        private readonly EmailService $emailService,
        private readonly InvoicePublicLink $invoiceLinks,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->invoiceService->isAvailable();
    }

    /**
     * Turns a freshly built order into an invoice order: payer snapshot, lines,
     * number and dates. Call before the final flush.
     *
     * @param array<int, array{name: string, unit: string, qty: int, price: int, sum: int}> $lines
     *
     * @throws InvoiceRequisitesMissingException
     */
    public function prepare(UserOrder $order, array $payerData, array $lines, ?User $rememberFor = null): void
    {
        $payer = InvoicePayer::fromArray($payerData);
        $order->setPaymentMethod(PaymentMethodEnum::INVOICE->value);
        $order->setOrderStatus(OrderStatusEnum::AWAITING_INVOICE_PAYMENT->value);
        $order->setInvoicePayer($payer->toArray());
        if ($order->getPhone() === null || $order->getPhone() === '') {
            $order->setPhone($payer->phone);
        }
        $order->setInvoiceLines($lines);
        $this->invoiceService->issue($order);
        $rememberFor?->setInvoicePayerProfile($payer->toArray());
    }

    /**
     * After the order is flushed: render the PDF, deliver it, tell the managers.
     * Delivery failures never fail the purchase — they are reported instead.
     *
     * @return array<string, mixed> the `invoice` block of the API response
     */
    public function complete(UserOrder $order): array
    {
        $publicUrl = $this->invoiceLinks->url($order);
        $sentTo = null;
        $telegramSent = false;

        try {
            $pdf = $this->invoiceService->renderPdf($order);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice PDF render failed', ['order' => $order->getId(), 'error' => $e->getMessage()]);
            $pdf = null;
        }

        if (null !== $pdf) {
            try {
                $sentTo = $this->emailService->sendInvoiceEmail($order, $pdf);
            } catch (\Throwable $e) {
                $this->logger->error('Invoice email failed', ['order' => $order->getId(), 'error' => $e->getMessage()]);
            }
            if (null === $sentTo) {
                $telegramSent = $this->invoiceNotifier->sendPdfToBuyer($order, $pdf, $publicUrl);
            }
        }

        $this->invoiceNotifier->invoiceIssued(
            $order,
            $this->urlGenerator->generate('app_admin_order_detail', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            null !== $sentTo,
            $publicUrl,
            $telegramSent,
        );

        return [
            'number' => $order->getInvoiceNumber(),
            'due_date' => $order->getInvoiceDueDate()?->format('Y-m-d'),
            'email' => $order->getInvoiceEmail(),
            'emailed' => null !== $sentTo,
            'telegram_sent' => $telegramSent,
            'public_url' => $publicUrl,
            'total' => $order->getTotalAmount(),
        ];
    }
}
