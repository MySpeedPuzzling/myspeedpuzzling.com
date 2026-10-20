<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial covering index for one stored day of the MSP Rating ladder (docs/features/msp-rating-history.md): the
 * ladder's 7-day movement (GetPlayerRatingRanking::window()), the header chip's place a week ago
 * (sqlPlaceOnStoredDayOf()) and "This week on the ladder" (GetRatingLadderThisWeek) all rank the rated rows of one
 * snapshot day. Without it each finds that day by scanning all of player_rating_snapshot (800 MB): on production
 * 2026-10-20 the ladder took 156 ms, the header 122 ms. On a copy of production with it: ladder 263 -> 4.9 ms, header
 * 159 -> 0.5 ms (no parallel workers locally).
 *
 * Custom (Doctrine cannot map a partial index with INCLUDE): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from generating a DROP for it, and tests/bootstrap.php mirrors it.
 * Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction (the boot migrations run --all-or-nothing, so no
 * CONCURRENTLY): the build holds a SHARE lock - reads go on, the rating batch's snapshot writes wait. On the copy the
 * build takes 0.17 s and the index is 13 MB (only the rated rows, about a quarter of the table).
 */
final class Version20261020200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial covering index for one stored day of the MSP Rating ladder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_rating_snapshot_day ON player_rating_snapshot (pieces_count, snapshot_date) INCLUDE (player_id, elo_rating, elo_rank) WHERE elo_rating IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_rating_snapshot_day');
    }
}
