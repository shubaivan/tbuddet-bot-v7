<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token fields to client_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user ADD reset_password_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE client_user ADD reset_password_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user DROP COLUMN reset_password_token');
        $this->addSql('ALTER TABLE client_user DROP COLUMN reset_password_token_expires_at');
    }
}
