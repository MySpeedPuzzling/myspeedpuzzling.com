<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle names, phase 1c-2 (docs/features/puzzle-names/): the old single `alternative_name` column goes. Phase 1c-1
 * stopped mapping and writing it one release earlier, so no container still running during this deploy touches it.
 * Its content lives in `alternative_names` since phase 1a (PuzzleNames::legacyAlternativeName() gives the old value).
 * Dropping a column is a catalogue change only, no table rewrite; lock_timeout caps the wait for the lock.
 */
final class Version20261005001318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle names: drop the old single alternative_name column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE puzzle DROP COLUMN IF EXISTS alternative_name');
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // Back as it was written: the first Czech name, else the first name
        $this->addSql('ALTER TABLE puzzle ADD alternative_name VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
UPDATE puzzle
SET alternative_name = left(COALESCE(
    (SELECT e->>'name' FROM jsonb_array_elements(alternative_names) e WHERE split_part(e->>'language', '-', 1) = 'cs' LIMIT 1),
    alternative_names->0->>'name'
), 255)
WHERE jsonb_array_length(alternative_names) > 0
SQL);
    }
}
