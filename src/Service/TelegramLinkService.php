<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class TelegramLinkService
{
    private const TOKEN_TTL_MINUTES = 30;
    public const PAYLOAD_PREFIX = 'link_';

    public function __construct(
        private EntityManagerInterface $em,
        private string $telegramBotName,
    ) {
    }

    /**
     * Issue a one-time deep link the user can click to associate their Telegram chat with the account.
     */
    public function issueLinkUrl(User $user): array
    {
        $token = bin2hex(random_bytes(16));
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes');

        $user->setTelegramLinkToken($token);
        $user->setTelegramLinkTokenExpiresAt($expiresAt);
        $this->em->flush();

        $username = ltrim($this->telegramBotName, '@');
        $url = sprintf('https://t.me/%s?start=%s%s', $username, self::PAYLOAD_PREFIX, $token);

        return [
            'telegramUrl' => $url,
            'expiresAt'   => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Resolve a `/start link_<token>` payload from the bot to the owning user, mark them linked.
     * Returns the linked User on success, null if token is missing/expired.
     */
    public function consumeLinkToken(string $rawPayload, int $chatId): ?User
    {
        if (!str_starts_with($rawPayload, self::PAYLOAD_PREFIX)) {
            return null;
        }
        $token = substr($rawPayload, strlen(self::PAYLOAD_PREFIX));
        if ($token === '') {
            return null;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['telegramLinkToken' => $token]);
        if ($user === null) {
            return null;
        }

        $expiresAt = $user->getTelegramLinkTokenExpiresAt();
        if ($expiresAt === null || $expiresAt < new \DateTimeImmutable()) {
            // Token expired — clear it and refuse
            $user->setTelegramLinkToken(null);
            $user->setTelegramLinkTokenExpiresAt(null);
            $this->em->flush();
            return null;
        }

        $user->setTelegramChatId($chatId);
        $user->setTelegramLinkToken(null);
        $user->setTelegramLinkTokenExpiresAt(null);
        $this->em->flush();

        return $user;
    }

    public function unlink(User $user): void
    {
        $user->setTelegramChatId(null);
        $user->setTelegramLinkToken(null);
        $user->setTelegramLinkTokenExpiresAt(null);
        $this->em->flush();
    }
}
