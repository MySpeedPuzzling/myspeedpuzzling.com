<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\DerivedMetricsCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImprovementRatioCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The recalculation writes only the rows whose values changed (2026-09-30; before, every run
 * rewrote every row). Pinned here: the tables end up exactly as rewriting everything leaves them -
 * a run over empty tables writes every row, so it is the reference - unchanged rows are not written
 * (not even locked), their computed_at stays, and stale rows go exactly as before.
 *
 * Fixture players never reach a skill tier or a rating (both need 20+ solvers per puzzle), so every
 * test seeds a small ladder: 24 players (22 public) x 55 puzzles of 500 pieces, repeat attempts, and
 * 1000/750/1500-piece solves for direct, interpolated and extrapolated baselines.
 */
final class PuzzleIntelligenceRecalculatorWritesTest extends KernelTestCase
{
    /** Every table the recalculation writes, with its unique key */
    private const array TABLES = [
        'player_baseline' => ['player_id', 'pieces_count'],
        'puzzle_difficulty' => ['puzzle_id'],
        'player_skill' => ['player_id', 'pieces_count'],
        'player_elo' => ['player_id', 'pieces_count'],
        'global_improvement_ratio' => ['pieces_count', 'from_attempt', 'gap_bucket'],
        'player_improvement_ratio' => ['player_id', 'from_attempt'],
        'player_skill_history' => ['player_id', 'pieces_count', 'month'],
        'player_rating_snapshot' => ['player_id', 'pieces_count', 'snapshot_date'],
    ];

    /** Recomputed completely by every run; history and snapshots keep the rows of earlier periods */
    private const array RECOMPUTED_TABLES = [
        'player_baseline',
        'puzzle_difficulty',
        'player_skill',
        'player_elo',
        'global_improvement_ratio',
        'player_improvement_ratio',
    ];

    /** Every value column the recalculation writes, each with a way to make a stored value wrong */
    private const array WRONG_VALUES = [
        'player_baseline' => [
            'baseline_seconds' => 'baseline_seconds + 1',
            'qualifying_solves_count' => 'qualifying_solves_count + 1',
            'baseline_type' => "baseline_type || '?'",
        ],
        'puzzle_difficulty' => [
            'difficulty_score' => 'difficulty_score + 0.5',
            'difficulty_tier' => 'COALESCE(difficulty_tier, 0) + 1',
            'confidence' => "confidence || '?'",
            'sample_size' => 'sample_size + 1',
            'indices_p25' => 'COALESCE(indices_p25, 0) + 0.5',
            'indices_p75' => 'COALESCE(indices_p75, 0) + 0.5',
            'memorability_score' => 'COALESCE(memorability_score, 0) + 0.5',
            'skill_sensitivity_score' => 'COALESCE(skill_sensitivity_score, 0) + 0.5',
            'predictability_score' => 'COALESCE(predictability_score, 0) + 0.5',
            'box_dependence_score' => 'COALESCE(box_dependence_score, 0) + 0.5',
            'improvement_ceiling_score' => 'COALESCE(improvement_ceiling_score, 0) + 0.5',
        ],
        'player_skill' => [
            'skill_score' => 'skill_score + 0.5',
            'skill_tier' => 'skill_tier + 1',
            'skill_percentile' => 'skill_percentile + 0.5',
            'confidence' => "confidence || '?'",
            'qualifying_puzzles_count' => 'qualifying_puzzles_count + 1',
        ],
        'player_elo' => [
            'elo_rating' => 'elo_rating + 0.5',
        ],
        'global_improvement_ratio' => [
            'median_ratio' => 'median_ratio + 0.5',
            'sample_size' => 'sample_size + 1',
        ],
        'player_improvement_ratio' => [
            'median_ratio' => 'median_ratio + 0.5',
            'sample_size' => 'sample_size + 1',
        ],
        'player_skill_history' => [
            'baseline_seconds' => 'baseline_seconds + 1',
            'skill_tier' => 'COALESCE(skill_tier, 0) + 1',
            'skill_percentile' => 'COALESCE(skill_percentile, 0) + 0.5',
        ],
        'player_rating_snapshot' => [
            'skill_score' => 'COALESCE(skill_score, 0) + 0.5',
            'skill_tier' => 'COALESCE(skill_tier, 0) + 1',
            'skill_percentile' => 'COALESCE(skill_percentile, 0) + 0.5',
            'elo_rating' => 'COALESCE(elo_rating, 0) + 0.5',
            'elo_rank' => 'COALESCE(elo_rank, 0) + 1',
            'baseline_seconds' => 'COALESCE(baseline_seconds, 0) + 1',
            'baseline_type' => "COALESCE(baseline_type, '') || '?'",
        ],
    ];

    private const string SENTINEL = '2000-01-01 00:00:00';

    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->now = new DateTimeImmutable('today 12:00:00');

        $this->seedLadder();
    }

    public function testTheLadderReachesEveryTable(): void
    {
        $this->recalculate($this->now);

        self::assertSame(
            ['direct', 'extrapolated', 'interpolated'],
            $this->connection->fetchFirstColumn("SELECT DISTINCT baseline_type FROM player_baseline WHERE player_id::text LIKE '019a0000-%' ORDER BY 1"),
        );
        self::assertSame(55, $this->countRows("SELECT COUNT(*) FROM puzzle_difficulty WHERE puzzle_id::text LIKE '019a0001-%' AND difficulty_score IS NOT NULL AND skill_sensitivity_score IS NOT NULL"));
        self::assertSame(5, $this->countRows('SELECT COUNT(*) FROM puzzle_difficulty WHERE memorability_score IS NOT NULL'));
        self::assertSame(24, $this->countRows('SELECT COUNT(*) FROM player_skill'));
        self::assertSame(22, $this->countRows('SELECT COUNT(*) FROM player_elo'), 'Every public ladder player; private players have no rating');
        self::assertGreaterThan(0, $this->countRows('SELECT COUNT(*) FROM global_improvement_ratio'));
        self::assertSame(10, $this->countRows("SELECT COUNT(DISTINCT player_id) FROM player_improvement_ratio WHERE player_id::text LIKE '019a0000-%'"));

        $baselines = $this->countRows('SELECT COUNT(*) FROM player_baseline');
        self::assertSame($baselines, $this->countRows('SELECT COUNT(*) FROM player_skill_history'));
        self::assertSame($baselines, $this->countRows('SELECT COUNT(*) FROM player_rating_snapshot'));
    }

    public function testASecondRunOverUnchangedDataWritesNothing(): void
    {
        $this->recalculate($this->now);

        // However old their computed_at, rows the run produces stay: stale rows are found by key
        $this->stampComputedAt(self::SENTINEL);

        $before = $this->contents();
        $writesBefore = $this->writes();

        $this->recalculate($this->now);

        // Same values, same row versions (ctid), not even locked (xmax), computed_at untouched
        self::assertSame($before, $this->contents());

        foreach ($this->writes() as $table => $writes) {
            self::assertSame($writesBefore[$table], $writes, "{$table}: a run over unchanged data writes no row");
        }
    }

    public function testAChangedSolveRewritesExactlyTheRowsWhoseValuesChange(): void
    {
        $this->recalculate($this->now);
        $this->stampComputedAt(self::SENTINEL);
        $before = $this->contents();

        // Player 3 takes 2.5 hours on puzzle 7 instead of ~36 minutes: their baseline, the difficulty
        // of the puzzles they solved, everybody's percentile on puzzle 7 and so skills, ratings,
        // history and snapshots move
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 9000 WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(3), 'puzzle' => self::ladderPuzzle(7)],
        );

        $this->recalculate($this->now);
        $after = $this->contents();

        foreach (array_keys(self::TABLES) as $table) {
            $changed = [];
            $written = [];
            $stamped = [];

            foreach (array_keys($before[$table] + $after[$table]) as $rowKey) {
                $old = $before[$table][$rowKey] ?? null;
                $new = $after[$table][$rowKey] ?? null;

                if ($old === null || $new === null || $old['row_values'] !== $new['row_values']) {
                    $changed[] = $rowKey;
                }

                if ($old === null || $new === null || $old['ctid'] !== $new['ctid']) {
                    $written[] = $rowKey;
                }

                if ($new !== null && $new['computed_at'] !== null && $new['computed_at'] !== self::SENTINEL) {
                    $stamped[] = $rowKey;
                }
            }

            self::assertSame($changed, $written, "{$table}: exactly the rows whose values changed are written");

            if ($table === 'puzzle_difficulty') {
                // The derived metrics never set computed_at (they did not before either): it moves
                // with the difficulty columns only
                self::assertSame([], array_diff($stamped, $written), $table);
            } elseif ($table !== 'player_skill_history') {
                self::assertSame($changed, $stamped, "{$table}: computed_at moves exactly on the written rows");
            }
        }

        foreach (['player_baseline', 'puzzle_difficulty', 'player_skill', 'player_elo', 'player_skill_history', 'player_rating_snapshot'] as $table) {
            self::assertNotSame($before[$table], $after[$table], "{$table}: the changed solve reaches this table");
        }

        foreach (['player_baseline', 'puzzle_difficulty', 'player_rating_snapshot'] as $table) {
            self::assertSame(
                array_intersect_key($before[$table], self::fixtureRows($before[$table])),
                array_intersect_key($after[$table], self::fixtureRows($before[$table])),
                "{$table}: rows the change cannot reach stay as they were",
            );
        }
    }

    public function testRowsTheRunNoLongerProducesAreDeletedAsBefore(): void
    {
        $this->recalculate($this->now);

        $past = $this->now->modify('-1 day')->format('Y-m-d H:i:s');
        // Written after the run started, e.g. by the incremental handler with its own later clock: kept, as always
        $later = $this->now->modify('+1 minute')->format('Y-m-d H:i:s');
        $month = $this->now->format('Y-m-01 00:00:00');
        $day = $this->now->format('Y-m-d 00:00:00');

        // A public player with no solves at all, so rows kept for them feed nothing else
        $this->connection->executeStatement(
            "INSERT INTO player (id, code, name, registered_at) VALUES (:player, 'ladder25', 'Ladder Solver 25', :past)",
            ['player' => self::ladderPlayer(25), 'past' => $past],
        );

        $stale = [
            'player_baseline' => [self::ladderPlayer(1) . '|4242', self::ladderPlayer(2) . '|4242'],
            'player_skill' => [PlayerFixture::PLAYER_REGULAR . '|500', self::ladderPlayer(1) . '|1000'],
            'player_elo' => [PlayerFixture::PLAYER_REGULAR . '|500', self::ladderPlayer(23) . '|500'],
            'global_improvement_ratio' => ['4242|1|all'],
            'player_improvement_ratio' => [self::ladderPlayer(20) . '|1'],
        ];
        $kept = [
            'player_baseline' => [self::ladderPlayer(13) . '|4242', self::ladderPlayer(14) . '|4242'],
            'player_skill' => [self::ladderPlayer(25) . '|500'],
            'player_elo' => [self::ladderPlayer(25) . '|500'],
            'global_improvement_ratio' => ['4242|2|all'],
            'player_improvement_ratio' => [self::ladderPlayer(21) . '|1'],
            // The recalculation never deletes these, whatever their computed_at
            'puzzle_difficulty' => [self::ladderPuzzle(99)],
            'player_skill_history' => [PlayerFixture::PLAYER_REGULAR . '|4242|' . $month],
            'player_rating_snapshot' => [PlayerFixture::PLAYER_REGULAR . '|4242|' . $day],
        ];
        // The kept baselines get their history and snapshot rows, like every baseline
        $appearing = [
            'player_skill_history' => [self::ladderPlayer(13) . '|4242|' . $month, self::ladderPlayer(14) . '|4242|' . $month],
            'player_rating_snapshot' => [self::ladderPlayer(13) . '|4242|' . $day, self::ladderPlayer(14) . '|4242|' . $day],
        ];

        // Ladder players 13 and 14 solve only 500-piece puzzles: their kept rows change no gap baseline
        $this->connection->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at) VALUES
                (gen_random_uuid(), :ladder1, 4242, 9999, 5, 'direct', :past),
                (gen_random_uuid(), :ladder2, 4242, 9999, 0, 'extrapolated', :past),
                (gen_random_uuid(), :ladder13, 4242, 9999, 5, 'direct', :later),
                (gen_random_uuid(), :ladder14, 4242, 9999, 0, 'extrapolated', :later)",
            [
                'ladder1' => self::ladderPlayer(1),
                'ladder2' => self::ladderPlayer(2),
                'ladder13' => self::ladderPlayer(13),
                'ladder14' => self::ladderPlayer(14),
                'past' => $past,
                'later' => $later,
            ],
        );
        // Piece counts without skills go whatever computed_at says (the second cleanup, unchanged)
        $this->connection->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at) VALUES
                (gen_random_uuid(), :regular, 500, 0.5, 3, 50.0, 'low', 10, :past),
                (gen_random_uuid(), :ladder1, 1000, 0.5, 3, 50.0, 'low', 10, :later),
                (gen_random_uuid(), :ladder25, 500, 0.5, 3, 50.0, 'low', 10, :later)",
            ['regular' => PlayerFixture::PLAYER_REGULAR, 'ladder1' => self::ladderPlayer(1), 'ladder25' => self::ladderPlayer(25), 'past' => $past, 'later' => $later],
        );
        // Private players go whatever computed_at says (the private cleanup, unchanged); a rating of
        // zero outranks nobody, so no snapshot rank moves
        $this->connection->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES
                (gen_random_uuid(), :regular, 500, 0.5, :past),
                (gen_random_uuid(), :ladder23, 500, 0.5, :later),
                (gen_random_uuid(), :ladder25, 500, 0.0, :later)',
            ['regular' => PlayerFixture::PLAYER_REGULAR, 'ladder23' => self::ladderPlayer(23), 'ladder25' => self::ladderPlayer(25), 'past' => $past, 'later' => $later],
        );
        $this->connection->executeStatement(
            "INSERT INTO global_improvement_ratio (id, pieces_count, from_attempt, gap_bucket, median_ratio, sample_size, computed_at) VALUES
                (gen_random_uuid(), 4242, 1, 'all', 0.9, 10, :past),
                (gen_random_uuid(), 4242, 2, 'all', 0.9, 10, :later)",
            ['past' => $past, 'later' => $later],
        );
        // Ladder players 20 and 21 have no repeat attempts, so no ratios of their own
        $this->connection->executeStatement(
            'INSERT INTO player_improvement_ratio (id, player_id, from_attempt, median_ratio, sample_size, computed_at) VALUES
                (gen_random_uuid(), :ladder20, 1, 0.9, 3, :past),
                (gen_random_uuid(), :ladder21, 1, 0.9, 3, :later)',
            ['ladder20' => self::ladderPlayer(20), 'ladder21' => self::ladderPlayer(21), 'past' => $past, 'later' => $later],
        );
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available) VALUES (:puzzle, 500, 'Ladder puzzle nobody solved', true, true)",
            ['puzzle' => self::ladderPuzzle(99)],
        );
        $this->connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, confidence, sample_size, computed_at) VALUES (:puzzle, 'insufficient', 0, :past)",
            ['puzzle' => self::ladderPuzzle(99), 'past' => $past],
        );
        $this->connection->executeStatement(
            'INSERT INTO player_skill_history (id, player_id, pieces_count, month, baseline_seconds) VALUES (gen_random_uuid(), :regular, 4242, :month, 9999)',
            ['regular' => PlayerFixture::PLAYER_REGULAR, 'month' => $month],
        );
        $this->connection->executeStatement(
            'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, baseline_seconds, computed_at) VALUES (gen_random_uuid(), :regular, 4242, :day, 9999, :past)',
            ['regular' => PlayerFixture::PLAYER_REGULAR, 'day' => $day, 'past' => $past],
        );

        $before = $this->contents();

        $this->recalculate($this->now);

        $after = $this->contents();

        foreach (array_keys(self::TABLES) as $table) {
            $expected = $before[$table];
            $actual = $after[$table];

            foreach ($stale[$table] ?? [] as $rowKey) {
                self::assertArrayHasKey($rowKey, $expected, "{$table}: {$rowKey} was seeded");
                self::assertArrayNotHasKey($rowKey, $actual, "{$table}: {$rowKey} is stale and goes");
                unset($expected[$rowKey]);
            }

            foreach ($kept[$table] as $rowKey) {
                self::assertArrayHasKey($rowKey, $actual, "{$table}: {$rowKey} stays");
            }

            foreach ($appearing[$table] ?? [] as $rowKey) {
                self::assertArrayHasKey($rowKey, $actual, "{$table}: {$rowKey} is recorded");
                unset($actual[$rowKey]);
            }

            // Nothing else changed, not even a row version
            self::assertSame($expected, $actual, $table);
        }
    }

    /**
     * Old vs new. A run over empty tables writes every row, exactly what every run did before
     * 2026-09-30, so it is the reference: after any change of the input and of the stored rows,
     * a run must leave the tables as a run from scratch does (only computed_at and generated ids
     * may differ). Baselines only appear here - history and snapshot rows of a baseline that is
     * gone were never deleted, which the other tests pin.
     */
    public function testTheTablesEndUpAsARecalculationFromScratchLeavesThem(): void
    {
        $yesterday = $this->now->modify('-1 day');
        $this->recalculate($yesterday);
        $this->recalculate($this->now);

        // The input changes...
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 9000 WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(3), 'puzzle' => self::ladderPuzzle(7)],
        );
        $this->connection->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle AND first_attempt = false AND tracked_at > :recently',
            ['player' => self::ladderPlayer(2), 'puzzle' => self::ladderPuzzle(1), 'recently' => $this->now->modify('-45 days')->format('Y-m-d H:i:s')],
        );
        // Player 7 has direct baselines at 500 and 1000 and no 1500-piece solve yet: a new extrapolated baseline
        $this->connection->executeStatement(
            'INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt) VALUES (gen_random_uuid(), :player, :puzzle, 6500, :at, :at, true, true)',
            ['player' => self::ladderPlayer(7), 'puzzle' => self::ladderPuzzle(63), 'at' => $this->now->modify('-10 days')->setTime(3, 0)->format('Y-m-d H:i:s')],
        );
        $this->connection->executeStatement('UPDATE player SET is_private = true WHERE id = :player', ['player' => self::ladderPlayer(22)]);

        // ...and so do the stored rows: wrong values, missing rows, a stale row
        $this->connection->executeStatement('UPDATE player_baseline SET baseline_seconds = 1 WHERE player_id = :player AND pieces_count = 500', ['player' => self::ladderPlayer(4)]);
        $this->connection->executeStatement('DELETE FROM player_skill WHERE player_id = :player', ['player' => self::ladderPlayer(6)]);
        $this->connection->executeStatement('UPDATE global_improvement_ratio SET median_ratio = 0.5');
        $this->connection->executeStatement('DELETE FROM puzzle_difficulty WHERE puzzle_id = :puzzle', ['puzzle' => self::ladderPuzzle(9)]);
        $this->connection->executeStatement('UPDATE puzzle_difficulty SET memorability_score = 7.0 WHERE puzzle_id = :puzzle', ['puzzle' => self::ladderPuzzle(2)]);
        $this->connection->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at) VALUES (gen_random_uuid(), :player, 4242, 9999, 5, 'direct', :past)",
            ['player' => self::ladderPlayer(1), 'past' => $yesterday->format('Y-m-d H:i:s')],
        );
        $this->connection->executeStatement(
            'UPDATE player_rating_snapshot SET elo_rank = 999 WHERE player_id = :player AND snapshot_date = :day',
            ['player' => self::ladderPlayer(8), 'day' => $this->now->format('Y-m-d 00:00:00')],
        );
        $this->connection->executeStatement(
            'UPDATE player_skill_history SET skill_tier = NULL WHERE player_id = :player AND month = :month',
            ['player' => self::ladderPlayer(9), 'month' => $this->now->format('Y-m-01 00:00:00')],
        );

        // Every value column wrong on its own in some row: each one has to be compared
        foreach (self::WRONG_VALUES as $table => $wrongValues) {
            $recomputed = match ($table) {
                'puzzle_difficulty' => "puzzle_id::text LIKE '019a0001-%'",
                'player_skill_history' => "month = '{$this->now->format('Y-m-01 00:00:00')}'",
                'player_rating_snapshot' => "snapshot_date = '{$this->now->format('Y-m-d 00:00:00')}' AND player_id::text LIKE '019a0000-%'",
                default => 'TRUE',
            };

            /** @var list<string> $rowVersions */
            $rowVersions = $this->connection->fetchFirstColumn(
                "SELECT ctid::text FROM {$table} WHERE {$recomputed} ORDER BY " . implode(', ', self::TABLES[$table]) . ' OFFSET 1 LIMIT ' . count($wrongValues),
            );
            self::assertCount(count($wrongValues), $rowVersions, $table);

            foreach ($wrongValues as $column => $wrongValue) {
                $this->connection->executeStatement(
                    "UPDATE {$table} SET {$column} = {$wrongValue} WHERE ctid = CAST(:rowVersion AS tid)",
                    ['rowVersion' => array_shift($rowVersions)],
                );
            }
        }

        $this->connection->beginTransaction();
        $this->recalculate($this->now);
        $incremental = $this->contents();
        $this->connection->rollBack();

        // From scratch: the recomputed tables empty, no history/snapshot rows of the current month/day
        foreach (self::RECOMPUTED_TABLES as $table) {
            $this->connection->executeStatement("DELETE FROM {$table}");
        }
        $this->connection->executeStatement('DELETE FROM player_skill_history WHERE month = :month', ['month' => $this->now->format('Y-m-01 00:00:00')]);
        $this->connection->executeStatement('DELETE FROM player_rating_snapshot WHERE snapshot_date = :day', ['day' => $this->now->format('Y-m-d 00:00:00')]);

        $this->recalculate($this->now);
        $fromScratch = $this->contents();

        foreach (array_keys(self::TABLES) as $table) {
            self::assertNotSame([], $fromScratch[$table], $table);
            self::assertSame(
                array_map(static fn (array $row): string => $row['row_values'], $fromScratch[$table]),
                array_map(static fn (array $row): string => $row['row_values'], $incremental[$table]),
                $table,
            );
        }
    }

    public function testTheFirstRunOfAMonthStillRecordsEveryBaseline(): void
    {
        $lastEvening = new DateTimeImmutable('last day of this month 23:50:00');
        $nextMonth = new DateTimeImmutable('first day of next month 00:05:00');

        $this->recalculate($lastEvening);
        $this->recalculate($nextMonth);

        $baselines = $this->countRows('SELECT COUNT(*) FROM player_baseline');

        foreach ([$lastEvening, $nextMonth] as $moment) {
            self::assertSame($baselines, $this->countRows(
                'SELECT COUNT(*) FROM player_skill_history WHERE month = :month',
                ['month' => $moment->format('Y-m-01 00:00:00')],
            ));
            self::assertSame($baselines, $this->countRows(
                'SELECT COUNT(*) FROM player_rating_snapshot WHERE snapshot_date = :day',
                ['day' => $moment->format('Y-m-d 00:00:00')],
            ));
        }
    }

    private function recalculate(DateTimeImmutable $now): void
    {
        $clock = new MockClock($now);

        (new PuzzleIntelligenceRecalculator(
            $this->connection,
            $clock,
            new PlayerBaselineCalculator($this->connection, $clock),
            new PuzzleDifficultyCalculator($this->connection),
            new PlayerSkillCalculator($this->connection, $clock),
            new DerivedMetricsCalculator($this->connection),
            new MspRatingCalculator($this->connection, $clock),
            new ImprovementRatioCalculator($this->connection),
        ))->recalculate();
    }

    /**
     * Every row of every table by its unique key: its values (all columns but the generated id and
     * computed_at), and apart from them computed_at, the row version (ctid) and the lock marker (xmax).
     *
     * @return array<string, array<string, array{row_values: string, computed_at: null|string, ctid: string, xmax: string}>>
     */
    private function contents(): array
    {
        $contents = [];

        foreach (self::TABLES as $table => $key) {
            $rowKey = implode(", '|', ", array_map(static fn (string $column): string => "t.{$column}::text", $key));
            $computedAt = $table === 'player_skill_history' ? 'NULL' : 't.computed_at::text';

            /** @var list<array{row_key: string, row_values: string, computed_at: null|string, ctid: string, xmax: string}> $rows */
            $rows = $this->connection->fetchAllAssociative("
                SELECT concat({$rowKey}) AS row_key,
                    (to_jsonb(t) - 'id' - 'computed_at')::text AS row_values,
                    {$computedAt} AS computed_at,
                    t.ctid::text AS ctid,
                    t.xmax::text AS xmax
                FROM {$table} t
                ORDER BY 1
            ");

            $contents[$table] = [];

            foreach ($rows as $row) {
                $contents[$table][$row['row_key']] = [
                    'row_values' => $row['row_values'],
                    'computed_at' => $row['computed_at'],
                    'ctid' => $row['ctid'],
                    'xmax' => $row['xmax'],
                ];
            }
        }

        return $contents;
    }

    /**
     * Rows inserted, updated and deleted so far in this test's transaction, per table
     *
     * @return array<string, int>
     */
    private function writes(): array
    {
        /** @var array<string, int|string> $writes */
        $writes = $this->connection->fetchAllKeyValue(
            'SELECT relname, n_tup_ins + n_tup_upd + n_tup_del FROM pg_stat_xact_user_tables WHERE relname IN (:tables) ORDER BY relname',
            ['tables' => array_keys(self::TABLES)],
            ['tables' => ArrayParameterType::STRING],
        );

        return array_map(static fn (int|string $count): int => (int) $count, $writes);
    }

    private function stampComputedAt(string $computedAt): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if ($table !== 'player_skill_history') {
                $this->connection->executeStatement("UPDATE {$table} SET computed_at = :computedAt", ['computedAt' => $computedAt]);
            }
        }
    }

    /**
     * @param array<string, array{row_values: string, computed_at: null|string, ctid: string, xmax: string}> $rows
     * @return array<string, array{row_values: string, computed_at: null|string, ctid: string, xmax: string}>
     */
    private static function fixtureRows(array $rows): array
    {
        return array_filter(
            $rows,
            static fn (string $rowKey): bool => !str_starts_with($rowKey, '019a0000-') && !str_starts_with($rowKey, '019a0001-'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countRows(string $sql, array $params = []): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne($sql, $params);

        return (int) $count;
    }

    private static function ladderPlayer(int $number): string
    {
        return sprintf('019a0000-0000-7000-8000-%012d', $number);
    }

    private static function ladderPuzzle(int $number): string
    {
        return sprintf('019a0001-0000-7000-8000-%012d', $number);
    }

    /**
     * Plain SQL like LeaderboardSeeding: persisting entities would dispatch PuzzleSolved per row.
     * Every solve is at 03:00 and the runs at 12:00, so no age in days moves between runs of a day.
     */
    private function seedLadder(): void
    {
        $today = $this->now->format('Y-m-d');

        $this->connection->executeStatement(
            "INSERT INTO player (id, code, name, registered_at, is_private)
            SELECT CAST('019a0000-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 'ladder' || n, 'Ladder Solver ' || n, CAST(:today AS timestamp) - INTERVAL '3 years', n > 22
            FROM generate_series(1, 24) AS n",
            ['today' => $today],
        );

        // 1-55: 500 pieces, 56-61: 1000, 62: 750, 63: 1500
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available)
            SELECT CAST('019a0001-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid),
                CASE WHEN n <= 55 THEN 500 WHEN n <= 61 THEN 1000 WHEN n = 62 THEN 750 ELSE 1500 END,
                'Ladder Puzzle ' || n, true, true
            FROM generate_series(1, 63) AS n",
        );

        $solves = [
            // First attempts of everybody on every 500-piece puzzle, 100-599 days ago
            'SELECT p, z, 1500 + 60 * p + 23 * z + ((p * 37 + z * 11) % 50) * 9, 100 + (p * 7 + z * 13) % 500, true FROM generate_series(1, 24) AS p CROSS JOIN generate_series(1, 55) AS z',
            // Attempts 2 and 3 of players 1-10 on puzzles 1-5: improvement ratios and memorability
            'SELECT p, z, (1500 + 60 * p + 23 * z) * 85 / 100, 60, false FROM generate_series(1, 10) AS p CROSS JOIN generate_series(1, 5) AS z',
            'SELECT p, z, (1500 + 60 * p + 23 * z) * 75 / 100, 30, false FROM generate_series(1, 10) AS p CROSS JOIN generate_series(1, 5) AS z',
            // Players 1-8: direct 1000-piece baselines
            'SELECT p, z, 2 * (1500 + 60 * p) + 40 * (z - 55) + ((p * 13 + z * 7) % 30) * 11, 120 + (p * 5 + z * 3) % 300, true FROM generate_series(1, 8) AS p CROSS JOIN generate_series(56, 61) AS z',
            // Players 1-4 once at 750 (interpolated), players 1-6 and 9-12 once at 1500 (extrapolated)
            'SELECT p, 62, 3 * (1500 + 60 * p) / 2, 90, true FROM generate_series(1, 4) AS p',
            'SELECT p, 63, 3 * (1500 + 60 * p), 80, true FROM generate_series(1, 12) AS p WHERE p NOT IN (7, 8)',
        ];

        foreach ($solves as $solve) {
            $this->connection->executeStatement(
                "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
                SELECT gen_random_uuid(),
                    CAST('019a0000-0000-7000-8000-' || lpad(s.p::text, 12, '0') AS uuid),
                    CAST('019a0001-0000-7000-8000-' || lpad(s.z::text, 12, '0') AS uuid),
                    s.seconds,
                    CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => s.days_ago),
                    CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => s.days_ago),
                    true,
                    s.first_attempt
                FROM ({$solve}) AS s (p, z, seconds, days_ago, first_attempt)",
                ['today' => $today],
            );
        }
    }
}
