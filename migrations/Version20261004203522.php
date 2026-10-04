<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle names, phase 1a (docs/features/puzzle-names/): the storage for every name of a puzzle and the two search
 * keys the entity maintains.
 *
 * - alternative_names: the single alternative_name copied in as the first entry - cleaned like PuzzleNames::cleanName()
 *   (whitespace runs one space, control and format characters removed, trimmed), tagged `cs` when it has a letter only
 *   Czech uses, skipped when it is the main title again (compared without accents and case). alternative_name stays
 *   until phase 1c; the entity writes both meanwhile.
 * - search_names / search_codes stay NULL here: the fold lives in PHP only (SearchText), so
 *   myspeedpuzzling:rebuild-puzzle-search-keys fills them right after the deploy. Nothing reads them before phase 1b.
 * - custom_puzzle_search_names_trgm / custom_puzzle_search_codes_trgm: GIN trigram indexes for the phase 1b search,
 *   built on empty columns here (instant). Custom (Doctrine cannot manage GIN trigram indexes), mirrored in
 *   tests/bootstrap.php, registered in docs/database-indexes.md.
 *
 * The new columns have constant defaults, so no table rewrite. ADD COLUMN takes an ACCESS EXCLUSIVE lock on puzzle
 * that the all-or-nothing boot migration keeps until its commit - lock_timeout caps the wait for it at 5 s (a boot that
 * cannot get it rolls back and is retried). Measured on a copy of production: see the phase 1a report. Last
 * migration of its release, deployed outside the quarter-hour cron minutes.
 */
final class Version20261004203522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle names: alternative_names list, name_language, names_changed_at and the search keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");

        $this->addSql('ALTER TABLE puzzle ADD alternative_names JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE puzzle ADD name_language VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD names_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD search_names TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD search_codes TEXT DEFAULT NULL');

        $this->addSql(<<<'SQL'
WITH cleaned AS (
    SELECT
        id,
        btrim(regexp_replace(regexp_replace(regexp_replace(
            alternative_name,
            '[\s   -     　]+', ' ', 'g'),
            '[\x01-\x1F\x7F-\x9F­​-‏‪-‮⁠-⁯﻿]', '', 'g'),
            ' {2,}', ' ', 'g')) AS alternative,
        btrim(regexp_replace(regexp_replace(regexp_replace(
            name,
            '[\s   -     　]+', ' ', 'g'),
            '[\x01-\x1F\x7F-\x9F­​-‏‪-‮⁠-⁯﻿]', '', 'g'),
            ' {2,}', ' ', 'g')) AS main_title
    FROM puzzle
    WHERE alternative_name IS NOT NULL
)
UPDATE puzzle
SET alternative_names = jsonb_build_array(jsonb_build_object(
    'name', cleaned.alternative,
    'language', CASE WHEN cleaned.alternative ~ '[ěščřžůťďňĚŠČŘŽŮŤĎŇ]' THEN 'cs' END
))
FROM cleaned
WHERE puzzle.id = cleaned.id
    AND cleaned.alternative <> ''
    AND lower(immutable_unaccent(cleaned.alternative)) <> lower(immutable_unaccent(cleaned.main_title))
SQL);

        $this->addSql('CREATE INDEX custom_puzzle_search_names_trgm ON puzzle USING GIN (search_names gin_trgm_ops)');
        $this->addSql('CREATE INDEX custom_puzzle_search_codes_trgm ON puzzle USING GIN (search_codes gin_trgm_ops)');

        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_search_names_trgm');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_search_codes_trgm');
        $this->addSql('ALTER TABLE puzzle DROP alternative_names');
        $this->addSql('ALTER TABLE puzzle DROP name_language');
        $this->addSql('ALTER TABLE puzzle DROP names_changed_at');
        $this->addSql('ALTER TABLE puzzle DROP search_names');
        $this->addSql('ALTER TABLE puzzle DROP search_codes');
    }
}
