<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240924143522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE files_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE files (id INT NOT NULL, product_id INT DEFAULT NULL, path VARCHAR(255) NOT NULL, extension VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, size VARCHAR(255) NOT NULL, description TEXT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_63540594584665A ON files (product_id)');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_63540594584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE files_id_seq CASCADE');
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_63540594584665A');
        $this->addSql('DROP TABLE files');
    }
}
