<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $mailerFrom,
        private string $frontendUrl,
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
            ->from(new Address($this->mailerFrom, 'Buddet'))
            ->to($user->getEmail())
            ->subject('Підтвердження реєстрації на Buddet')
            ->htmlTemplate('email/confirmation.html.twig')
            ->context([
                'user' => $user,
                'confirmUrl' => $confirmUrl,
                'expiresAt' => $expiresAt,
            ]);

        $this->mailer->send($email);
    }
}
