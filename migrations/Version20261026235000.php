<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Autovacuum for notification every ~1 % of changed rows instead of the server's 20 % / 10 %.
 *
 * At 4.5M rows the defaults meant ~900k dead or inserted rows between vacuums and 450k changes between analyzes:
 * production (2026-10-26) last vacuumed the table on 2026-09-11 (304k dead rows, 382k inserted since) and analyzed it
 * on 2026-10-17. Both cost us:
 * - the bell's count (every page, ~720k a day) is an index-only scan of custom_notification_unread, but with the
 *   visibility map out of date it read every row from the table anyway - Heap Fetches 18,203 for 18,201 rows, 31 ms;
 * - new target_* columns (#286, #288) had no statistics for days, and the notifications page planned a branch over all
 *   4.5M rows (docs/features/notifications.md).
 * 1 % is ~45k rows now, ~16k after the read notifications are pruned - a few vacuums a day of a table whose pages are
 * mostly all-visible, so each vacuum skips most of it.
 *
 * ALTER TABLE ... SET takes a SHARE UPDATE EXCLUSIVE lock: reads and writes go on; lock_timeout caps the wait for a
 * running (auto)vacuum.
 */
final class Version20261026235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vacuum and analyze notification every ~1 % of changed rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE notification SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_vacuum_insert_scale_factor = 0.01, autovacuum_analyze_scale_factor = 0.01)');
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_insert_scale_factor, autovacuum_analyze_scale_factor)');
    }
}
