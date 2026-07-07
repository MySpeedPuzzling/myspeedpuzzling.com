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

    private static function createSolvedPuzzle(string $timeId, int $time, bool $unboxed): SolvedPuzzle
    {
        return new SolvedPuzzle(
            timeId: $timeId,
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
            comment: null,
            trackedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            finishedPuzzlePhoto: null,
            teamId: null,
            players: null,
            solvedTimes: 1,
            puzzleIdentificationNumber: null,
            finishedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            firstAttempt: false,
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
