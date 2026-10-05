<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle names, phase 1c-1 (docs/features/puzzle-names/): the old single alternative_name is no longer written or read.
 *
 * - alternative_names gets every alternative_name it does not hold yet - names the phase 0 release wrote while it
 *   still served next to phase 1a. Compared like the phase 1a backfill (Version20261004203522): whitespace runs one
 *   space, control and format characters removed, trimmed, then without accents and case against the main title and
 *   every name of the list. Appended at the end, tagged `cs` when it has a letter only Czech uses. Expected to copy
 *   nothing on production (checked before the release); a copied row needs myspeedpuzzling:rebuild-puzzle-search-keys
 *   afterwards (the fold lives in PHP only).
 * - The column itself stays, unmapped, until phase 1c-2: containers of the release before still read and write it
 *   while this one rolls out (CustomIndexFilteringPostgreSQLSchemaManager keeps it away from the schema tools).
 * - Five trigram indexes nothing reads since phase 1b moved the puzzle search onto the search keys
 *   (custom_puzzle_search_names_trgm / custom_puzzle_search_codes_trgm): no query in src/ filters on
 *   immutable_unaccent() of a puzzle name, on alternative_name, or LIKE/ILIKE/similarity on ean or
 *   identification_number, production's idx_scan of each did not grow after the phase 1b deploy, and every puzzle
 *   text search of the code base (catalogue, marketplace, barcode lookups, multiscan, approval queue, duplicate
 *   signals) run on a copy of production scanned none of them. custom_puzzle_name_trgm stays (the approval queue's
 *   similar titles, `name % …`).
 *
 * Last in its batch: DROP INDEX takes an ACCESS EXCLUSIVE lock on puzzle that the all-or-nothing boot migration keeps
 * until its commit - lock_timeout caps the wait for it at 5 s (a boot that cannot get it rolls back and is retried).
 * The copy runs first, under the lighter lock of an UPDATE.
 */
final class Version20261004235009 extends AbstractMigration
{
    // PuzzleNames::cleanName() in SQL, as the phase 1a backfill wrote it
    private const string CLEANED = <<<'SQL'
btrim(regexp_replace(regexp_replace(regexp_replace(
    %s,
    '[\s\x00A0\x1680\x2000-\x200A\x2028\x2029\x202F\x205F\x3000]+', ' ', 'g'),
    '[\x01-\x1F\x7F-\x9F\x00AD\x200B-\x200F\x202A-\x202E\x2060-\x206F\xFEFF]', '', 'g'),
    ' {2,}', ' ', 'g'))
SQL;

    public function getDescription(): string
    {
        return 'Puzzle names: alternative_name copied into alternative_names where missing, unused trigram indexes dropped';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");

        $this->addSql(sprintf(
            <<<'SQL'
WITH cleaned AS (
    SELECT id, %s AS alternative, %s AS main_title, alternative_names
    FROM puzzle
    WHERE alternative_name IS NOT NULL
),
missing AS (
    SELECT id, alternative
    FROM cleaned
    WHERE alternative <> ''
        AND lower(immutable_unaccent(alternative)) <> lower(immutable_unaccent(main_title))
        AND NOT EXISTS (
            SELECT 1
            FROM jsonb_array_elements(cleaned.alternative_names) AS other
            WHERE lower(immutable_unaccent(%s)) = lower(immutable_unaccent(alternative))
        )
)
UPDATE puzzle
SET alternative_names = puzzle.alternative_names || jsonb_build_array(jsonb_build_object(
    'name', missing.alternative,
    'language', CASE WHEN missing.alternative ~ '[\x011B\x0161\x010D\x0159\x017E\x016F\x0165\x010F\x0148\x011A\x0160\x010C\x0158\x017D\x016E\x0164\x010E\x0147]' THEN 'cs' END
))
FROM missing
WHERE puzzle.id = missing.id
SQL,
            sprintf(self::CLEANED, 'alternative_name'),
            sprintf(self::CLEANED, 'name'),
            sprintf(self::CLEANED, "other ->> 'name'"),
        ));

        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_alt_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_alt_name_unaccent_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_name_unaccent_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_ean_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_identification_number_trgm');

        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // The copy stays: a copied name cannot be told from one added later
        $this->addSql('CREATE INDEX custom_puzzle_alt_name_trgm ON puzzle USING GIN (alternative_name gin_trgm_ops)');
        $this->addSql('CREATE INDEX custom_puzzle_name_unaccent_trgm ON puzzle USING GIN (immutable_unaccent(name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX custom_puzzle_alt_name_unaccent_trgm ON puzzle USING GIN (immutable_unaccent(alternative_name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_identification_number_trgm ON puzzle USING GIN (identification_number gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_ean_trgm ON puzzle USING GIN (ean gin_trgm_ops)');
    }
}
