<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guest cart checkout: a buyer without an account leaves a name (and optionally an
 * email) next to the phone. Both nullable — account and bot orders keep NULL.
 * The guest cart lines are purchase_product rows with shopping_cart_id NULL
 * (the column has always been nullable), so no change there.
 */
final class Version20261121200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_order.contact_name / contact_email (guest cart checkout)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order ADD contact_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD contact_email VARCHAR(180) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN user_order.contact_name IS 'Guest checkout (no account): name typed by the buyer'");
        $this->addSql("COMMENT ON COLUMN user_order.contact_email IS 'Guest checkout (no account): optional email typed by the buyer'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP contact_name');
        $this->addSql('ALTER TABLE user_order DROP contact_email');
    }
}
