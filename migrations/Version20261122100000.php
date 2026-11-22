<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_order.currency: the currency an order is priced in (UAH / USD), so a LiqPay
 * payment is accepted only in that currency and for that total. Existing rows are
 * backfilled (prod 22.11: 51/51); a row left NULL falls back to
 * UserOrder::getExpectedPaymentCurrency().
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
        $this->addSql("COMMENT ON COLUMN user_order.currency IS 'Currency the order total is priced in: UAH or USD'");
        // Backfill: invoices are always in hryvnia; card orders keep the currency of the
        // LiqPay request stored with them (invoice_send / status answer).
        $this->addSql("UPDATE user_order SET currency = 'UAH' WHERE payment_method = 'invoice'");
        $this->addSql(<<<'SQL'
            UPDATE user_order
            SET currency = substring(liq_pay_response from '"currency":"(UAH|USD)"')
            WHERE currency IS NULL
              AND substring(liq_pay_response from '"currency":"(UAH|USD)"') IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP currency');
    }
}
