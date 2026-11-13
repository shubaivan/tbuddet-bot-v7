<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pay-by-invoice for legal entities: payment method, buyer requisites, invoice
 * number/dates/lines snapshot, manual paid_at/paid_by, and a sequence for
 * invoice numbering (gap-free enough for a shop, never reused).
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_order: payment_method, invoice number/payer/dates/lines, paid_at/paid_by; client_user.invoice_payer_profile; invoice_number_seq';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_order ADD payment_method VARCHAR(20) DEFAULT 'card' NOT NULL");
        $this->addSql('ALTER TABLE user_order ADD invoice_number VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD invoice_payer JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD invoice_issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD invoice_due_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD invoice_lines JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD paid_by VARCHAR(255) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN user_order.invoice_issued_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN user_order.invoice_due_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN user_order.paid_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_17EB68C02DA68207 ON user_order (invoice_number)');
        $this->addSql('ALTER TABLE client_user ADD invoice_payer_profile JSON DEFAULT NULL');
        // Not mapped by Doctrine; excluded from schema diffs via dbal.schema_filter.
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq INCREMENT BY 1 MINVALUE 1 START 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE IF EXISTS invoice_number_seq');
        $this->addSql('ALTER TABLE client_user DROP invoice_payer_profile');
        $this->addSql('DROP INDEX IF EXISTS UNIQ_17EB68C02DA68207');
        $this->addSql('ALTER TABLE user_order DROP payment_method, DROP invoice_number, DROP invoice_payer, '
            . 'DROP invoice_issued_at, DROP invoice_due_date, DROP invoice_lines, DROP paid_at, DROP paid_by');
    }
}
