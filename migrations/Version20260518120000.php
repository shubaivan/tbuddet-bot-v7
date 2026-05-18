<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'promocode: create promocode + promocode_redemption tables, add discount snapshot columns to user_order';
    }

    public function up(Schema $schema): void
    {
        // -- promocode ----------------------------------------------------
        $this->addSql('CREATE SEQUENCE promocode_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('
            CREATE TABLE promocode (
                id INT NOT NULL,
                code VARCHAR(32) NOT NULL,
                discount_type VARCHAR(20) NOT NULL,
                value INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                valid_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                valid_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                max_uses INT DEFAULT NULL,
                max_uses_per_user INT DEFAULT NULL,
                min_order_amount INT DEFAULT NULL,
                times_used INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                assigned_user_id INT DEFAULT NULL,
                assigned_telegram_user_id INT DEFAULT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                delivery_channel VARCHAR(16) DEFAULT NULL,
                created_by_admin_id INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_promocode_code ON promocode (code)');
        $this->addSql('CREATE INDEX idx_promocode_assigned_user ON promocode (assigned_user_id)');
        $this->addSql('CREATE INDEX idx_promocode_assigned_tg_user ON promocode (assigned_telegram_user_id)');
        $this->addSql('CREATE INDEX idx_promocode_active_valid_to ON promocode (is_active, valid_to)');
        $this->addSql('ALTER TABLE promocode ADD CONSTRAINT fk_promocode_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES client_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promocode ADD CONSTRAINT fk_promocode_assigned_tg_user FOREIGN KEY (assigned_telegram_user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promocode ADD CONSTRAINT fk_promocode_created_by_admin FOREIGN KEY (created_by_admin_id) REFERENCES client_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- promocode_redemption -----------------------------------------
        $this->addSql('CREATE SEQUENCE promocode_redemption_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('
            CREATE TABLE promocode_redemption (
                id INT NOT NULL,
                promocode_id INT NOT NULL,
                user_order_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                telegram_user_id INT DEFAULT NULL,
                guest_phone VARCHAR(20) DEFAULT NULL,
                discount_applied INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                redeemed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        // The UNIQUE constraint below is the atomicity guarantee — concurrent retried
        // POSTs that both try to apply the same promocode to the same order are caught
        // at the DB level. PromocodeService::redeem() treats the collision as idempotent.
        $this->addSql('CREATE UNIQUE INDEX uniq_promocode_user_order ON promocode_redemption (promocode_id, user_order_id)');
        $this->addSql('CREATE INDEX idx_promo_redemption_user ON promocode_redemption (promocode_id, user_id)');
        $this->addSql('CREATE INDEX idx_promo_redemption_tg_user ON promocode_redemption (promocode_id, telegram_user_id)');
        $this->addSql('CREATE INDEX idx_promo_redemption_guest_phone ON promocode_redemption (promocode_id, guest_phone)');
        $this->addSql('ALTER TABLE promocode_redemption ADD CONSTRAINT fk_redemption_promocode FOREIGN KEY (promocode_id) REFERENCES promocode (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promocode_redemption ADD CONSTRAINT fk_redemption_user_order FOREIGN KEY (user_order_id) REFERENCES user_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promocode_redemption ADD CONSTRAINT fk_redemption_user FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promocode_redemption ADD CONSTRAINT fk_redemption_tg_user FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- user_order: discount snapshot columns ------------------------
        // subtotal_amount is nullable: legacy orders pre-promocode-system have no subtotal recorded;
        // for them total_amount IS the subtotal (no discount was ever applied).
        $this->addSql('ALTER TABLE user_order ADD subtotal_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD discount_amount INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE user_order ADD promocode_code_used VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP COLUMN promocode_code_used');
        $this->addSql('ALTER TABLE user_order DROP COLUMN discount_amount');
        $this->addSql('ALTER TABLE user_order DROP COLUMN subtotal_amount');

        $this->addSql('DROP TABLE promocode_redemption');
        $this->addSql('DROP SEQUENCE promocode_redemption_id_seq CASCADE');

        $this->addSql('DROP TABLE promocode');
        $this->addSql('DROP SEQUENCE promocode_id_seq CASCADE');
    }
}
