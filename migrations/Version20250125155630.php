<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250125155630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ALTER product_properties TYPE jsonb');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ALTER product_properties TYPE JSON');
    }
}
