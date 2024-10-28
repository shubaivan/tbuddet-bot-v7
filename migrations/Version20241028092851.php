<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241028092851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_63540594584665A');
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_635405912469DE2');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_63540594584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_635405912469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files DROP CONSTRAINT fk_63540594584665a');
        $this->addSql('ALTER TABLE files DROP CONSTRAINT fk_635405912469de2');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT fk_63540594584665a FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT fk_635405912469de2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
