<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add delivery_carrier column to user_order. Defaults all existing rows to
 * 'nova_poshta' (the only carrier we have integrated today) so the upgrade is
 * backwards-safe. Future carriers (ukrposhta, meest, justin, pickup) will set
 * this field explicitly at checkout once their UIs land.
 */
final class Version20260525130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_carrier column to user_order (default nova_poshta)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_order ADD COLUMN delivery_carrier VARCHAR(20) DEFAULT 'nova_poshta' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP COLUMN delivery_carrier');
    }
}
