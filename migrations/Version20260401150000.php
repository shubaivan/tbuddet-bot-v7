<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add preferred_language to telegram_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE telegram_user ADD preferred_language VARCHAR(10) NOT NULL DEFAULT 'ua'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP COLUMN preferred_language');
    }
}
