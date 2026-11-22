<?php

declare(strict_types=1);

namespace App\Service\Buyer;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guest orders (placed without an account) move into the cabinet of the account that
 * has PROVEN the same contact, the first time that account opens its orders:
 *  - a phone verified by Telegram: the bot user of the account's own Telegram identity
 *    (client_user.telegram_chat_id — set by the Telegram login or the profile link
 *    token) shared their OWN contact with the bot (SaveContactCommand / checkout);
 *  - a confirmed email equal to the email typed at the guest checkout.
 *
 * No account is created and nobody is messaged: the order keeps its phone, and any
 * account that later proves that phone (or email) gets it. A typed-in, unverified
 * profile phone is not enough — otherwise anyone could read a stranger's orders by
 * registering with their number.
 *
 * Only orders from the guest checkout era (LINK_SINCE) are linked; older guest rows
 * stay as they are.
 */
class GuestOrderLinker
{
    /** Guest one-page checkout went live on 22.11.2026; older guest rows are left alone. */
    public const LINK_SINCE = '2026-11-22 00:00:00';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Links the guest orders of this account's verified phone(s) / confirmed email to it.
     *
     * @return int how many orders were linked now
     */
    public function claimFor(User $user): int
    {
        $phoneKeys = $this->verifiedPhoneKeys($user);
        $email = $this->confirmedEmail($user);
        if ([] === $phoneKeys && null === $email) {
            return 0;
        }

        $conditions = [];
        $params = ['userId' => $user->getId(), 'since' => self::LINK_SINCE];
        $types = [];
        if ([] !== $phoneKeys) {
            $conditions[] = PhoneKey::sql('o.phone').' IN (:phoneKeys)';
            $params['phoneKeys'] = $phoneKeys;
            $types['phoneKeys'] = ArrayParameterType::STRING;
        }
        if (null !== $email) {
            $conditions[] = 'LOWER(o.contact_email) = :email';
            $params['email'] = $email;
        }

        $connection = $this->em->getConnection();
        $ids = $connection->fetchFirstColumn(
            'SELECT o.id FROM user_order o
             WHERE o.client_user_id IS NULL AND o.telegram_user_id IS NULL
               AND o.created_at >= :since
               AND ('.implode(' OR ', $conditions).')',
            $params,
            $types,
        );
        if ([] === $ids) {
            return 0;
        }

        $linked = $connection->executeStatement(
            'UPDATE user_order SET client_user_id = :userId, updated_at = NOW()
             WHERE id IN (:ids) AND client_user_id IS NULL AND telegram_user_id IS NULL',
            ['userId' => $user->getId(), 'ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        if ($linked > 0) {
            $this->logger->info('guest orders linked to the account', ['user_id' => $user->getId(), 'order_ids' => $ids]);
            // The account's order collection may already be loaded in this request.
            $this->em->refresh($user);
        }

        return $linked;
    }

    /** @return list<string> phone keys verified through the account's own Telegram identity */
    public function verifiedPhoneKeys(User $user): array
    {
        if (null === $user->getTelegramChatId()) {
            return [];
        }
        $phones = $this->em->getConnection()->fetchFirstColumn(
            'SELECT phone_number FROM telegram_user WHERE telegram_id = :telegramId AND phone_number IS NOT NULL',
            ['telegramId' => (string) $user->getTelegramChatId()],
        );

        return array_values(array_unique(array_filter(array_map(PhoneKey::of(...), $phones))));
    }

    private function confirmedEmail(User $user): ?string
    {
        $email = mb_strtolower(trim($user->getEmail()));
        if (!$user->isEmailConfirmed() || '' === $email || str_ends_with($email, '@telegram.local')) {
            return null;
        }

        return $email;
    }
}
