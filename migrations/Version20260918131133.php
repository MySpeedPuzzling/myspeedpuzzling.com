<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trigram indexes for the catalogue-number and EAN arms of the puzzle search.
 *
 * SearchPuzzle (header GlobalSearch, puzzle library search) matches
 * `identification_number ILIKE '%…%'` and `ean ILIKE '%…%'` next to the name
 * conditions. The name columns have had GIN trigram indexes since
 * Version20260102200000, these two had none, so every search scanned the whole
 * puzzle table - 135 ms on average (Sentry WEB-B4). Measured on production
 * without those two arms, i.e. what the indexes make possible: "wasgij"
 * 75 ms -> 12 ms, "cat" 79 ms -> 8.5 ms. Search terms of 1-2 characters cannot
 * use a trigram index and are unaffected.
 *
 * Custom (Doctrine cannot manage GIN trigram indexes): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for them,
 * and tests/bootstrap.php mirrors them. Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the puzzle table is
 * ~41k rows / 28 MB, so the build takes well under a second.
 */
final class Version20260918131133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trigram indexes for puzzle search by catalogue number and EAN';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_identification_number_trgm ON puzzle USING GIN (identification_number gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_ean_trgm ON puzzle USING GIN (ean gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_identification_number_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_ean_trgm');
    }
}
