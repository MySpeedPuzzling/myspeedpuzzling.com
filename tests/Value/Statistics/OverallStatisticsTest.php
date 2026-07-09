<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\Statistics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\Statistics\OverallStatistics;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;

final class OverallStatisticsTest extends TestCase
{
    /**
     * Reproduces the production bug: a day whose only solve is a relax solve (no measured
     * time) must not break the streak. Days 2026-07-01..08 are consecutive; 2026-07-06 has
     * only a relax solve. The longest streak must therefore be 8, not 5.
     */
    public function testRelaxOnlyDayDoesNotBreakStreak(): void
    {
        $solo = new PerCategoryStatistics([
            self::createSolvedPuzzle('2026-07-01', time: 1000),
            self::createSolvedPuzzle('2026-07-02', time: 1000),
            self::createSolvedPuzzle('2026-07-03', time: 1000),
            self::createSolvedPuzzle('2026-07-04', time: 1000),
            self::createSolvedPuzzle('2026-07-05', time: 1000),
            self::createSolvedPuzzle('2026-07-06', time: null), // relax solve, no measured time
            self::createSolvedPuzzle('2026-07-07', time: 1000),
            self::createSolvedPuzzle('2026-07-08', time: 1000),
        ]);

        $empty = new PerCategoryStatistics([]);

        $overall = new OverallStatistics($solo, $empty, $empty);

        self::assertSame(8, $overall->longestStreak);
    }

    /**
     * A relax solve on one category and a timed solve on another, both filling different
     * days of the same run, must still combine into one continuous streak.
     */
    public function testRelaxDayCountsAcrossCategories(): void
    {
        $solo = new PerCategoryStatistics([
            self::createSolvedPuzzle('2026-07-01', time: 1000),
            self::createSolvedPuzzle('2026-07-03', time: 1000),
        ]);

        // 2026-07-02 exists only as a relax solve in the duo category.
        $duo = new PerCategoryStatistics([
            self::createSolvedPuzzle('2026-07-02', time: null),
        ]);

        $empty = new PerCategoryStatistics([]);

        $overall = new OverallStatistics($solo, $duo, $empty);

        self::assertSame(3, $overall->longestStreak);
    }

    /**
     * A genuine gap (a missing calendar day with no solve of any kind) still breaks the streak.
     */
    public function testGapStillBreaksStreak(): void
    {
        $solo = new PerCategoryStatistics([
            self::createSolvedPuzzle('2026-07-01', time: 1000),
            self::createSolvedPuzzle('2026-07-02', time: null),
            self::createSolvedPuzzle('2026-07-03', time: 1000),
            // 2026-07-04 missing entirely -> gap
            self::createSolvedPuzzle('2026-07-05', time: 1000),
        ]);

        $empty = new PerCategoryStatistics([]);

        $overall = new OverallStatistics($solo, $empty, $empty);

        self::assertSame(3, $overall->longestStreak);
    }

    private static function createSolvedPuzzle(string $finishedDate, null|int $time): SolvedPuzzle
    {
        return new SolvedPuzzle(
            timeId: 'time-' . $finishedDate,
            playerId: 'player-1',
            playerName: 'player-1',
            playerCode: 'PLAYER-1',
            playerCountry: null,
            puzzleId: 'puzzle-1',
            puzzleName: 'Puzzle 1',
            puzzleAlternativeName: null,
            manufacturerName: 'Manufacturer',
            piecesCount: 500,
            time: $time,
            puzzleImage: null,
            puzzleImageRatio: null,
            comment: null,
            trackedAt: new DateTimeImmutable($finishedDate . ' 12:00:00'),
            finishedPuzzlePhoto: null,
            teamId: null,
            players: null,
            solvedTimes: 1,
            puzzleIdentificationNumber: null,
            finishedAt: new DateTimeImmutable($finishedDate . ' 12:00:00'),
            firstAttempt: false,
            unboxed: false,
            isPrivate: false,
            competitionId: null,
            competitionShortcut: null,
            competitionName: null,
            competitionSlug: null,
        );
    }
}
