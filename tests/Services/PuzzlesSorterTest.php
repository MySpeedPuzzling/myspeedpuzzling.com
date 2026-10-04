<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;

final class PuzzlesSorterTest extends TestCase
{
    public function testSortByUnboxedPutsUnboxedAttemptsFirst(): void
    {
        $sorter = new PuzzlesSorter();

        $fasterNotUnboxed = self::createSolver('time-1', 'player-1', time: 1500, unboxed: false);
        $slowerUnboxed = self::createSolver('time-2', 'player-1', time: 2000, unboxed: true);
        $slowestUnboxed = self::createSolver('time-3', 'player-1', time: 2500, unboxed: true);

        $sorted = $sorter->sortByUnboxed([$fasterNotUnboxed, $slowestUnboxed, $slowerUnboxed]);

        self::assertSame(['time-2', 'time-3', 'time-1'], array_map(
            static fn (PuzzleSolver $solver): string => $solver->timeId,
            $sorted,
        ));
    }

    public function testUnboxedAttemptLeadsPlayerGroupEvenWhenSlowerAttemptExists(): void
    {
        $sorter = new PuzzlesSorter();

        // player-1 has a faster non-unboxed time and a slower unboxed one
        $fasterNotUnboxed = self::createSolver('time-1', 'player-1', time: 1500, unboxed: false);
        $slowerUnboxed = self::createSolver('time-2', 'player-1', time: 2000, unboxed: true);
        // player-2 has only a non-unboxed time
        $otherPlayer = self::createSolver('time-3', 'player-2', time: 1800, unboxed: false);

        $grouped = $sorter->groupPlayers($sorter->sortByUnboxed([$fasterNotUnboxed, $slowerUnboxed, $otherPlayer]));
        $grouped = $sorter->filterOutNonUnboxedGrouped($grouped);

        self::assertCount(1, $grouped);
        self::assertSame('time-2', $grouped['player-1'][0]->timeId);
    }

    public function testMakeUnboxedFirstMovesBestUnboxedToHead(): void
    {
        $sorter = new PuzzlesSorter();

        // Already sorted by fastest - unboxed is not the fastest
        $sorted = $sorter->makeUnboxedFirst([
            self::createSolvedPuzzle('time-1', time: 1500, unboxed: false),
            self::createSolvedPuzzle('time-2', time: 2000, unboxed: true),
            self::createSolvedPuzzle('time-3', time: 2500, unboxed: true),
        ]);

        self::assertSame(['time-2', 'time-1', 'time-3'], array_map(
            static fn (SolvedPuzzle $puzzle): string => $puzzle->timeId,
            $sorted,
        ));
    }

    public function testSortGroupedByFastestWithOnlyUnboxedLeadsGroupsWithUnboxedAttempt(): void
    {
        $sorter = new PuzzlesSorter();

        $grouped = $sorter->sortGroupedByFastest(
            [
                'puzzle-1' => [
                    self::createSolvedPuzzle('time-1', time: 1500, unboxed: false),
                    self::createSolvedPuzzle('time-2', time: 2000, unboxed: true),
                ],
            ],
            onlyFirstTries: false,
            onlyUnboxed: true,
        );

        self::assertSame('time-2', $grouped[0][0]->timeId);
    }

    public function testSortByDifficultyPutsUnratedPuzzlesLastInBothDirections(): void
    {
        $sorter = new PuzzlesSorter();
        $scores = ['easy' => 0.8, 'hard' => 1.3, 'average' => 1.0];
        $times = [
            self::createSolvedPuzzle('unrated', time: 900, puzzleId: 'unrated'),
            self::createSolvedPuzzle('hard', time: 3000, puzzleId: 'hard'),
            self::createSolvedPuzzle('easy', time: 1000, puzzleId: 'easy'),
            self::createSolvedPuzzle('average', time: 2000, puzzleId: 'average'),
        ];

        self::assertSame(['easy', 'average', 'hard', 'unrated'], self::timeIds($sorter->sortByDifficulty($times, $scores, hardestFirst: false)));
        self::assertSame(['hard', 'average', 'easy', 'unrated'], self::timeIds($sorter->sortByDifficulty($times, $scores, hardestFirst: true)));
    }

    public function testSortByDifficultyOrdersEquallyDifficultResultsFastestFirst(): void
    {
        $sorter = new PuzzlesSorter();
        $scores = ['puzzle-a' => 1.1, 'puzzle-b' => 1.1];
        $times = [
            self::createSolvedPuzzle('slow-a', time: 3000, puzzleId: 'puzzle-a', teamId: 'team-1'),
            self::createSolvedPuzzle('unrated-slow', time: 5000, puzzleId: 'unrated'),
            self::createSolvedPuzzle('fast-b', time: 1000, puzzleId: 'puzzle-b', teamId: 'team-2'),
            self::createSolvedPuzzle('unrated-fast', time: 4000, puzzleId: 'unrated'),
            self::createSolvedPuzzle('mid-a', time: 2000, puzzleId: 'puzzle-a', teamId: 'team-3'),
        ];

        self::assertSame(
            ['fast-b', 'mid-a', 'slow-a', 'unrated-fast', 'unrated-slow'],
            self::timeIds($sorter->sortByDifficulty($times, $scores, hardestFirst: true)),
        );
    }

    public function testSortGroupedByDifficultyOrdersPuzzleGroupsByScore(): void
    {
        $sorter = new PuzzlesSorter();
        $scores = ['easy' => 0.8, 'hard' => 1.3];
        $grouped = [
            'unrated' => [self::createSolvedPuzzle('unrated', time: 900, puzzleId: 'unrated')],
            'easy' => [
                self::createSolvedPuzzle('easy-slow', time: 1500, puzzleId: 'easy'),
                self::createSolvedPuzzle('easy-fast', time: 1200, puzzleId: 'easy'),
            ],
            'hard' => [self::createSolvedPuzzle('hard', time: 3000, puzzleId: 'hard')],
        ];

        $easiest = $sorter->sortGroupedByDifficulty($grouped, $scores, hardestFirst: false, onlyFirstTries: false);
        self::assertSame(['easy-fast', 'hard', 'unrated'], self::groupHeads($easiest));
        self::assertSame(['easy-fast', 'easy-slow'], self::timeIds($easiest[0]), 'Fastest first within a puzzle');

        $hardest = $sorter->sortGroupedByDifficulty($grouped, $scores, hardestFirst: true, onlyFirstTries: false);
        self::assertSame(['hard', 'easy-fast', 'unrated'], self::groupHeads($hardest));
    }

    public function testSortGroupedByDifficultyKeepsTheFirstTryOrUnboxedHead(): void
    {
        $sorter = new PuzzlesSorter();
        $grouped = [
            'puzzle-1' => [
                self::createSolvedPuzzle('fast', time: 1200),
                self::createSolvedPuzzle('first-try', time: 1800, firstAttempt: true),
                self::createSolvedPuzzle('unboxed', time: 1500, unboxed: true),
            ],
        ];

        $firstTries = $sorter->sortGroupedByDifficulty($grouped, ['puzzle-1' => 1.0], hardestFirst: false, onlyFirstTries: true);
        self::assertSame('first-try', $firstTries[0][0]->timeId);

        $unboxed = $sorter->sortGroupedByDifficulty($grouped, ['puzzle-1' => 1.0], hardestFirst: false, onlyFirstTries: false, onlyUnboxed: true);
        self::assertSame('unboxed', $unboxed[0][0]->timeId);
    }

    public function testSortGroupedByDifficultyOrdersEquallyDifficultPuzzlesByFastestHead(): void
    {
        $sorter = new PuzzlesSorter();
        $grouped = [
            'slower' => [self::createSolvedPuzzle('slower', time: 2000, puzzleId: 'slower')],
            'faster' => [self::createSolvedPuzzle('faster', time: 1000, puzzleId: 'faster')],
        ];

        $sorted = $sorter->sortGroupedByDifficulty($grouped, ['slower' => 1.2, 'faster' => 1.2], hardestFirst: true, onlyFirstTries: false);

        self::assertSame(['faster', 'slower'], self::groupHeads($sorted));
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return list<string>
     */
    private static function timeIds(array $solvedPuzzles): array
    {
        return array_values(array_map(static fn (SolvedPuzzle $puzzle): string => $puzzle->timeId, $solvedPuzzles));
    }

    /**
     * @param array<array<SolvedPuzzle>> $grouped
     * @return list<string>
     */
    private static function groupHeads(array $grouped): array
    {
        return array_values(array_map(static function (array $group): string {
            $head = reset($group);
            self::assertInstanceOf(SolvedPuzzle::class, $head);

            return $head->timeId;
        }, $grouped));
    }

    private static function createSolvedPuzzle(
        string $timeId,
        int $time,
        bool $unboxed = false,
        string $puzzleId = 'puzzle-1',
        bool $firstAttempt = false,
        null|string $teamId = null,
    ): SolvedPuzzle {
        return new SolvedPuzzle(
            timeId: $timeId,
            playerId: 'player-1',
            playerName: 'player-1',
            playerCode: 'PLAYER-1',
            playerCountry: null,
            puzzleId: $puzzleId,
            puzzleName: $puzzleId,
            puzzleAlternativeName: null,
            manufacturerName: 'Manufacturer',
            piecesCount: 500,
            time: $time,
            puzzleImage: null,
            puzzleImageRatio: null,
            comment: null,
            trackedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            finishedPuzzlePhoto: null,
            teamId: $teamId,
            players: null,
            solvedTimes: 1,
            puzzleIdentificationNumber: null,
            finishedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            firstAttempt: $firstAttempt,
            unboxed: $unboxed,
            isPrivate: false,
            competitionId: null,
            competitionShortcut: null,
            competitionName: null,
            competitionSlug: null,
        );
    }

    private static function createSolver(string $timeId, string $playerId, int $time, bool $unboxed): PuzzleSolver
    {
        return new PuzzleSolver(
            timeId: $timeId,
            puzzleId: 'puzzle-1',
            playerId: $playerId,
            playerName: $playerId,
            playerCode: strtoupper($playerId),
            playerCountry: null,
            time: $time,
            finishedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            trackedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            firstAttempt: false,
            unboxed: $unboxed,
            isPrivate: false,
            competitionId: null,
            competitionShortcut: null,
            competitionName: null,
            competitionSlug: null,
        );
    }
}
