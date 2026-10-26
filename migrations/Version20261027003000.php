<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A player's newest times in order, for the profile's Recent activity (GetRecentActivity::forPlayer()).
 *
 * The query read `player_id = … OR team @> …` ordered by tracked_at: no index answers the OR in that order, so the
 * planner walked the tracked_at index backwards filtering every time - the whole table (565k rows, 0.5-0.9 s on
 * production 2026-10-26) for a player with fewer than 20 times. The query now reads the player's own times and their
 * group times apart; this index gives the first branch the newest 20 directly. On a local copy of production (523k
 * results) the rewritten query alone left 5-89 ms for the heaviest players (the planner still walked tracked_at for
 * them); with this index 0.7-2.3 ms.
 *
 * Custom (the house keeps hand-written puzzle_solving_time indexes as custom_*): the prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for it, and tests/bootstrap.php mirrors it.
 * Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the boot migrations run --all-or-nothing, which refuses
 * non-transactional migrations, so CONCURRENTLY is not available. The build holds a SHARE lock - reads go on, writes to
 * puzzle_solving_time wait until the deploy's migration transaction commits. On a local copy (523k rows) the build takes
 * 0.7-0.9 s and the index is 20 MB.
 */
final class Version20261027003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index for the newest times of a player';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_pst_player_tracked_at ON puzzle_solving_time (player_id, tracked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_player_tracked_at');
    }
}
