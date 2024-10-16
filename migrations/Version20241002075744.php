<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241002075744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files ADD category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_635405912469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_635405912469DE2 ON files (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_635405912469DE2');
        $this->addSql('DROP INDEX IDX_635405912469DE2');
        $this->addSql('ALTER TABLE files DROP category_id');
    }
}
