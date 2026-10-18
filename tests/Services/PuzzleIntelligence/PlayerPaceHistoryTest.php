<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\BackfillPaceHistory;
use SpeedPuzzling\Web\Results\PaceHistorySyncResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryWriter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * player_pace_history (one row per qualifying first attempt from the 5th on, per player and piece
 * count) and the as-of difficulty indices built on it. Solves are seeded with plain SQL - persisting
 * entities would run the incremental recalculation per row.
 */
final class PlayerPaceHistoryTest extends KernelTestCase
{
    private Connection $connection;
    private PlayerPaceHistoryWriter $writer;
    private DateTimeImmutable $today;
    private int $timeNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->writer = $container->get(PlayerPaceHistoryWriter::class);

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->today = $clock->now()->setTime(0, 0);

        $this->connection->executeStatement(
            "INSERT INTO player (id, code, name, registered_at) SELECT CAST('019a00a0-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 'pace' || n, 'Pace Solver ' || n, NOW() - INTERVAL '3 years' FROM generate_series(1, 9) AS n",
        );
        // 1-30: 500 pieces, 31-33: 1000 pieces
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available) SELECT CAST('019a00a1-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), CASE WHEN n <= 30 THEN 500 ELSE 1000 END, 'Pace Puzzle ' || n, true, true FROM generate_series(1, 33) AS n",
        );
    }

    public function testOneRowPerQualifyingFirstAttemptFromTheFifthOnWithTheBaselineAfterIt(): void
    {
        $times = [];

        foreach ([3000, 2800, 3100, 2600, 2900, 2500, 2400] as $index => $seconds) {
            $times[] = $this->solve(1, $index + 1, $seconds, 70 - 10 * $index);
        }

        // Neither a repeat nor a suspicious or unboxed solve is a qualifying first attempt
        $this->solve(1, 1, 1000, 1, firstAttempt: false);
        $this->solve(1, 8, 1000, 1, suspicious: true);
        $this->solve(1, 9, 1000, 1, unboxed: true);

        $this->writer->sync();

        $rows = $this->rows(1);
        self::assertCount(3, $rows);

        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = self::getContainer()->get(PlayerBaselineCalculator::class);
        $solves = [];

        foreach ([3000, 2800, 3100, 2600, 2900, 2500, 2400] as $index => $seconds) {
            $solves[] = ['seconds' => $seconds, 'solved_at' => new DateTimeImmutable($this->daysAgo(70 - 10 * $index)->format('Y-m-d H:i:s'))];
        }

        $previous = null;

        foreach ($rows as $position => $row) {
            $count = $position + 5;
            $expected = $baselineCalculator->weightedBaselineSeconds(array_slice($solves, 0, $count), $solves[$count - 1]['solved_at']);

            self::assertSame($count, $row['qualifying_count']);
            self::assertSame($times[$count - 1], $row['time_id']);
            self::assertSame($this->daysAgo(70 - 10 * ($count - 1))->format('Y-m-d H:i:s'), $row['valid_from']);
            self::assertSame($expected, $row['baseline_seconds']);
            self::assertSame($previous === null ? null : round(log($expected / $previous), 6), $row['step']);
            self::assertNull($row['drift']);

            $previous = $expected;
        }
    }

    public function testSameDaySolvesAreOrderedByTrackedAt(): void
    {
        foreach ([1, 2, 3, 4] as $puzzle) {
            $this->solve(2, $puzzle, 3000, 40 - $puzzle);
        }

        // finished_at is a date: tracked_at puts the 18:00 solve after the 09:00 one
        $late = $this->solve(2, 5, 2000, 10, trackedHour: 18);
        $early = $this->solve(2, 6, 2500, 10, trackedHour: 9);

        $this->writer->sync();

        self::assertSame([$early, $late], array_column($this->rows(2), 'time_id'));
    }

    public function testASecondSyncWritesNothing(): void
    {
        $this->seedHistories();

        $first = $this->writer->sync();
        self::assertGreaterThan(0, $first->inserted);

        $before = $this->snapshot(withRowVersions: true);
        $second = $this->writer->sync();

        self::assertSame(0, $second->changedPairs);
        self::assertSame(0, $second->inserted);
        self::assertSame(0, $second->deleted);
        self::assertSame($before, $this->snapshot(withRowVersions: true), 'Not a row version moved');
    }

    /**
     * Every kind of change between two runs: the incremental run leaves exactly the rows a rebuild
     * from scratch does, and rewrites only the pairs that changed.
     */
    public function testAnIncrementalRunEndsAsARebuildFromScratch(): void
    {
        $this->seedHistories();
        $this->writer->sync();
        $untouched = $this->rows(9);

        // Player 1: a new solve today; player 2: a back-dated one between old solves
        $this->solve(1, 20, 1800, 0);
        $this->solve(2, 21, 1700, 55);
        // Player 3: an early solve (no row of its own) gets a new time - every row moves
        $this->connection->executeStatement("UPDATE puzzle_solving_time SET seconds_to_solve = 5000 WHERE player_id = :player AND puzzle_id = :puzzle", ['player' => self::player(3), 'puzzle' => self::puzzle(2)]);
        // Player 4: a time deleted (its row goes with it at once), player 5: one turns suspicious
        $this->connection->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle', ['player' => self::player(4), 'puzzle' => self::puzzle(6)]);
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle', ['player' => self::player(5), 'puzzle' => self::puzzle(3)]);
        // Player 6: unboxed; player 7: a later repeat flagged as the first attempt
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET unboxed = true WHERE player_id = :player AND puzzle_id = :puzzle', ['player' => self::player(6), 'puzzle' => self::puzzle(7)]);
        $this->connection->executeStatement('UPDATE puzzle_solving_time SET first_attempt = false WHERE player_id = :player AND puzzle_id = :puzzle', ['player' => self::player(7), 'puzzle' => self::puzzle(1)]);
        $this->solve(7, 1, 1500, 2, firstAttempt: true);
        // Player 8 falls below five qualifying first attempts: no pace at all
        $this->connection->executeStatement("UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id::text < '019a00a1-0000-7000-8000-000000000009'", ['player' => self::player(8)]);

        $result = $this->writer->sync();
        $incremental = $this->snapshot();

        self::assertSame(8, $result->changedPairs, 'Players 1-8 changed, player 9 did not');
        self::assertSame($untouched, $this->rows(9));
        self::assertSame([], $this->rows(8));

        $this->connection->executeStatement('DELETE FROM player_pace_history');
        $this->writer->sync();

        self::assertSame($this->snapshot(), $incremental);
    }

    public function testTheBackfillIsIdempotent(): void
    {
        $this->seedHistories();

        $first = $this->backfill();
        $rows = $this->snapshot();
        self::assertGreaterThan(0, $first->inserted);

        $second = $this->backfill();

        self::assertSame(0, $second->inserted + $second->deleted);
        self::assertSame($rows, $this->snapshot());

        // ... and leaves what the batch writes
        $this->connection->executeStatement('DELETE FROM player_pace_history');
        $this->writer->sync();
        self::assertSame($rows, $this->snapshot());
    }

    /**
     * Five players were twice as slow when they solved the puzzle as they are today. Divided by
     * today's pace the puzzle would look twice as hard; divided by the pace of then it is average.
     */
    public function testTheDifficultyIndexDividesByThePaceBeforeTheSolve(): void
    {
        $target = 30;

        foreach ([1, 2, 3, 4, 5] as $player) {
            foreach ([1, 2, 3, 4, 5] as $puzzle) {
                $this->solve($player, $puzzle, 3000, 420 - $puzzle);
            }

            $this->solve($player, $target, 3000, 400);

            foreach ([6, 7, 8, 9, 10, 11] as $puzzle) {
                $this->solve($player, $puzzle, 1500, 40 - $puzzle);
            }
        }

        $this->recalculate();

        /** @var array{difficulty_score: float|string, sample_size: int|string, sum_log_indices: float|string} $stored */
        $stored = $this->connection->fetchAssociative('SELECT difficulty_score, sample_size, sum_log_indices FROM puzzle_difficulty WHERE puzzle_id = :puzzle', ['puzzle' => self::puzzle($target)]);
        self::assertEqualsWithDelta(1.0, (float) $stored['difficulty_score'], 0.0001);
        self::assertSame(5, (int) $stored['sample_size']);
        self::assertEqualsWithDelta(0.0, (float) $stored['sum_log_indices'], 0.000001);

        /** @var int|string $todaysBaseline */
        $todaysBaseline = $this->connection->fetchOne('SELECT baseline_seconds FROM player_baseline WHERE player_id = :player AND pieces_count = 500', ['player' => self::player(1)]);
        self::assertSame(1500, (int) $todaysBaseline, 'Today the same solve would be an index of 2');

        // The single-puzzle path (incremental handler, --puzzle) agrees with the batch
        /** @var PuzzleDifficultyCalculator $difficultyCalculator */
        $difficultyCalculator = self::getContainer()->get(PuzzleDifficultyCalculator::class);
        $single = $difficultyCalculator->calculateForPuzzle(self::puzzle($target));
        self::assertSame((float) $stored['difficulty_score'], $single['difficulty_score']);
        self::assertSame((float) $stored['sum_log_indices'], $single['sum_log_indices']);
    }

    /**
     * Two players whose first ever solves are the puzzle (no pace yet then) and three with a
     * 500-piece pace only, solving a 1000-piece puzzle: the first pace, and the extrapolated pace
     * of then.
     */
    public function testIndicesWithoutAPaceRowBeforeTheSolve(): void
    {
        foreach ([1, 2] as $player) {
            $this->solve($player, 20, 2000, 100);

            foreach ([1, 2, 3, 4] as $puzzle) {
                $this->solve($player, $puzzle, 2000, 90 - $puzzle);
            }
        }

        foreach ([3, 4, 5] as $player) {
            foreach ([1, 2, 3, 4, 5] as $puzzle) {
                $this->solve($player, $puzzle, 2000, 300 - $puzzle);
            }

            $this->solve($player, 31, 5000, 200);
        }

        $this->writer->sync();

        /** @var PuzzleDifficultyCalculator $difficultyCalculator */
        $difficultyCalculator = self::getContainer()->get(PuzzleDifficultyCalculator::class);
        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = self::getContainer()->get(PlayerBaselineCalculator::class);

        $firstPace = $difficultyCalculator->calculateForPuzzle(self::puzzle(20));
        self::assertSame(2, $firstPace['sample_size']);
        self::assertEqualsWithDelta(0.0, $firstPace['sum_log_indices'], 0.000001, 'Index 1: the first pace is 2000');

        $extrapolated = $baselineCalculator->extrapolateBaseline(1000, 500, 2000, $baselineCalculator->computeScalingExponent());
        $otherPieceCount = $difficultyCalculator->calculateForPuzzle(self::puzzle(31));
        self::assertSame(3, $otherPieceCount['sample_size']);
        self::assertEqualsWithDelta(3 * log(5000 / $extrapolated), $otherPieceCount['sum_log_indices'], 0.000001);
    }

    private function seedHistories(): void
    {
        foreach (range(1, 9) as $player) {
            foreach (range(1, 12) as $puzzle) {
                $this->solve($player, $puzzle, 2000 + 37 * $player + ((7 * $puzzle * $player) % 23) * 41, 200 - 15 * $puzzle + $player);
            }

            // A second piece count for some: rows per pair
            if ($player % 3 === 0) {
                foreach ([31, 32, 33] as $puzzle) {
                    $this->solve($player, $puzzle, 6000 + 11 * $player, 150 - $puzzle);
                }
            }
        }
    }

    private function recalculate(): void
    {
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
    }

    private function backfill(): PaceHistorySyncResult
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $result = $bus->dispatch(new BackfillPaceHistory())->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PaceHistorySyncResult::class, $result);

        return $result;
    }

    private function solve(int $player, int $puzzle, int $seconds, int $daysAgo, bool $firstAttempt = true, bool $suspicious = false, bool $unboxed = false, int $trackedHour = 12): string
    {
        $id = sprintf('019a00a2-0000-7000-8000-%012d', ++$this->timeNumber);
        $day = $this->daysAgo($daysAgo);

        $this->connection->executeStatement(
            'INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, suspicious, unboxed)
            VALUES (:id, :player, :puzzle, :seconds, :tracked, :finished, true, :first, :suspicious, :unboxed)',
            [
                'id' => $id,
                'player' => self::player($player),
                'puzzle' => self::puzzle($puzzle),
                'seconds' => $seconds,
                'tracked' => $day->setTime($trackedHour, 0)->format('Y-m-d H:i:s'),
                'finished' => $day->format('Y-m-d H:i:s'),
                'first' => $firstAttempt ? 'true' : 'false',
                'suspicious' => $suspicious ? 'true' : 'false',
                'unboxed' => $unboxed ? 'true' : 'false',
            ],
        );

        return $id;
    }

    /**
     * @return list<array{qualifying_count: int, time_id: string, valid_from: string, baseline_seconds: int, step: null|float, drift: null|float}>
     */
    private function rows(int $player): array
    {
        /** @var list<array{qualifying_count: int|string, time_id: string, valid_from: string, baseline_seconds: int|string, step: null|float|string, drift: null|float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT qualifying_count, time_id, valid_from, baseline_seconds, step, drift FROM player_pace_history WHERE player_id = :player AND pieces_count = 500 ORDER BY qualifying_count',
            ['player' => self::player($player)],
        );

        return array_map(static fn (array $row): array => [
            'qualifying_count' => (int) $row['qualifying_count'],
            'time_id' => $row['time_id'],
            'valid_from' => $row['valid_from'],
            'baseline_seconds' => (int) $row['baseline_seconds'],
            'step' => $row['step'] !== null ? (float) $row['step'] : null,
            'drift' => $row['drift'] !== null ? (float) $row['drift'] : null,
        ], $rows);
    }

    /**
     * @return list<string>
     */
    private function snapshot(bool $withRowVersions = false): array
    {
        $rowVersion = $withRowVersions ? ", '|', ctid::text, '|', xmax::text" : '';

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            "SELECT concat((to_jsonb(h) - 'id')::text{$rowVersion}) FROM player_pace_history h ORDER BY player_id, pieces_count, qualifying_count",
        );
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return $this->today->modify("-{$days} days");
    }

    private static function player(int $number): string
    {
        return sprintf('019a00a0-0000-7000-8000-%012d', $number);
    }

    private static function puzzle(int $number): string
    {
        return sprintf('019a00a1-0000-7000-8000-%012d', $number);
    }
}
