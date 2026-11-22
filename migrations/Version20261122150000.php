<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * telegram_user.phone_verified_at: set when the phone comes from the user's OWN contact
 * (checked since 22.11.2026). Existing phones stay NULL = not trusted for showing guest
 * orders in a cabinet (GuestOrderLinker); the user verifies by sharing the contact again.
 */
final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_user.phone_verified_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD phone_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN telegram_user.phone_verified_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP phone_verified_at');
    }
}
