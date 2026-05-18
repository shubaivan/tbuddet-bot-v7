<?php

namespace App\Service\Promocode;

use App\Entity\Promocode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Hand-deliver an admin-issued single-use promocode to the assigned buyer.
 *
 * Channel selection (best-channel-first):
 *   1. If the code is assigned to a TelegramUser with a chat_id   → Telegram DM
 *   2. Else if assigned to a User with telegram_chat_id           → Telegram DM
 *   3. Else if assigned to a User with email                      → email
 *   4. Else                                                       → no-op (admin must share manually)
 *
 * On success, the promocode's delivered_at + delivery_channel fields are set so
 * the admin list can show a "✓ Надіслано через TG" badge.
 */
class PromocodeDeliveryService
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly string $mailerFrom,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns the channel used, or null if no usable channel was found.
     */
    public function deliver(Promocode $promocode): ?string
    {
        $assignedTg = $promocode->getAssignedTelegramUser();
        $assignedUser = $promocode->getAssignedUser();

        // Channel 1: TelegramUser (bot-native customer)
        if ($assignedTg !== null && $assignedTg->getChatId()) {
            try {
                $this->sendTelegramMessage((int) $assignedTg->getChatId(), $promocode);
                $this->markDelivered($promocode, Promocode::DELIVERY_CHANNEL_TELEGRAM);
                return Promocode::DELIVERY_CHANNEL_TELEGRAM;
            } catch (\Throwable $e) {
                $this->logger?->error('Promocode TG delivery failed', [
                    'promocode_id' => $promocode->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Channel 2: Web user linked to a Telegram chat
        if ($assignedUser !== null && $assignedUser->getTelegramChatId()) {
            try {
                $this->sendTelegramMessage((int) $assignedUser->getTelegramChatId(), $promocode);
                $this->markDelivered($promocode, Promocode::DELIVERY_CHANNEL_TELEGRAM);
                return Promocode::DELIVERY_CHANNEL_TELEGRAM;
            } catch (\Throwable $e) {
                $this->logger?->error('Promocode TG delivery failed', [
                    'promocode_id' => $promocode->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Channel 3: Email fallback
        if ($assignedUser !== null && $assignedUser->getEmail()) {
            try {
                $this->sendEmail($assignedUser->getEmail(), $promocode);
                $this->markDelivered($promocode, Promocode::DELIVERY_CHANNEL_EMAIL);
                return Promocode::DELIVERY_CHANNEL_EMAIL;
            } catch (\Throwable $e) {
                $this->logger?->error('Promocode email delivery failed', [
                    'promocode_id' => $promocode->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function sendTelegramMessage(int $chatId, Promocode $promocode): void
    {
        $validity = '';
        if ($promocode->getValidTo() !== null) {
            $validity = sprintf("\nДійсний до: <b>%s</b>", $promocode->getValidTo()->format('d.m.Y'));
        }

        $message = sprintf(
            "🎟 <b>Ваш персональний промокод</b>\n\n"
            . "<code>%s</code>\n\n"
            . "Знижка: <b>%s</b>%s\n\n"
            . "Введіть цей код у полі \"Промокод\" при оформленні замовлення.",
            $promocode->getCode(),
            $this->formatDiscountValue($promocode),
            $validity,
        );

        $this->bot->sendMessage(
            text: $message,
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
        );
    }

    private function sendEmail(string $emailAddress, Promocode $promocode): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($emailAddress)
            ->subject('Ваш персональний промокод — Арт Бетон Маркет')
            ->htmlTemplate('email/promocode.html.twig')
            ->context([
                'promocode' => $promocode,
                'discountLabel' => $this->formatDiscountValue($promocode),
            ]);

        $this->mailer->send($email);
    }

    private function markDelivered(Promocode $promocode, string $channel): void
    {
        $promocode->setDeliveredAt(new \DateTime());
        $promocode->setDeliveryChannel($channel);
        $this->em->flush();
    }

    private function formatDiscountValue(Promocode $promocode): string
    {
        return match ($promocode->getDiscountType()) {
            \App\Entity\Enum\DiscountTypeEnum::PERCENT => sprintf('%d%%', $promocode->getValue()),
            \App\Entity\Enum\DiscountTypeEnum::FIXED_AMOUNT => sprintf(
                '%d %s',
                $promocode->getValue(),
                $promocode->getCurrency()->label(),
            ),
        };
    }
}
