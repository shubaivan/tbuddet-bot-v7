<?php

declare(strict_types=1);

namespace App\Service\Buyer;

use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a buyer has already done, across every identity of the same person: the web
 * account, the Telegram bot user linked to it, and the phone number (a guest is the
 * phone they typed; an account is also every phone stored on it or on its linked
 * Telegram user).
 *
 * Used only to RESTRICT discounts (first-order codes, per-buyer promocode limits), so
 * matching is deliberately broad: an extra match can only cost a discount, it never
 * shows anyone else's data. Showing orders in a cabinet uses the strict rules of
 * {@see GuestOrderLinker} instead.
 */
class BuyerHistory
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Has this buyer placed an order before? An order counts once it is paid (LiqPay
     * success or a confirmed bank transfer) or once an invoice was issued for it and
     * not cancelled. Abandoned card attempts do not count — the buyer may come back.
     */
    public function hasPreviousOrders(?User $user, ?TelegramUser $telegramUser, ?string $phone): bool
    {
        $identity = $this->identity($user, $telegramUser, $phone);
        if ($identity->isEmpty()) {
            return false;
        }

        [$match, $params, $types] = $identity->orderMatchSql('o', 'ou', 'ot');

        $sql = <<<SQL
            SELECT COUNT(*)
            FROM user_order o
            LEFT JOIN client_user ou ON ou.id = o.client_user_id
            LEFT JOIN telegram_user ot ON ot.id = o.telegram_user_id
            WHERE (o.liq_pay_status = 'success'
                   OR o.paid_at IS NOT NULL
                   OR (o.payment_method = 'invoice' AND o.order_status <> 'cancelled'))
              AND ({$match})
            SQL;

        return (int) $this->connection()->fetchOne($sql, $params, $types) > 0;
    }

    /**
     * How many times this buyer (any of their identities) has redeemed the code. A
     * redemption belongs to the buyer when it was made by their account, their bot
     * user, with their phone as a guest, or on an order placed with their phone.
     */
    public function countRedemptions(Promocode $promocode, ?User $user, ?TelegramUser $telegramUser, ?string $phone): int
    {
        $identity = $this->identity($user, $telegramUser, $phone);
        if ($identity->isEmpty()) {
            return 0;
        }

        [$match, $params, $types] = $identity->orderMatchSql('o', 'ou', 'ot');
        [$redemptionMatch, $redemptionParams, $redemptionTypes] = $identity->redemptionMatchSql('r', 'ru', 'rt');

        $sql = <<<SQL
            SELECT COUNT(*)
            FROM promocode_redemption r
            LEFT JOIN client_user ru ON ru.id = r.user_id
            LEFT JOIN telegram_user rt ON rt.id = r.telegram_user_id
            LEFT JOIN user_order o ON o.id = r.user_order_id
            LEFT JOIN client_user ou ON ou.id = o.client_user_id
            LEFT JOIN telegram_user ot ON ot.id = o.telegram_user_id
            WHERE r.promocode_id = :promocodeId
              AND (({$redemptionMatch}) OR (o.id IS NOT NULL AND ({$match})))
            SQL;

        return (int) $this->connection()->fetchOne(
            $sql,
            ['promocodeId' => $promocode->getId()] + $params + $redemptionParams,
            $types + $redemptionTypes,
        );
    }

    /**
     * Every identity of the buyer: the account and the bot users linked to it (and
     * vice versa) and all their phone keys.
     */
    public function identity(?User $user, ?TelegramUser $telegramUser, ?string $phone): BuyerIdentity
    {
        $userIds = [];
        $telegramUserIds = [];
        $phoneKeys = [];

        if (null !== $user) {
            $userIds[] = $user->getId();
            $phoneKeys[] = PhoneKey::of($user->getPhone());
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT t.id, t.phone_number FROM telegram_user t
                 WHERE t.id IN (SELECT m.telegram_user_id FROM user_merge m WHERE m.user_id = :uid)
                    OR (:chatId <> \'\' AND t.telegram_id = :chatId)',
                ['uid' => $user->getId(), 'chatId' => (string) ($user->getTelegramChatId() ?? '')],
            );
            foreach ($rows as $row) {
                $telegramUserIds[] = (int) $row['id'];
                $phoneKeys[] = PhoneKey::of($row['phone_number']);
            }
        }

        if (null !== $telegramUser && null !== $telegramUser->getId()) {
            $telegramUserIds[] = $telegramUser->getId();
            $phoneKeys[] = PhoneKey::of($telegramUser->getPhoneNumber());
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT u.id, u.phone FROM client_user u
                 WHERE u.id IN (SELECT m.user_id FROM user_merge m WHERE m.telegram_user_id = :tid)
                    OR u.telegram_chat_id::text = :telegramId',
                ['tid' => $telegramUser->getId(), 'telegramId' => (string) $telegramUser->getTelegramId()],
            );
            foreach ($rows as $row) {
                $userIds[] = (int) $row['id'];
                $phoneKeys[] = PhoneKey::of($row['phone']);
            }
        }

        $phoneKeys[] = PhoneKey::of($phone);

        return new BuyerIdentity(
            array_values(array_unique(array_filter($userIds))),
            array_values(array_unique(array_filter($telegramUserIds))),
            array_values(array_unique(array_filter($phoneKeys))),
        );
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
