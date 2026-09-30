<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops two puzzle_solving_time indexes nothing reads.
 *
 * - custom_pst_tracked_at_type (tracked_at, puzzling_type), from Version20260102230000 - 18 MB
 * - custom_tracked_at_order_desc (tracked_at DESC), created by hand on production, no migration - 17 MB
 *
 * Production (2026-09-30): 0 scans each since its statistics started (~27 days), while the Doctrine
 * index on tracked_at (idx_fe83a93cafa9b124) had 646k. Every query filtering or ordering by tracked_at
 * (GetStatistics, GetMostSolvedPuzzles, GetPlayerActivityCalendar, GetRecentActivity) plans on that one,
 * and a b-tree reads backwards as well as forwards, so the DESC copy adds nothing. Checked on a local copy
 * of production: the plans of those queries are identical without the two. They only cost writes -
 * every insert and every non-HOT update of a solving time maintained 35 MB of index for no reader.
 *
 * Last in the batch on purpose: DROP INDEX takes an ACCESS EXCLUSIVE lock on puzzle_solving_time, which
 * the all-or-nothing boot migration keeps until its commit, so it must not be followed by the index builds.
 * CONCURRENTLY is not available (--all-or-nothing refuses non-transactional migrations). The drop itself
 * takes under a millisecond, but it has to wait for the statements already reading the table and every new
 * reader queues behind it - lock_timeout caps that wait at 5 s (the longest statement on the table in
 * pg_stat_statements on 2026-09-30: 2.3 s). A boot that cannot get the lock in time rolls the whole batch
 * back and fails; the other new container, or this one restarted, tries again, and the rollout reverts
 * to the running release if none gets it - instead of the site stalling behind a long query.
 */
final class Version20260930163200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused tracked_at indexes of puzzle_solving_time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('DROP INDEX IF EXISTS custom_pst_tracked_at_type');
        $this->addSql('DROP INDEX IF EXISTS custom_tracked_at_order_desc');
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_pst_tracked_at_type ON puzzle_solving_time (tracked_at, puzzling_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_tracked_at_order_desc ON puzzle_solving_time (tracked_at DESC)');
    }
}
