<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * custom_pst_solo_board keyed (puzzle_id, player_id) instead of (puzzle_id) INCLUDE (player_id, ...): the same entries
 * and size, but each puzzle's board now comes sorted by player. The profile's ranks (GetRanking::rankings()) count the
 * distinct players of every board the player is on, per puzzle - presorted, that count needs no sort (on a copy of
 * production, heaviest profile: 0.05-0.10 -> 0.035-0.05 ms per board, 2,129 boards). The Times boards and close calls
 * look it up by puzzle_id only and read the same pages (solo 500: 4,950 buffers either way).
 *
 * Custom (Doctrine cannot map a partial index with INCLUDE): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from generating a DROP for it, and tests/bootstrap.php mirrors it.
 * Registry: docs/database-indexes.md.
 *
 * Plain DROP + CREATE inside the migration transaction (the boot migrations run --all-or-nothing, so no
 * CONCURRENTLY): puzzle_solving_time is locked until the migration commits - the build took 0.18 s on a copy of
 * production (25 MB).
 */
final class Version20261027020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key the solo board index by puzzle and player';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_solo_board');
        $this->addSql("CREATE INDEX custom_pst_solo_board ON puzzle_solving_time (puzzle_id, player_id) INCLUDE (seconds_to_solve) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_solo_board');
        $this->addSql("CREATE INDEX custom_pst_solo_board ON puzzle_solving_time (puzzle_id) INCLUDE (player_id, seconds_to_solve) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0");
    }
}
