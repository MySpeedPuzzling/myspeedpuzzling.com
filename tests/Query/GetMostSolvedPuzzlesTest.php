<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use SpeedPuzzling\Web\Results\MostSolvedPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMostSolvedPuzzlesTest extends KernelTestCase
{
    private GetMostSolvedPuzzles $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetMostSolvedPuzzles::class);
    }

    public function testTopReturnsPuzzlesSortedBySolvedCount(): void
    {
        $puzzles = $this->query->top(10);

        self::assertNotEmpty($puzzles);

        // Results should be sorted by solved_times descending
        $counts = array_map(fn($p) => $p->solvedTimes, $puzzles);
        $sortedCounts = $counts;
        rsort($sortedCounts);
        self::assertSame($sortedCounts, $counts, 'Results should be sorted by solved count descending');
    }

    public function testTopRespectsLimit(): void
    {
        $puzzles = $this->query->top(3);

        self::assertLessThanOrEqual(3, count($puzzles));
    }

    public function testTopIncludesSoloStatistics(): void
    {
        $puzzles = $this->query->top(10);

        // At least one puzzle should have solo statistics (non-zero values)
        $hasSoloStats = false;
        foreach ($puzzles as $puzzle) {
            if ($puzzle->fastestTimeSolo > 0 || $puzzle->averageTimeSolo > 0) {
                $hasSoloStats = true;
                break;
            }
        }

        self::assertTrue($hasSoloStats, 'At least one puzzle should have solo statistics');
    }

    public function testTopExcludesPuzzlesWithNoSolves(): void
    {
        $puzzles = $this->query->top(100);

        foreach ($puzzles as $puzzle) {
            self::assertGreaterThan(0, $puzzle->solvedTimes, 'All returned puzzles should have at least one solve');
        }

        // PUZZLE_9000 has no solves, should not be in results
        $puzzleIds = array_map(fn($p) => $p->puzzleId, $puzzles);
        self::assertNotContains(
            PuzzleFixture::PUZZLE_9000,
            $puzzleIds,
            'Puzzles with no solves should not appear',
        );
    }

    public function testTopReturnsCorrectPuzzleData(): void
    {
        $puzzles = $this->query->top(10);

        foreach ($puzzles as $puzzle) {
            // Each puzzle should have required fields
            self::assertNotEmpty($puzzle->puzzleId);
            self::assertNotEmpty($puzzle->puzzleName);
            self::assertGreaterThan(0, $puzzle->piecesCount);
            self::assertNotEmpty($puzzle->manufacturerName);
        }
    }

    public function testPuzzle500Pieces01HasMostSolves(): void
    {
        // PUZZLE_500_01 has the most solving times in fixtures - possibly tied with another puzzle
        // (DuplicateResultsFixture), and the order among equal counts is not defined
        $puzzles = $this->query->top(5);

        self::assertNotEmpty($puzzles);

        $mostSolved = array_filter(
            $puzzles,
            static fn (MostSolvedPuzzle $puzzle): bool => $puzzle->solvedTimes === $puzzles[0]->solvedTimes,
        );

        self::assertContains(
            PuzzleFixture::PUZZLE_500_01,
            array_map(static fn (MostSolvedPuzzle $puzzle): string => $puzzle->puzzleId, $mostSolved),
            'PUZZLE_500_01 should have the most solves',
        );
    }

    public function testTopInMonthFiltersByMonth(): void
    {
        // Get current month
        $now = new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');

        // Fixtures are created relative to "now", so recent solves should appear
        $puzzles = $this->query->topInMonth(10, $month, $year);

        // Should not contain any puzzles with zero solves
        foreach ($puzzles as $puzzle) {
            self::assertGreaterThan(0, $puzzle->solvedTimes);
        }
    }

    public function testTopInMonthNeitherCountsNorTimesASuspiciousResult(): void
    {
        $now = new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');
        $monthStart = $now->format('Y-m-01');
        $nextMonthStart = $now->modify('first day of next month')->format('Y-m-d');

        $withTop = array_values(array_filter(
            $this->query->topInMonth(100, $month, $year),
            static fn (MostSolvedPuzzle $puzzle): bool => $puzzle->fastestTimeSolo > 0 && $puzzle->solvedTimes > 1,
        ));
        self::assertNotEmpty($withTop, 'Fixtures must have a puzzle solved more than once this month, solo among others');
        $puzzle = $withTop[0];

        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true
            WHERE puzzle_id = :puzzleId AND team IS NULL AND seconds_to_solve = :fastest AND tracked_at >= :from AND tracked_at < :to',
            ['puzzleId' => $puzzle->puzzleId, 'fastest' => $puzzle->fastestTimeSolo, 'from' => $monthStart, 'to' => $nextMonthStart],
        );
        /** @var array{fastest: null|int, solved: int} $expected */
        $expected = $database->fetchAssociative(
            'SELECT MIN(seconds_to_solve) FILTER (WHERE team IS NULL) AS fastest, COUNT(*) AS solved
            FROM puzzle_solving_time WHERE puzzle_id = :puzzleId AND tracked_at >= :from AND tracked_at < :to AND suspicious = false',
            ['puzzleId' => $puzzle->puzzleId, 'from' => $monthStart, 'to' => $nextMonthStart],
        );

        $after = array_values(array_filter(
            $this->query->topInMonth(100, $month, $year),
            static fn (MostSolvedPuzzle $candidate): bool => $candidate->puzzleId === $puzzle->puzzleId,
        ));

        if ($expected['solved'] === 0) {
            self::assertSame([], $after, 'Nothing left to count');

            return;
        }

        self::assertCount(1, $after);
        self::assertSame($expected['fastest'] ?? 0, $after[0]->fastestTimeSolo);
        self::assertNotSame($puzzle->fastestTimeSolo, $after[0]->fastestTimeSolo);
        self::assertSame($expected['solved'], $after[0]->solvedTimes);
        self::assertLessThan($puzzle->solvedTimes, $after[0]->solvedTimes);
    }

    public function testTopInMonthReturnsEmptyForFutureMonth(): void
    {
        // No solves in a future month
        $puzzles = $this->query->topInMonth(10, 1, 2050);

        self::assertEmpty($puzzles);
    }
}
