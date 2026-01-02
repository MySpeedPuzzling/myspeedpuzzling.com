<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
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

    public function testPuzzle500_01HasMostSolves(): void
    {
        // PUZZLE_500_01 has the most solving times in fixtures
        $puzzles = $this->query->top(1);

        self::assertNotEmpty($puzzles);

        // The first puzzle should be PUZZLE_500_01 (has many solves)
        self::assertSame(
            PuzzleFixture::PUZZLE_500_01,
            $puzzles[0]->puzzleId,
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

    public function testTopInMonthReturnsEmptyForFutureMonth(): void
    {
        // No solves in a future month
        $puzzles = $this->query->topInMonth(10, 1, 2050);

        self::assertEmpty($puzzles);
    }
}
