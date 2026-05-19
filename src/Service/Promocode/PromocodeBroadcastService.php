<?php

namespace App\Service\Promocode;

use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use App\Repository\TelegramUserRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Mass-send a promocode to all bot users and/or web users (PR #6 broadcast).
 *
 * Audience constants:
 *  - AUDIENCE_TELEGRAM: every TelegramUser with a chat_id (the bot's own customers)
 *  - AUDIENCE_EMAIL: every User with confirmed email AND no linked TG chat
 *    (web-only customers — TG-linked web users are reached via the TG side)
 *  - AUDIENCE_BOTH: the two sets above, disjoint by construction so no dedup needed.
 *
 * Rate-limit: 35ms sleep between Telegram sends to stay under Telegram's
 * ~30 msg/sec soft limit for messages to *different* users. Email sends are
 * unthrottled — the local mailer/SMTP handles its own queueing.
 */
class PromocodeBroadcastService
{
    public const AUDIENCE_TELEGRAM = 'telegram';
    public const AUDIENCE_EMAIL = 'email';
    public const AUDIENCE_BOTH = 'both';

    private const TELEGRAM_THROTTLE_USEC = 35_000;

    public function __construct(
        private readonly Nutgram $bot,
        private readonly MailerInterface $mailer,
        private readonly TelegramUserRepository $telegramUserRepository,
        private readonly UserRepository $userRepository,
        private readonly string $mailerFrom,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{telegram:int, email:int, total:int}
     */
    public function previewCounts(): array
    {
        $tgCount = (int) $this->telegramUserRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.chatId IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $emailCount = (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.isEmailConfirmed = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.telegramChatId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'telegram' => $tgCount,
            'email' => $emailCount,
            'total' => $tgCount + $emailCount,
        ];
    }

    /**
     * @param self::AUDIENCE_* $audience
     * @return array{tg_sent:int, email_sent:int, failures:list<array{channel:string,target:string,error:string}>}
     */
    public function dispatch(Promocode $promocode, string $audience, ?string $customPrefix = null): array
    {
        $tgSent = 0;
        $emailSent = 0;
        $failures = [];

        if ($audience === self::AUDIENCE_TELEGRAM || $audience === self::AUDIENCE_BOTH) {
            $tgUsers = $this->telegramUserRepository->createQueryBuilder('t')
                ->where('t.chatId IS NOT NULL')
                ->getQuery()
                ->getResult();

            $tgText = $this->buildTelegramText($promocode, $customPrefix);
            foreach ($tgUsers as $tg) {
                /** @var TelegramUser $tg */
                try {
                    $this->bot->sendMessage(
                        text: $tgText,
                        chat_id: (int) $tg->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $tgSent++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'channel' => 'telegram',
                        'target' => (string) $tg->getChatId(),
                        'error' => $e->getMessage(),
                    ];
                    $this->logger?->warning('Promocode broadcast TG failed', [
                        'promocode_id' => $promocode->getId(),
                        'chat_id' => $tg->getChatId(),
                        'error' => $e->getMessage(),
                    ]);
                }
                usleep(self::TELEGRAM_THROTTLE_USEC);
            }
        }

        if ($audience === self::AUDIENCE_EMAIL || $audience === self::AUDIENCE_BOTH) {
            $emailUsers = $this->userRepository->createQueryBuilder('u')
                ->where('u.isEmailConfirmed = true')
                ->andWhere('u.email IS NOT NULL')
                ->andWhere('u.telegramChatId IS NULL')
                ->getQuery()
                ->getResult();

            foreach ($emailUsers as $user) {
                /** @var User $user */
                try {
                    $this->sendEmail($user->getEmail(), $promocode, $customPrefix);
                    $emailSent++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'channel' => 'email',
                        'target' => $user->getEmail(),
                        'error' => $e->getMessage(),
                    ];
                    $this->logger?->warning('Promocode broadcast email failed', [
                        'promocode_id' => $promocode->getId(),
                        'email' => $user->getEmail(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'tg_sent' => $tgSent,
            'email_sent' => $emailSent,
            'failures' => $failures,
        ];
    }

    /**
     * Send a single test message — to a TG chat id, an email, or both if both given.
     *
     * @return array{tg_ok:bool, email_ok:bool, error:?string}
     */
    public function sendTest(Promocode $promocode, ?int $tgChatId, ?string $email, ?string $customPrefix = null): array
    {
        $result = ['tg_ok' => false, 'email_ok' => false, 'error' => null];

        if ($tgChatId !== null) {
            try {
                $this->bot->sendMessage(
                    text: $this->buildTelegramText($promocode, $customPrefix),
                    chat_id: $tgChatId,
                    parse_mode: ParseMode::HTML,
                );
                $result['tg_ok'] = true;
            } catch (\Throwable $e) {
                $result['error'] = 'TG: ' . $e->getMessage();
            }
        }

        if ($email !== null) {
            try {
                $this->sendEmail($email, $promocode, $customPrefix);
                $result['email_ok'] = true;
            } catch (\Throwable $e) {
                $result['error'] = ($result['error'] ? $result['error'] . '; ' : '') . 'Email: ' . $e->getMessage();
            }
        }

        return $result;
    }

    private function buildTelegramText(Promocode $promocode, ?string $customPrefix): string
    {
        $validity = '';
        if ($promocode->getValidTo() !== null) {
            $validity = sprintf("\nДійсний до: <b>%s</b>", $promocode->getValidTo()->format('d.m.Y'));
        }

        $prefix = '';
        if ($customPrefix !== null && trim($customPrefix) !== '') {
            $prefix = trim($customPrefix) . "\n\n";
        }

        return sprintf(
            "%s🎟 <b>Промокод</b>\n\n"
            . "<code>%s</code>\n\n"
            . "Знижка: <b>%s</b>%s\n\n"
            . "Введіть цей код у полі \"Промокод\" при оформленні замовлення:\n"
            . "🌐 на сайті <a href=\"https://artbeton.market\">artbeton.market</a>\n"
            . "🤖 або тут у Telegram-боті",
            $prefix,
            $promocode->getCode(),
            $this->formatDiscountValue($promocode),
            $validity,
        );
    }

    private function sendEmail(string $emailAddress, Promocode $promocode, ?string $customPrefix): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Арт Бетон Маркет'))
            ->to($emailAddress)
            ->subject('Промокод — Арт Бетон Маркет')
            ->htmlTemplate('email/promocode.html.twig')
            ->context([
                'promocode' => $promocode,
                'discountLabel' => $this->formatDiscountValue($promocode),
                'customPrefix' => $customPrefix !== null && trim($customPrefix) !== '' ? trim($customPrefix) : null,
            ]);

        $this->mailer->send($email);
    }

    private function formatDiscountValue(Promocode $promocode): string
    {
        return match ($promocode->getDiscountType()) {
            DiscountTypeEnum::PERCENT => sprintf('%d%%', $promocode->getValue()),
            DiscountTypeEnum::FIXED_AMOUNT => sprintf(
                '%d %s',
                $promocode->getValue(),
                $promocode->getCurrency()->label(),
            ),
        };
    }
}
