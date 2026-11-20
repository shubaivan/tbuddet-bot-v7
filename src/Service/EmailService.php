<?php

namespace App\Service;

use App\Entity\Enum\OrderStatusEnum;
use App\Entity\User;
use App\Entity\UserOrder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $mailerFrom,
        private string $frontendUrl,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param bool $reuseValidToken resend: keep the token of the first letter (only the
     *                              expiry moves), so the link the buyer already has still works
     */
    public function sendConfirmationEmail(User $user, bool $reuseValidToken = false): void
    {
        $expiresAt = new \DateTimeImmutable('+24 hours');
        $current = $user->getConfirmationToken();
        $isCurrentValid = null !== $current
            && null !== $user->getConfirmationTokenExpiresAt()
            && $user->getConfirmationTokenExpiresAt() > new \DateTimeImmutable();
        $token = $reuseValidToken && $isCurrentValid ? $current : bin2hex(random_bytes(32));

        $user->setConfirmationToken($token);
        $user->setConfirmationTokenExpiresAt($expiresAt);

        $confirmUrl = rtrim($this->frontendUrl, '/') . '/uk/confirm-email?token=' . $token;

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($user->getEmail())
            ->subject('Підтвердження реєстрації на Арт Бетон Маркет')
            ->htmlTemplate('email/confirmation.html.twig')
            ->context([
                'user' => $user,
                'confirmUrl' => $confirmUrl,
                'expiresAt' => $expiresAt,
            ]);

        $this->mailer->send($email);
    }

    public function sendPasswordResetEmail(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $user->setResetPasswordToken($token);
        $user->setResetPasswordTokenExpiresAt($expiresAt);

        $resetUrl = rtrim($this->frontendUrl, '/') . '/uk/reset-password?token=' . $token;

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($user->getEmail())
            ->subject('Скидання пароля — Арт Бетон Маркет')
            ->htmlTemplate('email/password-reset.html.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $resetUrl,
                'expiresAt' => $expiresAt,
            ]);

        $this->mailer->send($email);
    }

    /**
     * Emails the client a LiqPay payment link for an unpaid order.
     * Returns the email address it was sent to, or null if the order has no
     * client email (e.g. a bot order) — the caller then shows the link for
     * manual delivery.
     */
    public function sendPaymentLinkEmail(UserOrder $order, string $link): ?string
    {
        $emailAddress = $order->getClientUserId()?->getEmail();
        if (!$emailAddress) {
            return null;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($emailAddress)
            ->subject(sprintf('Оплата замовлення #%d — Арт Бетон Маркет', $order->getId()))
            ->htmlTemplate('email/payment-link.html.twig')
            ->context([
                'order' => $order,
                'paymentLink' => $link,
            ]);

        $this->mailer->send($email);

        return $emailAddress;
    }

    /**
     * Sends the «Рахунок на оплату» PDF to the payer email. Returns the address
     * it went to, or null when there is none.
     */
    public function sendInvoiceEmail(UserOrder $order, string $pdf, ?string $payUrl = null): ?string
    {
        $to = $order->getInvoiceEmail();
        if (!$to) {
            return null;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($to)
            ->subject(sprintf('Рахунок на оплату № %s — Арт Бетон Маркет', $order->getInvoiceNumber()))
            ->htmlTemplate('email/invoice.html.twig')
            ->context([
                'order' => $order,
                'payer' => $order->getInvoicePayer(),
                'profileUrl' => rtrim($this->frontendUrl, '/') . '/uk/profile',
                'payUrl' => $payUrl,
            ])
            ->attach($pdf, sprintf('Rahunok-%s.pdf', $order->getInvoiceNumber()), 'application/pdf');

        $this->mailer->send($email);

        return $to;
    }

    /** «Оплату отримано» after a manager confirms the bank transfer. */
    public function sendInvoicePaidEmail(UserOrder $order): ?string
    {
        $to = $order->getInvoiceEmail();
        if (!$to) {
            return null;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($to)
            ->subject(sprintf('Оплату за рахунком № %s отримано — Арт Бетон Маркет', $order->getInvoiceNumber()))
            ->htmlTemplate('email/invoice-paid.html.twig')
            ->context([
                'order' => $order,
                'profileUrl' => rtrim($this->frontendUrl, '/') . '/uk/profile',
            ]);

        $this->mailer->send($email);

        return $to;
    }

    public function sendOrderStatusChangeEmail(UserOrder $order, string $newStatus): void
    {
        $statusEnum = OrderStatusEnum::tryFrom($newStatus);
        if ($statusEnum === null
            || $statusEnum === OrderStatusEnum::NEW
            || $statusEnum === OrderStatusEnum::AWAITING_INVOICE_PAYMENT
        ) {
            return;
        }

        $user = $order->getClientUserId();
        if ($user === null || !$user->getEmail()) {
            return;
        }

        $subject = match ($statusEnum) {
            OrderStatusEnum::PROCESSING => sprintf('Замовлення #%d прийнято в обробку — Арт Бетон Маркет', $order->getId()),
            OrderStatusEnum::SHIPPED    => sprintf('Замовлення #%d відправлено — Арт Бетон Маркет', $order->getId()),
            OrderStatusEnum::DELIVERED  => sprintf('Замовлення #%d доставлено — Дякуємо!', $order->getId()),
            OrderStatusEnum::CANCELLED  => sprintf('Замовлення #%d скасовано', $order->getId()),
            default                     => sprintf('Оновлення замовлення #%d', $order->getId()),
        };

        $deliveryAddress = $order->isCityDelivery()
            ? $order->getDeliverySummary()
            : trim(implode(', ', array_filter([
                $order->getDeliveryCity(),
                $order->getDeliveryDepartment(),
            ])));

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
                ->to($user->getEmail())
                ->subject($subject)
                ->htmlTemplate('email/order-status-changed.html.twig')
                ->context([
                    'order'           => $order,
                    'user'            => $user,
                    'newStatus'       => $statusEnum->value,
                    'statusLabel'     => $statusEnum->label(),
                    'trackingNumber'  => $order->getNovaPoshtaTrackingNumber(),
                    'deliveryAddress' => $deliveryAddress ?: null,
                    'profileUrl'      => rtrim($this->frontendUrl, '/') . '/uk/profile',
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send order status change email', [
                'order_id' => $order->getId(),
                'status'   => $newStatus,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
