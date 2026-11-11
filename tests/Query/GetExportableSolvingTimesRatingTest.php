<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ResultRatingStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The export's msp_rating columns say what the rating batch did with each result, and the progress towards a first
 * rating counts first attempts on counting puzzles only (user report 2026-11-09: "my list came up with different
 * figures from yours").
 */
final class GetExportableSolvingTimesRatingTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    private const int PLAYER = 11;

    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->now = TestClock::now()->modify('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($this->now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;

        $this->seedLadder($this->connection, $this->now);

        // 56: 10 first-try solvers incl. the player - does not count yet
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available) VALUES (CAST(:id AS uuid), 500, 'Insights Puzzle 56', true, true)",
            ['id' => self::ladderPuzzle(56)],
        );
        $this->seedSolves($this->connection, $this->now, [
            'SELECT p, 56, 1700 + 50 * p, 50 + p, true FROM generate_series(6, 15) AS p',
            // A slower second go on puzzle 20, 10 days ago: neither its first attempt nor its best time
            'SELECT ' . self::PLAYER . ', 20, 9000, 10, false',
        ]);

        // The export lists results with their brand
        $this->connection->executeStatement(
            "INSERT INTO manufacturer (id, name, approved) VALUES ('019b0002-0000-7000-8000-000000000001', 'Insights Brand', true)",
        );
        $this->connection->executeStatement(
            "UPDATE puzzle SET manufacturer_id = '019b0002-0000-7000-8000-000000000001' WHERE id::text LIKE '019b0001-0000-7000-8000-%'",
        );

        // Puzzle 7: the player's only result is 800 days old - out of the 24 months
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET tracked_at = CAST(:at AS timestamp), finished_at = CAST(:at AS timestamp) WHERE player_id = :player AND puzzle_id = :puzzle',
            ['at' => $this->now->setTime(3, 0)->modify('-800 days')->format('Y-m-d H:i:s'), 'player' => self::ladderPlayer(self::PLAYER), 'puzzle' => self::ladderPuzzle(7)],
        );

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        // Saved after the batch ran: a faster go on puzzle 30 is the new best time, not in the stored entries yet
        $this->seedSolves($this->connection, $this->now, [
            'SELECT ' . self::PLAYER . ', 30, 600, 0, false',
        ]);
    }

    public function testEveryResultSaysWhetherItCountsAndWhyNot(): void
    {
        $rows = $this->rowsByPuzzle();

        $counted = $rows[self::ladderPuzzle(1)];
        self::assertCount(1, $counted);
        self::assertSame(ResultRatingStatus::FIRST_ATTEMPT_AND_BEST_TIME, $counted[0]->mspRating);
        self::assertNull($counted[0]->mspRatingReason);
        self::assertSame(22, $counted[0]->mspRatingPuzzleFirstTrySolvers);

        $notRatedYet = $rows[self::ladderPuzzle(56)];
        self::assertSame(ResultRatingStatus::NOT_COUNTED, $notRatedYet[0]->mspRating);
        self::assertSame(ResultRatingStatus::REASON_TOO_FEW_SOLVERS, $notRatedYet[0]->mspRatingReason);
        self::assertSame(10, $notRatedYet[0]->mspRatingPuzzleFirstTrySolvers);

        $old = $rows[self::ladderPuzzle(7)];
        self::assertSame(ResultRatingStatus::REASON_OLDER_THAN_WINDOW, $old[0]->mspRatingReason);

        $repeat = self::bySeconds($rows[self::ladderPuzzle(20)]);
        self::assertSame(ResultRatingStatus::REASON_NOT_BEST_TIME, $repeat[9000]->mspRatingReason);
        unset($repeat[9000]);
        self::assertSame(ResultRatingStatus::FIRST_ATTEMPT_AND_BEST_TIME, array_values($repeat)[0]->mspRating);

        $afterTheRun = self::bySeconds($rows[self::ladderPuzzle(30)]);
        self::assertSame(ResultRatingStatus::REASON_NOT_COMPUTED_YET, $afterTheRun[600]->mspRatingReason);
        unset($afterTheRun[600]);
        // The stored best time is no longer the player's fastest result: only its first attempt counts as stored
        self::assertSame(ResultRatingStatus::FIRST_ATTEMPT, array_values($afterTheRun)[0]->mspRating);
    }

    public function testTheProgressCountsFirstAttemptsOnCountingPuzzlesOnly(): void
    {
        /** @var MspRatingCalculator $calculator */
        $calculator = self::getContainer()->get(MspRatingCalculator::class);

        // 55 ladder puzzles: puzzle 7 is out of the window; puzzle 56 counts in total solves but not as a first attempt
        self::assertSame(
            ['first_attempts' => 54, 'first_attempts_not_counted_yet' => 1, 'total_solves' => 55],
            $calculator->qualificationProgress(self::ladderPlayer(self::PLAYER), 500),
        );

        // What the batch decides eligibility by still counts every puzzle
        self::assertSame(
            ['first_attempts' => 55, 'total_solves' => 55],
            $calculator->getProgress(self::ladderPlayer(self::PLAYER), 500),
        );
    }

    /**
     * @return array<string, list<ExportableSolvingTime>>
     */
    private function rowsByPuzzle(): array
    {
        /** @var GetExportableSolvingTimes $query */
        $query = self::getContainer()->get(GetExportableSolvingTimes::class);
        $rows = [];

        foreach ($query->byPlayerId(self::ladderPlayer(self::PLAYER)) as $row) {
            $rows[$row->puzzleId][] = $row;
        }

        return $rows;
    }

    /**
     * @param list<ExportableSolvingTime> $rows
     * @return array<int, ExportableSolvingTime>
     */
    private static function bySeconds(array $rows): array
    {
        $bySeconds = [];

        foreach ($rows as $row) {
            self::assertNotNull($row->secondsToSolve);
            $bySeconds[$row->secondsToSolve] = $row;
        }

        return $bySeconds;
    }
}
