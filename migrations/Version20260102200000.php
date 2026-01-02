<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom indexes for puzzle search optimization';
    }

    public function up(Schema $schema): void
    {
        // Enable pg_trgm extension for trigram indexes
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Create immutable wrapper for unaccent (required for index expressions)
        $this->addSql("
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text AS \$\$
                SELECT unaccent('unaccent', \$1)
            \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        ");

        // GIN trigram indexes for ILIKE with wildcards (Doctrine can't manage these)
        $this->addSql('CREATE INDEX custom_puzzle_name_trgm ON puzzle USING GIN (name gin_trgm_ops)');
        $this->addSql('CREATE INDEX custom_puzzle_alt_name_trgm ON puzzle USING GIN (alternative_name gin_trgm_ops)');

        // GIN trigram indexes for unaccent + ILIKE (accent-insensitive search)
        $this->addSql('CREATE INDEX custom_puzzle_name_unaccent_trgm ON puzzle USING GIN (immutable_unaccent(name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX custom_puzzle_alt_name_unaccent_trgm ON puzzle USING GIN (immutable_unaccent(alternative_name) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_alt_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_name_unaccent_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_alt_name_unaccent_trgm');
        $this->addSql('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
