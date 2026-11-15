<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paid city delivery (Черкаси): delivery method, courier address and the
 * delivery fee as a separate snapshot line of the order (already in total_amount).
 */
final class Version20261115180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_order: delivery_method, delivery_address, delivery_amount (city delivery)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_order ADD delivery_method VARCHAR(20) DEFAULT NULL");
        $this->addSql("ALTER TABLE user_order ADD delivery_address VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE user_order ADD delivery_amount INT DEFAULT 0 NOT NULL");
        $this->addSql("COMMENT ON COLUMN user_order.delivery_method IS 'nova_poshta | pickup | city_courier; NULL = legacy'");
        $this->addSql("COMMENT ON COLUMN user_order.delivery_address IS 'Courier address for city delivery'");
        $this->addSql("COMMENT ON COLUMN user_order.delivery_amount IS 'Delivery fee, UAH, included in total_amount'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP delivery_method');
        $this->addSql('ALTER TABLE user_order DROP delivery_address');
        $this->addSql('ALTER TABLE user_order DROP delivery_amount');
    }
}
