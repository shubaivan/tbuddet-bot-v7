<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add canceled_at to promocode_redemption — supports the order-cancellation
 * release flow (decrement Promocode.times_used while preserving the ledger row).
 */
final class Version20260519130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add canceled_at to promocode_redemption for cancel-release';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promocode_redemption ADD COLUMN canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promocode_redemption DROP COLUMN canceled_at');
    }
}
