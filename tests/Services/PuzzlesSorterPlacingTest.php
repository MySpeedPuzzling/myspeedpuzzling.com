<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Tests\Value\PlacingFilterTest;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * The profile results' placing and prediction sorts (feature request #292)
 */
final class PuzzlesSorterPlacingTest extends TestCase
{
    public function testByPlaceTheBestFirstAndTheBiggerBoardOnATie(): void
    {
        $groups = self::puzzleGroups(['a', 'b', 'c', 'd']);
        $ranking = self::ranking(['a' => [5, 100], 'b' => [1, 20], 'c' => [1, 300], 'd' => [40, 50]]);

        self::assertSame(['c', 'b', 'a', 'd'], self::puzzleIds((new PuzzlesSorter())->sortGroupedByPlacing($groups, $ranking, byShare: false, worstFirst: false, onlyFirstTries: false)));
        self::assertSame(['d', 'a', 'c', 'b'], self::puzzleIds((new PuzzlesSorter())->sortGroupedByPlacing($groups, $ranking, byShare: false, worstFirst: true, onlyFirstTries: false)));
    }

    public function testByShareTheBoardsWithAShareFirstAndTheSmallOnesAfterThem(): void
    {
        // a = top 2 %, b = top 25 %, c = #1 of 3 (no share), d = #2 of 9 (no share), e = top 90 %
        $groups = self::puzzleGroups(['a', 'b', 'c', 'd', 'e']);
        $ranking = self::ranking(['a' => [40, 2000], 'b' => [3, 12], 'c' => [1, 3], 'd' => [2, 9], 'e' => [90, 100]]);
        $sorter = new PuzzlesSorter();

        self::assertSame(['a', 'b', 'e', 'c', 'd'], self::puzzleIds($sorter->sortGroupedByPlacing($groups, $ranking, byShare: true, worstFirst: false, onlyFirstTries: false)));
        self::assertSame(['e', 'b', 'a', 'd', 'c'], self::puzzleIds($sorter->sortGroupedByPlacing($groups, $ranking, byShare: true, worstFirst: true, onlyFirstTries: false)));
    }

    public function testByShareAFirstPlaceComesBeforeEveryShare(): void
    {
        // a = top 1.1 % (#4 of 363), b = #1 of 49 (top 2.04 %), c = #1 of 200, d = top 2.0 % (#19 of 952)
        $groups = self::puzzleGroups(['a', 'b', 'c', 'd']);
        $ranking = self::ranking(['a' => [4, 363], 'b' => [1, 49], 'c' => [1, 200], 'd' => [19, 952]]);
        $sorter = new PuzzlesSorter();

        self::assertSame(['c', 'b', 'a', 'd'], self::puzzleIds($sorter->sortGroupedByPlacing($groups, $ranking, byShare: true, worstFirst: false, onlyFirstTries: false)));
        self::assertSame(['d', 'a', 'b', 'c'], self::puzzleIds($sorter->sortGroupedByPlacing($groups, $ranking, byShare: true, worstFirst: true, onlyFirstTries: false)));
    }

    public function testAPuzzleWithoutARankGoesLastEitherWay(): void
    {
        $groups = self::puzzleGroups(['unranked', 'a', 'b']);
        $ranking = self::ranking(['a' => [1, 100], 'b' => [60, 100]]);
        $sorter = new PuzzlesSorter();

        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$byShare, $worstFirst]) {
            $sorted = self::puzzleIds($sorter->sortGroupedByPlacing($groups, $ranking, $byShare, $worstFirst, onlyFirstTries: false));
            self::assertSame('unranked', $sorted[2]);
        }
    }

    public function testThePredictionOfTheLatestAttemptDecides(): void
    {
        $groups = [
            // Latest attempt 10 % behind - an older attempt was well ahead
            'a' => [
                self::solved('a1', 'a', time: 800, predicted: 1000, finishedAt: '2026-01-01'),
                self::solved('a2', 'a', time: 1100, predicted: 1000, finishedAt: '2026-03-01'),
            ],
            // 20 % ahead
            'b' => [self::solved('b1', 'b', time: 800, predicted: 1000, finishedAt: '2026-02-01')],
            // The latest attempt was not predicted
            'c' => [
                self::solved('c1', 'c', time: 500, predicted: 1000, finishedAt: '2026-01-01'),
                self::solved('c2', 'c', time: 900, predicted: null, finishedAt: '2026-04-01'),
            ],
            // 5 % ahead
            'd' => [self::solved('d1', 'd', time: 950, predicted: 1000, finishedAt: '2026-02-01')],
        ];
        $sorter = new PuzzlesSorter();

        self::assertSame(['b', 'd', 'a', 'c'], self::puzzleIds($sorter->sortGroupedByPrediction($groups, aheadFirst: true, onlyFirstTries: false)));
        self::assertSame(['a', 'd', 'b', 'c'], self::puzzleIds($sorter->sortGroupedByPrediction($groups, aheadFirst: false, onlyFirstTries: false)));
    }

    public function testTheLatestAttemptOfTheSameDayIsTheOneAddedLater(): void
    {
        $earlier = self::solved('t1', 'a', time: 900, predicted: 1000, finishedAt: '2026-02-01', trackedAt: '2026-02-01 10:00');
        $later = self::solved('t2', 'a', time: 1200, predicted: 1000, finishedAt: '2026-02-01', trackedAt: '2026-02-01 18:00');

        self::assertSame('t2', PuzzlesSorter::latestAttempt([$later, $earlier])?->timeId);
        self::assertSame('t2', PuzzlesSorter::latestAttempt([$earlier, $later])?->timeId);
        self::assertSame(1.2, PuzzlesSorter::predictionRatio($later));
        self::assertNull(PuzzlesSorter::predictionRatio(self::solved('t3', 'a', time: 900, predicted: null, finishedAt: '2026-02-01')));
    }

    public function testATimeWaitingForVerificationIsNoLatestAttempt(): void
    {
        $verified = self::solved('t1', 'a', time: 900, predicted: 1000, finishedAt: '2026-01-01');
        $suspicious = self::solved('t2', 'a', time: 100, predicted: 1000, finishedAt: '2026-02-01', suspicious: true);

        self::assertSame('t1', PuzzlesSorter::latestAttempt([$verified, $suspicious])?->timeId);
        self::assertNull(PuzzlesSorter::latestAttempt([$suspicious]));
    }

    /**
     * "Slowest" by the row's time (the best one), never by the slowest attempt - that is usually the first try
     */
    public function testSlowestGoesByTheBestTimeOfEachPuzzle(): void
    {
        $sorter = new PuzzlesSorter();
        $groups = [
            'a' => [self::solved('a-first', 'a', time: 3000, predicted: null, finishedAt: '2026-01-01', firstAttempt: true), self::solved('a-best', 'a', time: 1000, predicted: null, finishedAt: '2026-02-01')],
            'b' => [self::solved('b-first', 'b', time: 2000, predicted: null, finishedAt: '2026-01-01', firstAttempt: true)],
        ];

        $slowest = $sorter->sortGoupedBySlowest($groups, onlyFirstTries: false);
        self::assertSame(['b', 'a'], self::puzzleIds($slowest));
        self::assertSame('a-best', $slowest[1][0]->timeId);
        self::assertSame(['b', 'a'], self::puzzleIds($sorter->sortGroupedBySlowestPpm($groups, onlyFirstTries: false)));

        // Under "first tries only" the row shows the first try, and that time places it
        $firstTries = $sorter->sortGoupedBySlowest($groups, onlyFirstTries: true);
        self::assertSame(['a', 'b'], self::puzzleIds($firstTries));
        self::assertSame('a-first', $firstTries[0][0]->timeId);
    }

    public function testSlowestPairResultsGoByTheBestTimeOfEachPair(): void
    {
        $sorter = new PuzzlesSorter();
        $results = [
            self::solved('a-first', 'a', time: 3000, predicted: null, finishedAt: '2026-01-01', firstAttempt: true, teamId: 'pair-1'),
            self::solved('b-first', 'b', time: 2000, predicted: null, finishedAt: '2026-01-01', firstAttempt: true, teamId: 'pair-1'),
            self::solved('a-best', 'a', time: 1000, predicted: null, finishedAt: '2026-02-01', teamId: 'pair-1'),
        ];

        $timeIds = static fn (array $sorted): array => array_map(
            static fn (SolvedPuzzle $solved): string => $solved->timeId,
            array_values(array_filter($sorted, static fn (mixed $solved): bool => $solved instanceof SolvedPuzzle)),
        );

        self::assertSame(['b-first', 'a-best', 'a-first'], $timeIds($sorter->sortTeamResultsBySlowest($results, byPpm: false, onlyFirstTries: false, onlyUnboxed: false)));
        self::assertSame(['b-first', 'a-best', 'a-first'], $timeIds($sorter->sortTeamResultsBySlowest($results, byPpm: true, onlyFirstTries: false, onlyUnboxed: false)));
        self::assertSame(['a-first', 'a-best', 'b-first'], $timeIds($sorter->sortTeamResultsBySlowest($results, byPpm: false, onlyFirstTries: true, onlyUnboxed: false)));
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, list<SolvedPuzzle>>
     */
    private static function puzzleGroups(array $puzzleIds): array
    {
        $groups = [];

        foreach ($puzzleIds as $index => $puzzleId) {
            $groups[$puzzleId] = [self::solved('time-' . $puzzleId, $puzzleId, time: 1000 + $index, predicted: null, finishedAt: '2026-01-01')];
        }

        return $groups;
    }

    /**
     * @param array<string, array{int, int}> $ranks
     * @return array<string, PlayerRanking>
     */
    private static function ranking(array $ranks): array
    {
        $ranking = [];

        foreach ($ranks as $puzzleId => [$rank, $total]) {
            $ranking[$puzzleId] = PlacingFilterTest::ranking($rank, $total, $puzzleId);
        }

        return $ranking;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groups
     * @return list<string>
     */
    private static function puzzleIds(array $groups): array
    {
        $puzzleIds = [];

        foreach ($groups as $group) {
            $first = reset($group);
            self::assertNotFalse($first);
            $puzzleIds[] = $first->puzzleId;
        }

        return $puzzleIds;
    }

    private static function solved(string $timeId, string $puzzleId, int $time, null|int $predicted, string $finishedAt, null|string $trackedAt = null, bool $suspicious = false, bool $firstAttempt = false, null|string $teamId = null): SolvedPuzzle
    {
        return new SolvedPuzzle(
            timeId: $timeId,
            playerId: 'player-1',
            playerName: 'player-1',
            playerCode: 'PLAYER-1',
            playerCountry: null,
            puzzleId: $puzzleId,
            puzzleName: $puzzleId,
            puzzleAlternativeNames: new PuzzleNames(),
            manufacturerName: 'Manufacturer',
            piecesCount: 500,
            time: $time,
            puzzleImage: null,
            puzzleImageRatio: null,
            comment: null,
            trackedAt: new DateTimeImmutable($trackedAt ?? $finishedAt),
            finishedPuzzlePhoto: null,
            teamId: $teamId,
            players: null,
            solvedTimes: 1,
            puzzleIdentificationNumber: null,
            finishedAt: new DateTimeImmutable($finishedAt),
            firstAttempt: $firstAttempt,
            unboxed: false,
            isPrivate: false,
            competitionId: null,
            competitionShortcut: null,
            competitionName: null,
            competitionSlug: null,
            suspicious: $suspicious,
            predictedSeconds: $predicted,
        );
    }
}
