<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order_status, nova_poshta_tracking_number to user_order; add ROLE_MANAGER role';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_order ADD COLUMN order_status VARCHAR(50) NOT NULL DEFAULT 'new'");
        $this->addSql('ALTER TABLE user_order ADD COLUMN nova_poshta_tracking_number VARCHAR(255) DEFAULT NULL');
        $this->addSql("INSERT INTO roles (id, name, description, created_at) VALUES (3, 'ROLE_MANAGER', 'manager', NOW()) ON CONFLICT DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP COLUMN order_status');
        $this->addSql('ALTER TABLE user_order DROP COLUMN nova_poshta_tracking_number');
        $this->addSql("DELETE FROM roles WHERE name = 'ROLE_MANAGER'");
    }
}
