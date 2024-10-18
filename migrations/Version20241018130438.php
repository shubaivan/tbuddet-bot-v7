<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241018130438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_order DROP CONSTRAINT FK_17EB68C0FC28B263');
        $this->addSql('ALTER TABLE user_order ADD client_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD CONSTRAINT FK_17EB68C0F55397E8 FOREIGN KEY (client_user_id) REFERENCES client_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_order ADD CONSTRAINT FK_17EB68C0FC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_17EB68C0F55397E8 ON user_order (client_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP CONSTRAINT FK_17EB68C0F55397E8');
        $this->addSql('ALTER TABLE user_order DROP CONSTRAINT fk_17eb68c0fc28b263');
        $this->addSql('DROP INDEX IDX_17EB68C0F55397E8');
        $this->addSql('ALTER TABLE user_order DROP client_user_id');
        $this->addSql('ALTER TABLE user_order ADD CONSTRAINT fk_17eb68c0fc28b263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
