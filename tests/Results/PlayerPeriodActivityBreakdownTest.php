<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PeriodResult;
use SpeedPuzzling\Web\Results\PlayerPeriodActivity;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;

final class PlayerPeriodActivityBreakdownTest extends TestCase
{
    public function testOnlySoloResultsNeedNoSplit(): void
    {
        $activity = self::activity([self::periodResult('solo', 1, 500), self::periodResult('solo', 1, 1000)]);

        self::assertSame([], $activity->splitByPuzzlingType());
        self::assertSame([], self::activity([])->splitByPuzzlingType());
    }

    public function testSoloPairsAndTeamsInThatOrderWithOnlyTheKindsThePeriodHolds(): void
    {
        $activity = self::activity([
            self::periodResult('team', 3, 500),
            self::periodResult('solo', 1, 9000),
            self::periodResult('team', 4, 1000),
        ]);

        self::assertSame([
            ['type' => 'solo', 'puzzles' => 1, 'pieces' => 9000],
            ['type' => 'team', 'puzzles' => 2, 'pieces' => 1500],
        ], $activity->splitByPuzzlingType());
    }

    public function testResultsAwaitingVerificationAreNotCounted(): void
    {
        $activity = self::activity([
            self::periodResult('duo', 2, 500),
            self::periodResult('solo', 1, 1000, awaitingVerification: true),
        ]);

        self::assertSame([['type' => 'duo', 'puzzles' => 1, 'pieces' => 500]], $activity->splitByPuzzlingType());
    }

    public function testDaysOfAWeekWithTheBusiestDayAsTheFullBar(): void
    {
        $activity = self::activity([
            self::periodResult('solo', 1, 500, solvedAt: '2026-10-19 10:00'),
            self::periodResult('team', 3, 1000, solvedAt: '2026-10-21 12:00'),
            // 23:30 UTC on Saturday is already Sunday in Prague
            self::periodResult('solo', 1, 250, solvedAt: '2026-10-24 23:30'),
            self::periodResult('solo', 1, 1000, solvedAt: '2026-10-22 12:00', awaitingVerification: true),
        ]);

        $days = $activity->days();

        self::assertCount(7, $days);
        self::assertSame('2026-10-19', $days[0]['day']->format('Y-m-d'));
        self::assertSame([500, 0, 1000, 0, 0, 0, 250], array_column($days, 'pieces'));
        self::assertSame([0.5, 0.0, 1.0, 0.0, 0.0, 0.0, 0.25], array_column($days, 'share'));
    }

    public function testHighlightsShowEachPuzzleOnceWithItsFastestAttemptOfThePeriod(): void
    {
        $activity = self::activity([
            self::periodResult('solo', 1, 500, solvedAt: '2026-10-19 10:00', puzzleId: 'a', seconds: 3000, personalBest: true),
            self::periodResult('solo', 1, 500, solvedAt: '2026-10-20 10:00', puzzleId: 'a', seconds: 2800, personalBest: true),
            self::periodResult('solo', 1, 500, solvedAt: '2026-10-21 10:00', puzzleId: 'a', seconds: 2900),
            self::periodResult('duo', 2, 500, solvedAt: '2026-10-22 10:00', puzzleId: 'a', seconds: 1500),
            self::periodResult('solo', 1, 1000, solvedAt: '2026-10-23 10:00', puzzleId: 'b', seconds: 6000),
        ]);

        $highlights = $activity->highlights(3);

        self::assertCount(3, $highlights, 'Puzzle a solo, puzzle a in a pair, puzzle b');
        self::assertSame(2800, $highlights[0]['result']->secondsToSolve);
        self::assertSame(3, $highlights[0]['attempts']);
        self::assertSame(['b', 'a'], [$highlights[1]['result']->puzzleId, $highlights[2]['result']->puzzleId], 'Then the bigger puzzle');
        self::assertSame(1, $highlights[2]['attempts']);
    }

    public function testNoDaysForAMonthOrAnEmptyPeriod(): void
    {
        self::assertSame([], self::activity([])->days());
        self::assertSame([], self::activity([self::periodResult('solo', 1, 500)], DigestPeriodLength::Month)->days());
    }

    /**
     * @param list<PeriodResult> $results
     */
    private static function activity(array $results, DigestPeriodLength $length = DigestPeriodLength::Week): PlayerPeriodActivity
    {
        return new PlayerPeriodActivity(
            period: DigestPeriod::containing($length, new DateTimeImmutable('2026-10-21 12:00')),
            results: $results,
            puzzlesCount: count($results),
            piecesCount: 0,
            minutesSpent: 0,
            xpVisible: false,
            xpGained: 0,
            levelBefore: 1,
            levelAfter: 1,
            levelProgress: null,
            xpToNextLevel: null,
            isMember: false,
            achievementsEarned: [],
            achievementPointsGained: 0,
            rating: null,
            streakDays: 0,
            favoritesActivity: [],
            mostSolved: null,
            nextAchievements: [],
        );
    }

    private static function periodResult(string $type, int $puzzlers, int $pieces, bool $awaitingVerification = false, string $solvedAt = '2026-10-21 12:00', string $puzzleId = 'puzzle', int $seconds = 3600, bool $personalBest = false): PeriodResult
    {
        return new PeriodResult(
            timeId: 'time',
            puzzleId: $puzzleId,
            puzzleName: 'Puzzle',
            brandName: 'Brand',
            piecesCount: $pieces,
            puzzleImage: null,
            secondsToSolve: $seconds,
            solvedAt: new DateTimeImmutable($solvedAt, new \DateTimeZone('UTC')),
            puzzlingType: $type,
            puzzlersCount: $puzzlers,
            firstAttempt: false,
            personalBest: $personalBest,
            awaitingVerification: $awaitingVerification,
            xp: 0,
        );
    }
}
