<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial index for the unread-notifications badge.
 *
 * GetNotifications::countUnreadForPlayer() runs on every page a signed-in player opens (the bell in
 * base.html.twig) - 17-21k calls a day. It found the player's rows through the player_id foreign-key
 * index and then read every one of them from the heap to keep the unread ones: 4.17M notifications, up to
 * 21k per player, 20 % unread (production, 2026-09-30). Measured on production without this index:
 * p90 player 2.6 ms / 3,153 buffers, p95 5.5 ms / 4,960, p99 7.6 ms / 10,473, the biggest 11.7 ms / 14,437
 * (warm cache); pg_stat_statements mean 14.6 ms, max 1.1 s. With it, on a local copy with production's
 * per-player counts: 0.04-0.2 ms for those players, 1.2 ms for the one with 16,947 unread notifications -
 * an index-only scan, which is why the query counts `*` instead of `id`.
 *
 * It also serves markNotificationAsReadForPlayer() (UPDATE ... WHERE player_id = ? AND read_at IS NULL)
 * and the hasUnread...Notification() lookups of NotificationRepository.
 *
 * Cost: read_at is now part of an index predicate, so marking a notification read is no longer a HOT
 * update. Since 2026-09-30 a visit updates only the unread rows, so each notification is updated at most
 * once (~10k a day).
 *
 * Custom (Doctrine cannot map a partial index): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for it, and tests/bootstrap.php
 * mirrors it. Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the boot migrations run --all-or-nothing, which
 * refuses non-transactional migrations, so CONCURRENTLY is not available. The build holds a SHARE lock -
 * reads go on, writes to notification wait until the deploy's migration transaction commits. On a local
 * copy of production's size (4.17M rows, 524 MB heap) the build takes 0.22-0.33 s and the index is 6 MB.
 */
final class Version20260930163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial index for counting unread notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_notification_unread ON notification (player_id) WHERE read_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_notification_unread');
    }
}
