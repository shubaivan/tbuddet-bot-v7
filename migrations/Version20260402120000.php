<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email confirmation fields to client_user, make password nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user ADD confirmation_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE client_user ADD confirmation_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE client_user ADD is_email_confirmed BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE client_user ALTER password DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user DROP COLUMN confirmation_token');
        $this->addSql('ALTER TABLE client_user DROP COLUMN confirmation_token_expires_at');
        $this->addSql('ALTER TABLE client_user DROP COLUMN is_email_confirmed');
        $this->addSql('ALTER TABLE client_user ALTER password SET NOT NULL');
    }
}
