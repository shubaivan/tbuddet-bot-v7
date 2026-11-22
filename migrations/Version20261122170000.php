<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes bot-user ↔ web-account links (user_merge) that do not belong to the same
 * Telegram user: they were made by the old phone match in TelegramUserService::savePhone
 * (a typed-in profile phone) or left behind by an unlink. Through such a link an
 * account could see another person's bot orders and the Telegram login could land in
 * it. Links made by the Telegram login / profile link (same Telegram id) stay.
 * Prod 22.11: one row (user 16 ↔ telegram_user 7, no bot orders). Not reversible —
 * the pre-deploy DB backup has the rows.
 */
final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove user_merge rows whose account is not the same Telegram user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM user_merge m
            USING client_user u, telegram_user t
            WHERE u.id = m.user_id AND t.id = m.telegram_user_id
              AND (u.telegram_chat_id IS NULL OR u.telegram_chat_id::text <> t.telegram_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Data clean-up: restore from the pre-deploy backup if ever needed.
    }
}
