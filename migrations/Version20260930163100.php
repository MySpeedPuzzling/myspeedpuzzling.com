<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trigram index for the player search.
 *
 * SearchPlayers::fulltext() (header search on every keystroke, the co-puzzler picker, player autocomplete,
 * the players page) matched `LIKE '%...%'` on name and code, with and without diacritics, by scanning the
 * whole player table and calling unaccent() on every row: 23-44 ms on production (2026-09-30) whatever the
 * search, 11-14k calls a day. A search of 3+ characters now asks the same four expressions this index
 * holds - `immutable_unaccent()` is the IMMUTABLE wrapper index expressions need, same results as
 * unaccent(). On a local copy of production's players: "jan" 12.6 -> 0.5 ms, "ann" 14.4 -> 1.7 ms,
 * "mar" 13.1 -> 1.4 ms, "šár" 14.1 -> 0.8 ms, "petra" 12.8 -> 0.2 ms. Searches of 1-2 characters cannot
 * use a trigram index; they keep the plain unaccent() scan, which is faster per row than the wrapper.
 *
 * One multicolumn GIN index instead of four: the planner ORs four bitmap scans of it.
 *
 * Custom (Doctrine cannot manage GIN trigram expression indexes): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for it, and tests/bootstrap.php
 * mirrors it. Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the player table is ~11k rows / 5 MB, the build
 * takes 0.11-0.14 s on a local copy of production's size (5.5 MB of index).
 */
final class Version20260930163100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trigram index for the player search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_player_search_trgm ON player USING GIN (LOWER(name) gin_trgm_ops, LOWER(code) gin_trgm_ops, LOWER(immutable_unaccent(name)) gin_trgm_ops, LOWER(immutable_unaccent(code)) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_player_search_trgm');
    }
}
