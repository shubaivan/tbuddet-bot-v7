<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250126135815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ALTER category_name TYPE jsonb USING category_name::jsonb');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ALTER category_name TYPE VARCHAR(255)');
    }
}
