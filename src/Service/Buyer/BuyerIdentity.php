<?php

declare(strict_types=1);

namespace App\Service\Buyer;

use Doctrine\DBAL\ArrayParameterType;

/**
 * All identities of one buyer (see {@see BuyerHistory::identity()}): account ids,
 * Telegram bot user ids and phone keys ({@see PhoneKey}).
 */
final class BuyerIdentity
{
    /**
     * @param list<int>    $userIds
     * @param list<int>    $telegramUserIds
     * @param list<string> $phoneKeys
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $telegramUserIds,
        public readonly array $phoneKeys,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->userIds && [] === $this->telegramUserIds && [] === $this->phoneKeys;
    }

    /**
     * SQL condition "this order belongs to the buyer" for `user_order {$o}` joined with
     * `client_user {$u}` (its account) and `telegram_user {$t}` (its bot user).
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    public function orderMatchSql(string $o, string $u, string $t): array
    {
        return $this->matchSql(
            "{$o}.client_user_id",
            "{$o}.telegram_user_id",
            [PhoneKey::sql("{$o}.phone"), PhoneKey::sql("{$u}.phone"), PhoneKey::sql("{$t}.phone_number")],
            'o',
        );
    }

    /**
     * The same for `promocode_redemption {$r}` joined with its account {$u} and bot user {$t}.
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    public function redemptionMatchSql(string $r, string $u, string $t): array
    {
        return $this->matchSql(
            "{$r}.user_id",
            "{$r}.telegram_user_id",
            [PhoneKey::sql("{$r}.guest_phone"), PhoneKey::sql("{$u}.phone"), PhoneKey::sql("{$t}.phone_number")],
            'r',
        );
    }

    /**
     * @param list<string> $phoneExpressions
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function matchSql(string $userColumn, string $telegramColumn, array $phoneExpressions, string $prefix): array
    {
        $parts = [];
        $params = [];
        $types = [];

        if ([] !== $this->userIds) {
            $parts[] = "{$userColumn} IN (:{$prefix}UserIds)";
            $params["{$prefix}UserIds"] = $this->userIds;
            $types["{$prefix}UserIds"] = ArrayParameterType::INTEGER;
        }
        if ([] !== $this->telegramUserIds) {
            $parts[] = "{$telegramColumn} IN (:{$prefix}TelegramUserIds)";
            $params["{$prefix}TelegramUserIds"] = $this->telegramUserIds;
            $types["{$prefix}TelegramUserIds"] = ArrayParameterType::INTEGER;
        }
        if ([] !== $this->phoneKeys) {
            foreach ($phoneExpressions as $expression) {
                $parts[] = "{$expression} IN (:{$prefix}PhoneKeys)";
            }
            $params["{$prefix}PhoneKeys"] = $this->phoneKeys;
            $types["{$prefix}PhoneKeys"] = ArrayParameterType::STRING;
        }

        return [[] === $parts ? 'FALSE' : implode(' OR ', $parts), $params, $types];
    }
}
