<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'files: cache Telegram file_id per uploaded image to avoid URL refetches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files ADD telegram_file_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files DROP COLUMN telegram_file_id');
    }
}
