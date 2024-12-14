<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214083038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE purchase_product_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE purchase_product (id INT NOT NULL, shopping_cart_id INT DEFAULT NULL, product_id INT DEFAULT NULL, product_properties JSON DEFAULT NULL, quantity INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C890CED445F80CD ON purchase_product (shopping_cart_id)');
        $this->addSql('CREATE INDEX IDX_C890CED44584665A ON purchase_product (product_id)');
        $this->addSql('ALTER TABLE purchase_product ADD CONSTRAINT FK_C890CED445F80CD FOREIGN KEY (shopping_cart_id) REFERENCES shopping_cart (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_product ADD CONSTRAINT FK_C890CED44584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE purchase_product_id_seq CASCADE');
        $this->addSql('ALTER TABLE purchase_product DROP CONSTRAINT FK_C890CED445F80CD');
        $this->addSql('ALTER TABLE purchase_product DROP CONSTRAINT FK_C890CED44584665A');
        $this->addSql('DROP TABLE purchase_product');
        $this->addSql('ALTER TABLE product ADD common_fts TEXT NOT NULL');
    }
}
