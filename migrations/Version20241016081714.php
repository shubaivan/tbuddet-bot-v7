<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241016081714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE client_user_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE client_user_role_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE client_user (id INT NOT NULL, uuid VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, phone VARCHAR(255) DEFAULT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C0F152BD17F50A6 ON client_user (uuid)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C0F152BE7927C74 ON client_user (email)');
        $this->addSql('CREATE TABLE client_user_role (id INT NOT NULL, user_id INT DEFAULT NULL, role_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C6C816ACA76ED395 ON client_user_role (user_id)');
        $this->addSql('CREATE INDEX IDX_C6C816ACD60322AC ON client_user_role (role_id)');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT FK_C6C816ACA76ED395 FOREIGN KEY (user_id) REFERENCES client_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT FK_C6C816ACD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE files ALTER created_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE client_user_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE client_user_role_id_seq CASCADE');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT FK_C6C816ACA76ED395');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT FK_C6C816ACD60322AC');
        $this->addSql('DROP TABLE client_user');
        $this->addSql('DROP TABLE client_user_role');
        $this->addSql('ALTER TABLE files ALTER created_at DROP NOT NULL');
    }
}
