<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public «first order» codes (promocode.purpose = 'welcome'): WELCOME10 (10%, UAH)
 * becomes first-order-only, and WELCOME10USD (10%, USD) is added for the English
 * site. The guest checkout offers them once the phone has no orders behind it;
 * PromocodeService refuses them to a buyer who has ordered before. Data only —
 * purpose is a VARCHAR(20), no schema change.
 */
final class Version20261122140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WELCOME10 / WELCOME10USD: public first-order codes (purpose welcome)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE promocode SET purpose = 'welcome', updated_at = NOW() WHERE code = 'WELCOME10' AND purpose = 'generic'");
        $this->addSql(<<<'SQL'
            INSERT INTO promocode (id, code, discount_type, value, currency, times_used, is_active, purpose, created_at)
            SELECT nextval('promocode_id_seq'), 'WELCOME10USD', 'percent', 10, 'USD', 0, true, 'welcome', NOW()
            WHERE NOT EXISTS (SELECT 1 FROM promocode WHERE code = 'WELCOME10USD')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM promocode WHERE code = 'WELCOME10USD' AND times_used = 0");
        $this->addSql("UPDATE promocode SET purpose = 'generic' WHERE code = 'WELCOME10' AND purpose = 'welcome'");
    }
}
