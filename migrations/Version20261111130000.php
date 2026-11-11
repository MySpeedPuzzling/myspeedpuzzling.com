<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Analyze puzzle_solving_time every ~1 % of changed rows instead of the server's 10 %.
 *
 * At ~583k rows the default meant ~58k changes between analyzes, while the table changes ~1,700 rows a day - one
 * analyze a month. Production on 2026-11-11: the finished_at histogram still ended on 2026-10-31, so every range in
 * the current month lay past it, and the partial index custom_pst_finished_at_solo cannot be used to look up the
 * column's real maximum. The "this month" lists (Hub: most active / most solved) were planned for 1 row while
 * reading 9,479 - nested loops, 72k buffers, 40 ms, growing every day until the month ended.
 * 1 % is ~5.9k changes - an analyze every 3-4 days, each samples 30k rows (well under a second).
 * Vacuum thresholds stay as they are (docs/TODO.md "Background batch queries").
 *
 * ALTER TABLE ... SET takes a SHARE UPDATE EXCLUSIVE lock: reads and writes go on; lock_timeout caps the wait for a
 * running (auto)vacuum.
 */
final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Analyze puzzle_solving_time every ~1 % of changed rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE puzzle_solving_time SET (autovacuum_analyze_scale_factor = 0.01)');
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time RESET (autovacuum_analyze_scale_factor)');
    }
}
