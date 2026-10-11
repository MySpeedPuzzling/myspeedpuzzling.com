<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial covering index for the solo Times boards (GetFastestPlayers::window(): the /ladder overview, the per-pieces
 * boards, their lazy tables).
 *
 * Each board statement first builds every player's best solo time for one piece count: a hash aggregate over the
 * valid solo results joined to puzzle by pieces_count. Without the index that is a parallel scan of all 523k results
 * (23.7k pages, 185 MB). With it the aggregate reads the index only (3.2k pages): on a copy of production, together
 * with grouping before the player filters, solo 500 85 -> 50 ms and solo 1000 48 -> 31 ms (2026-10-11).
 *
 * Custom (Doctrine cannot map a partial index with INCLUDE): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from generating a DROP for it, and tests/bootstrap.php mirrors it.
 * Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction (the boot migrations run --all-or-nothing, so no
 * CONCURRENTLY): the build holds a SHARE lock - reads go on, writes to puzzle_solving_time wait until the
 * migration commits. On a copy of production the build takes 0.18 s and the index is 25 MB.
 */
final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial covering index for the solo Times boards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX IF NOT EXISTS custom_pst_solo_board ON puzzle_solving_time (puzzle_id) INCLUDE (player_id, seconds_to_solve) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_solo_board');
    }
}
