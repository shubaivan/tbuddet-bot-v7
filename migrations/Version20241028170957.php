<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241028170957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'category_relation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE category_relation_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE category_relation (id INT NOT NULL, parent_id INT DEFAULT NULL, child_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D5F3C332727ACA70 ON category_relation (parent_id)');
        $this->addSql('CREATE INDEX IDX_D5F3C332DD62C21B ON category_relation (child_id)');
        $this->addSql('ALTER TABLE category_relation ADD CONSTRAINT FK_D5F3C332727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE category_relation ADD CONSTRAINT FK_D5F3C332DD62C21B FOREIGN KEY (child_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT FK_C6C816ACA76ED395');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT FK_C6C816ACD60322AC');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT FK_C6C816ACA76ED395 FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT FK_C6C816ACD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE category_relation_id_seq CASCADE');
        $this->addSql('ALTER TABLE category_relation DROP CONSTRAINT FK_D5F3C332727ACA70');
        $this->addSql('ALTER TABLE category_relation DROP CONSTRAINT FK_D5F3C332DD62C21B');
        $this->addSql('DROP TABLE category_relation');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT fk_c6c816aca76ed395');
        $this->addSql('ALTER TABLE client_user_role DROP CONSTRAINT fk_c6c816acd60322ac');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT fk_c6c816aca76ed395 FOREIGN KEY (user_id) REFERENCES client_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_user_role ADD CONSTRAINT fk_c6c816acd60322ac FOREIGN KEY (role_id) REFERENCES roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
