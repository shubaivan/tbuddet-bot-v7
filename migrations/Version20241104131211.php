<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241104131211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add full text search opportunity for product_name and product_properties field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD common_fts tsvector NOT NULL');
        $this->addSql('DROP TRIGGER IF EXISTS tsvectorupdate ON product');
        $this->addSql('DROP FUNCTION IF EXISTS product_ts_trigger');
        $this->addSql('
        CREATE FUNCTION product_ts_trigger() RETURNS trigger AS $$
begin
  new.common_fts :=
     setweight(to_tsvector(coalesce(new.product_name,\'\')), \'A\') ||	 
     setweight(to_tsvector(coalesce(new.product_properties,\'{}\')), \'B\');
  return new;
end
$$ LANGUAGE plpgsql
        ');
        $this->addSql('
        CREATE TRIGGER tsvectorupdate BEFORE INSERT OR UPDATE
    ON product FOR EACH ROW EXECUTE FUNCTION product_ts_trigger()
        ');


    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS tsvectorupdate ON product');
        $this->addSql('DROP FUNCTION IF EXISTS product_ts_trigger');

    }
}
