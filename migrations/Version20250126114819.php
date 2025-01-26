<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250126114819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ALTER product_name TYPE jsonb USING product_name::jsonb');
        $this->addSql('ALTER TABLE product ALTER description TYPE jsonb USING description::jsonb');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ALTER product_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE product ALTER description TYPE TEXT');
    }
}
