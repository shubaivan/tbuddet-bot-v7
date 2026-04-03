<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make client_user.last_name nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_user ALTER COLUMN last_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE client_user SET last_name = '' WHERE last_name IS NULL");
        $this->addSql('ALTER TABLE client_user ALTER COLUMN last_name SET NOT NULL');
    }
}
