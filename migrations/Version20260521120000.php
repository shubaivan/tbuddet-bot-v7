<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add purpose to promocode — distinguishes hand-issued/campaign codes (GENERIC)
 * from auto-generated personal "first order" codes (FIRST_ORDER).
 */
final class Version20260521120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purpose column to promocode (generic / first_order)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE promocode ADD COLUMN purpose VARCHAR(20) DEFAULT 'generic' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promocode DROP COLUMN purpose');
    }
}
