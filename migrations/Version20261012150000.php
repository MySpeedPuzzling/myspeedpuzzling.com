<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial index for the Inbox badge's For you part (docs/features/navigation INB-02, decision 47).
 *
 * GetInboxBadge runs on every page a signed-in player opens and counts their unread notifications except
 * SubscribedPlayerAddedTime (favorites' new times, no longer in the bell - D4) and NewConversationRequest (counted
 * under Messages). custom_notification_unread (player_id WHERE read_at IS NULL) cannot answer that type filter from the
 * index: every unread row would be read from the heap - and most unread rows are exactly the favorites' times (players
 * hold up to ~17k of them). With the same two types in the predicate the count stays an index-only scan; the query
 * inlines them as literals so the planner can match the predicate.
 *
 * custom_notification_unread stays: mark-read (UPDATE ... WHERE player_id = ? AND read_at IS NULL) and the
 * hasUnread...Notification() lookups read every type.
 *
 * Custom (Doctrine cannot map a partial index): the custom_ prefix keeps CustomIndexFilteringSchemaManagerFactory from
 * generating a DROP for it, tests/bootstrap.php mirrors it, docs/database-indexes.md registers it. Plain CREATE INDEX
 * inside the migration transaction (the boot migrations run --all-or-nothing, so no CONCURRENTLY), as
 * Version20260930163000 - the build holds a SHARE lock on notification for its fraction of a second.
 */
final class Version20261012150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial index for the Inbox badge\'s For you count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX IF NOT EXISTS custom_notification_unread_for_you ON notification (player_id) WHERE read_at IS NULL AND type NOT IN ('SubscribedPlayerAddedTime', 'NewConversationRequest')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_notification_unread_for_you');
    }
}
