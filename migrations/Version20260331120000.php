<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Nova Poshta delivery fields to user_order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order ADD delivery_city VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD delivery_city_ref VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD delivery_department VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_order ADD delivery_department_ref VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_order DROP COLUMN delivery_city');
        $this->addSql('ALTER TABLE user_order DROP COLUMN delivery_city_ref');
        $this->addSql('ALTER TABLE user_order DROP COLUMN delivery_department');
        $this->addSql('ALTER TABLE user_order DROP COLUMN delivery_department_ref');
    }
}
