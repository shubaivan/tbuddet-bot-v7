<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214062516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE shopping_cart_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE user_merge_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE shopping_cart (id INT NOT NULL, telegram_user_id INT DEFAULT NULL, user_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_72AAD4F6FC28B263 ON shopping_cart (telegram_user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_72AAD4F6A76ED395 ON shopping_cart (user_id)');
        $this->addSql('CREATE TABLE user_merge (id INT NOT NULL, telegram_user_id INT DEFAULT NULL, user_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9AB6C01FC28B263 ON user_merge (telegram_user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9AB6C01A76ED395 ON user_merge (user_id)');
        $this->addSql('ALTER TABLE shopping_cart ADD CONSTRAINT FK_72AAD4F6FC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shopping_cart ADD CONSTRAINT FK_72AAD4F6A76ED395 FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_merge ADD CONSTRAINT FK_9AB6C01FC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_merge ADD CONSTRAINT FK_9AB6C01A76ED395 FOREIGN KEY (user_id) REFERENCES client_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE shopping_cart_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE user_merge_id_seq CASCADE');
        $this->addSql('ALTER TABLE shopping_cart DROP CONSTRAINT FK_72AAD4F6FC28B263');
        $this->addSql('ALTER TABLE shopping_cart DROP CONSTRAINT FK_72AAD4F6A76ED395');
        $this->addSql('ALTER TABLE user_merge DROP CONSTRAINT FK_9AB6C01FC28B263');
        $this->addSql('ALTER TABLE user_merge DROP CONSTRAINT FK_9AB6C01A76ED395');
        $this->addSql('DROP TABLE shopping_cart');
        $this->addSql('DROP TABLE user_merge');
    }
}
