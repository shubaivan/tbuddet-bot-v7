<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_order.currency: the currency an order is priced in (UAH / USD), so a LiqPay
 * payment is accepted only in that currency and for that total. Nullable — rows
 * placed before this column fall back to UserOrder::getExpectedPaymentCurrency().
 */
final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_order.currency (expected LiqPay payment currency)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order ADD currency VARCHAR(3) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN user_order.currency IS 'Currency the order total is priced in: UAH or USD; NULL for orders placed before 2026-11-22'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP currency');
    }
}
