<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark auto-generated image variants (e.g. JPG fallbacks of AVIF originals)
 * as siblings of their parent file rather than separate top-level images.
 *
 * The AVIF→JPEG backfill on 2026-05-08 created 24 sibling Files rows that
 * showed up in the admin as "duplicate" photos next to the original AVIF.
 * Adding a self-referencing FK lets the admin list filter them out while
 * keeping them addressable for FE fallback rendering.
 */
final class Version20260508150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'files: add variant_of_id self-FK and link existing AVIF→JPG fallbacks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files ADD variant_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_files_variant_of FOREIGN KEY (variant_of_id) REFERENCES files (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_files_variant_of ON files (variant_of_id)');

        // Backfill: for every (product, base_path) pair where we have both an .avif
        // and a .jpg row, point the .jpg row at the .avif as its parent.
        $this->addSql(<<<'SQL'
            UPDATE files jpg
            SET variant_of_id = avif.id
            FROM files avif
            WHERE jpg.product_id = avif.product_id
              AND lower(jpg.extension) = 'jpg'
              AND lower(avif.extension) = 'avif'
              AND regexp_replace(jpg.path, '\.jpg$', '') = regexp_replace(avif.path, '\.avif$', '')
              AND jpg.variant_of_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_files_variant_of');
        $this->addSql('DROP INDEX IDX_files_variant_of');
        $this->addSql('ALTER TABLE files DROP COLUMN variant_of_id');
    }
}
