<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Covering index for the qualifying first attempts of the puzzle insights batch (`recalculate-puzzle-intelligence`,
 * every 15 minutes): per player and puzzle the solo solve with a time, not suspicious, not unboxed, ranked
 * `first_attempt DESC, COALESCE(finished_at, tracked_at), tracked_at, id` (PlayerBaselineCalculator::
 * qualifyingFirstAttemptsQuery() - the baseline preload, the pace history checksum, the difficulty preload) and the
 * improvement ratios' solves (ImprovementRatioCalculator, same rows ordered by player, puzzle, solve moment).
 *
 * The key is that ranking and the predicate is that rule, so the window streams from an index-only scan instead of
 * walking custom_pst_intelligence into the heap (production 2026-10-26: 430k buffer hits) or sorting 465k rows. Four of
 * the run's seven statements over 500 ms (production: 1.16 + 1.33 + 1.17 + 0.82 s per run). On a local copy of
 * production (523k results), 10 interleaved runs each, 72 % of the heap all-visible like production (min / median ms):
 * first attempts 1079 / 1558 -> 556 / 985, in pace order 1178 / 1701 -> 773 / 1034, the pace checksum 1809 / 2454 ->
 * 1302 / 1750, the improvement solves 755 / 1144 -> 388 / 688. Results identical (EXCEPT ALL both ways, empty).
 *
 * Custom (Doctrine cannot map a partial expression index with INCLUDE): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for it, and tests/bootstrap.php mirrors it.
 * Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the boot migrations run --all-or-nothing, which refuses
 * non-transactional migrations, so CONCURRENTLY is not available. The build holds a SHARE lock - reads go on, writes to
 * puzzle_solving_time wait until the deploy's migration transaction commits. On the local copy (523k rows, 440k in the
 * index) the build takes 0.9-1.2 s and the index is 47 MB; production has 566k rows. lock_timeout caps the wait for a
 * running writer, so a long one fails the boot (which retries) instead of queueing every write behind the build.
 */
final class Version20261027010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add covering index for the qualifying first attempts of the puzzle insights batch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql("CREATE INDEX IF NOT EXISTS custom_pst_qualifying_first_attempt ON puzzle_solving_time (player_id, puzzle_id, first_attempt DESC, (COALESCE(finished_at, tracked_at)), tracked_at, id) INCLUDE (seconds_to_solve, finished_at) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL AND unboxed = false");
        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_qualifying_first_attempt');
    }
}
