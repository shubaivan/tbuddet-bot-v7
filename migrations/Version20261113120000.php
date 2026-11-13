<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product search for the storefront search box.
 *
 * - common_fts is rebuilt with the 'simple' config (the DB default was
 *   'english', which only stems English and leaves Ukrainian words alone
 *   anyway; 'simple' keeps both languages predictable, word-form matching is
 *   done in App\Service\Search\ProductSearchQuery).
 * - GIN index on common_fts (there was none: every search was a seq scan).
 * - pg_trgm + GIN trigram index on the product name for typo-tolerant
 *   fallback ("вазн", "півсфра"). pg_trgm is a trusted extension (PG 13+),
 *   so the DB owner role can create it without superuser.
 *
 * Both indexes are expression/tsvector indexes Doctrine cannot map; a future
 * `make:migration` diff that proposes dropping them must be edited out.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product search: simple-config tsvector, GIN index on common_fts, pg_trgm index on product name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION product_ts_trigger() RETURNS trigger AS $$
begin
  new.common_fts :=
     setweight(to_tsvector('simple', coalesce(new.product_name, '{}'::jsonb)), 'A') ||
     setweight(to_tsvector('simple', coalesce(new.product_properties, '[]'::jsonb)), 'B');
  return new;
end
$$ LANGUAGE plpgsql
SQL);

        // Re-run the trigger on every row so existing vectors use the new config.
        $this->addSql('UPDATE product SET common_fts = common_fts');

        $this->addSql('CREATE INDEX IF NOT EXISTS product_common_fts_gin ON product USING GIN (common_fts)');
        $this->addSql('CREATE INDEX IF NOT EXISTS product_name_trgm ON product USING GIN (lower(product_name::text) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS product_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS product_common_fts_gin');

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION product_ts_trigger() RETURNS trigger AS $$
begin
  new.common_fts :=
     setweight(to_tsvector(coalesce(new.product_name,'{}')), 'A') ||
     setweight(to_tsvector(coalesce(new.product_properties,'{}')), 'B');
  return new;
end
$$ LANGUAGE plpgsql
SQL);
        $this->addSql('UPDATE product SET common_fts = common_fts');
        // pg_trgm is left installed on purpose: dropping an extension is not worth the risk.
    }
}
