<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial index for the borrow request branch of GetNotifications::forPlayer().
 *
 * Version20261026154954 added notification.target_borrow_request_id. The column has no statistics until notification
 * (4.55M rows) is analyzed again, so the planner took `target_borrow_request_id IS NOT NULL` for nearly every row and
 * started the branch from the actor_player_id index for a merge join with player: 4.55M rows filtered, 0.8-14 s per
 * notifications page on production (2026-10-26). The branch now reads the reader's borrow notifications in a
 * MATERIALIZED CTE (so nothing outside can choose that index), and this index answers the CTE with only the reader's
 * borrow request notifications instead of all their notifications. On a local copy with 4.55M notifications: the
 * branch 377 ms -> 0.3 ms for a player with 31k notifications.
 *
 * Custom (Doctrine cannot map a partial index): the custom_ prefix keeps CustomIndexFilteringSchemaManagerFactory from
 * ever generating a DROP for it, and tests/bootstrap.php mirrors it. Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the boot migrations run --all-or-nothing, which refuses
 * non-transactional migrations, so CONCURRENTLY is not available. The build holds a SHARE lock - reads go on, writes to
 * notification wait until the deploy's migration transaction commits. On a local copy of production's size (4.55M
 * rows) the build takes 0.7 s and the index is 8 kB (no borrow request notifications yet).
 */
final class Version20261026210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial index for the borrow request notifications of a player';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_notification_borrow_request ON notification (player_id) WHERE target_borrow_request_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_notification_borrow_request');
    }
}
