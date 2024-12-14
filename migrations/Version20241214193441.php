<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214193441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_product ADD user_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_product ADD CONSTRAINT FK_C890CED46D128938 FOREIGN KEY (user_order_id) REFERENCES user_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_C890CED46D128938 ON purchase_product (user_order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_product DROP CONSTRAINT FK_C890CED46D128938');
        $this->addSql('DROP INDEX IDX_C890CED46D128938');
        $this->addSql('ALTER TABLE purchase_product DROP user_order_id');
    }
}
