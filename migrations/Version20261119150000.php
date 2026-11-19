<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QR / offline campaigns, step 2: the funnel. campaign_event records what a
 * campaign visitor did (landed with JS, registered, logged in, added to cart,
 * ordered); user_order keeps a snapshot of the campaign the order belongs to.
 * "Paid" is read from the order itself (liq_pay_status / paid_at).
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'campaign_event + user_order.campaign_id/campaign_visitor_id/campaign_source (campaign funnel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE campaign_event_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("
            CREATE TABLE campaign_event (
                id INT NOT NULL,
                campaign_id INT NOT NULL,
                visitor_id VARCHAR(32) DEFAULT NULL,
                type VARCHAR(20) NOT NULL,
                user_id INT DEFAULT NULL,
                user_order_id INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ");
        $this->addSql('CREATE INDEX idx_campaign_event_campaign_type ON campaign_event (campaign_id, type)');
        $this->addSql('CREATE INDEX idx_campaign_event_visitor ON campaign_event (visitor_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_campaign_event_landing ON campaign_event (visitor_id) WHERE type = 'landing'");
        $this->addSql('ALTER TABLE campaign_event ADD CONSTRAINT fk_campaign_event_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE campaign_event ADD CONSTRAINT fk_campaign_event_user FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE campaign_event ADD CONSTRAINT fk_campaign_event_order FOREIGN KEY (user_order_id) REFERENCES user_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON TABLE campaign_event IS 'Funnel step of a campaign visitor: landing | register | login | add_to_cart | order'");
        $this->addSql("COMMENT ON COLUMN campaign_event.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE user_order ADD campaign_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD campaign_visitor_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD campaign_source VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_order_campaign ON user_order (campaign_id)');
        $this->addSql('ALTER TABLE user_order ADD CONSTRAINT fk_user_order_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN user_order.campaign_id IS 'Campaign the order is attributed to (first touch); NULL = none'");
        $this->addSql("COMMENT ON COLUMN user_order.campaign_visitor_id IS 'QR visitor id that placed the order (campaign_source = qr)'");
        $this->addSql("COMMENT ON COLUMN user_order.campaign_source IS 'qr = came via the QR within the window | promocode = used the campaign code without a QR visit'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP CONSTRAINT fk_user_order_campaign');
        $this->addSql('DROP INDEX idx_user_order_campaign');
        $this->addSql('ALTER TABLE user_order DROP campaign_id');
        $this->addSql('ALTER TABLE user_order DROP campaign_visitor_id');
        $this->addSql('ALTER TABLE user_order DROP campaign_source');
        $this->addSql('DROP TABLE campaign_event');
        $this->addSql('DROP SEQUENCE campaign_event_id_seq');
    }
}
