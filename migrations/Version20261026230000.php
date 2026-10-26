<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops custom_notification_borrow_request (Version20261026210000): GetNotifications::forPlayer() now picks the page
 * from the reader's notifications first and every branch, the borrow request one too, reads only that page - nothing
 * plans on the index any more (docs/features/notifications.md).
 *
 * DROP INDEX takes an ACCESS EXCLUSIVE lock on notification, kept until the boot migration commits; CONCURRENTLY is
 * not available (--all-or-nothing refuses non-transactional migrations). The drop itself takes under a millisecond,
 * lock_timeout caps the wait for the statements already reading the table at 5 s (the badge's count, 0.1 ms on
 * average) - a boot that cannot get the lock fails and tries again, see Version20260930163200.
 */
final class Version20261026230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the partial index of the borrow request notifications, unused since the page-first notifications query';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('DROP INDEX IF EXISTS custom_notification_borrow_request');
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_notification_borrow_request ON notification (player_id) WHERE target_borrow_request_id IS NOT NULL');
    }
}
