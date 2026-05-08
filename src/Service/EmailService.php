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

    public function sendConfirmationEmail(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+24 hours');

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

    public function sendOrderStatusChangeEmail(UserOrder $order, string $newStatus): void
    {
        $statusEnum = OrderStatusEnum::tryFrom($newStatus);
        if ($statusEnum === null || $statusEnum === OrderStatusEnum::NEW) {
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

        $deliveryAddress = trim(implode(', ', array_filter([
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
