<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QR / offline campaigns, step 1: the campaign itself, every scan of its QR
 * (/q/{slug}) and the anonymous visitor a scan creates (first-touch cookie).
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'campaign, qr_scan, campaign_visitor (QR scan tracking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE campaign_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("
            CREATE TABLE campaign (
                id INT NOT NULL,
                slug VARCHAR(32) NOT NULL,
                name VARCHAR(120) NOT NULL DEFAULT '',
                promocode_id INT DEFAULT NULL,
                target_path VARCHAR(255) NOT NULL DEFAULT '/uk/',
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                attribution_days INT NOT NULL DEFAULT 60,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        ");
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_slug ON campaign (slug)');
        $this->addSql('CREATE INDEX idx_campaign_promocode ON campaign (promocode_id)');
        $this->addSql('ALTER TABLE campaign ADD CONSTRAINT fk_campaign_promocode FOREIGN KEY (promocode_id) REFERENCES promocode (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON TABLE campaign IS 'Offline/QR campaign: the QR points at /q/{slug}'");
        $this->addSql("COMMENT ON COLUMN campaign.slug IS 'Lower-case id used in the QR URL /q/{slug}'");
        $this->addSql("COMMENT ON COLUMN campaign.target_path IS 'Site path the scan redirects to (on FRONTEND_URL)'");
        $this->addSql("COMMENT ON COLUMN campaign.attribution_days IS 'First-touch window: visitor actions within N days of the first scan count for the campaign'");

        $this->addSql('CREATE TABLE campaign_visitor (
                visitor_id VARCHAR(32) NOT NULL,
                campaign_id INT NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                user_id INT DEFAULT NULL,
                linked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(visitor_id)
            )');
        $this->addSql('CREATE INDEX idx_campaign_visitor_campaign ON campaign_visitor (campaign_id)');
        $this->addSql('CREATE INDEX idx_campaign_visitor_user ON campaign_visitor (user_id)');
        $this->addSql('ALTER TABLE campaign_visitor ADD CONSTRAINT fk_campaign_visitor_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE campaign_visitor ADD CONSTRAINT fk_campaign_visitor_user FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON TABLE campaign_visitor IS 'Anonymous visitor created by a QR scan (cookie abm_vid); campaign = first touch'");
        $this->addSql("COMMENT ON COLUMN campaign_visitor.user_id IS 'Shop account the visitor later registered/logged in as'");
        $this->addSql("COMMENT ON COLUMN campaign_visitor.first_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN campaign_visitor.last_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN campaign_visitor.linked_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE SEQUENCE qr_scan_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("
            CREATE TABLE qr_scan (
                id INT NOT NULL,
                campaign_id INT DEFAULT NULL,
                slug VARCHAR(64) NOT NULL DEFAULT '',
                visitor_id VARCHAR(32) DEFAULT NULL,
                scanned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                ip_anonymized BOOLEAN NOT NULL DEFAULT FALSE,
                user_agent VARCHAR(512) NOT NULL DEFAULT '',
                referer VARCHAR(512) DEFAULT NULL,
                accept_language VARCHAR(64) DEFAULT NULL,
                is_bot BOOLEAN NOT NULL DEFAULT FALSE,
                bot_reason VARCHAR(32) DEFAULT NULL,
                is_repeat BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY(id)
            )
        ");
        $this->addSql('CREATE INDEX idx_qr_scan_campaign_time ON qr_scan (campaign_id, scanned_at)');
        $this->addSql('CREATE INDEX idx_qr_scan_visitor ON qr_scan (visitor_id)');
        $this->addSql('CREATE INDEX idx_qr_scan_retention ON qr_scan (ip_anonymized, scanned_at)');
        $this->addSql('ALTER TABLE qr_scan ADD CONSTRAINT fk_qr_scan_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON TABLE qr_scan IS 'One row per hit on /q/{slug}, bots included (is_bot)'");
        $this->addSql("COMMENT ON COLUMN qr_scan.slug IS 'Slug as requested (kept even when no campaign matched)'");
        $this->addSql("COMMENT ON COLUMN qr_scan.ip IS 'Client IP; last octet / IPv6 tail zeroed after the retention period'");
        $this->addSql("COMMENT ON COLUMN qr_scan.is_repeat IS 'Same visitor cookie scanned before'");
        $this->addSql("COMMENT ON COLUMN qr_scan.scanned_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE qr_scan');
        $this->addSql('DROP SEQUENCE qr_scan_id_seq');
        $this->addSql('DROP TABLE campaign_visitor');
        $this->addSql('DROP TABLE campaign');
        $this->addSql('DROP SEQUENCE campaign_id_seq');
    }
}
