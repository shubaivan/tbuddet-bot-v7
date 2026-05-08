<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Telegram link fields to client_user (chat id + opt-in token)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user ADD telegram_chat_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE client_user ADD telegram_link_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE client_user ADD telegram_link_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_client_user_telegram_link_token ON client_user (telegram_link_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_client_user_telegram_link_token');
        $this->addSql('ALTER TABLE client_user DROP COLUMN telegram_chat_id');
        $this->addSql('ALTER TABLE client_user DROP COLUMN telegram_link_token');
        $this->addSql('ALTER TABLE client_user DROP COLUMN telegram_link_token_expires_at');
    }
}
