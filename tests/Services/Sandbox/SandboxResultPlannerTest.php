<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sandbox;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SandboxPlannedResult;
use SpeedPuzzling\Web\Value\SandboxPlayer;
use SpeedPuzzling\Web\Value\SandboxPuzzle;
use SpeedPuzzling\Web\Services\Sandbox\SandboxResultPlanner;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Component\Clock\MockClock;

final class SandboxResultPlannerTest extends TestCase
{
    private const string NOW = TestClock::FROZEN_NOW;

    public function testTheSameSeedPlansTheSameHistory(): void
    {
        $planner = new SandboxResultPlanner(new MockClock(self::NOW));

        $first = $planner->history(self::players(), self::puzzles(), 400, 7);
        $second = $planner->history(self::players(), self::puzzles(), 400, 7);

        self::assertSame(self::fingerprint($first), self::fingerprint($second));
        self::assertNotSame(self::fingerprint($first), self::fingerprint($planner->history(self::players(), self::puzzles(), 400, 8)));
    }

    public function testAHistoryLooksLikeOneThatHappened(): void
    {
        $planner = new SandboxResultPlanner(new MockClock(self::NOW));
        $now = new DateTimeImmutable(self::NOW);
        $results = $planner->history(self::players(), self::puzzles(), 600, 2026);

        self::assertGreaterThan(500, count($results));

        $previousTrackedAt = null;
        $firstTries = [];
        $groups = 0;

        foreach ($results as $result) {
            // Saved in order, never in the future, never before the puzzle was finished or the player existed
            if ($previousTrackedAt !== null) {
                self::assertGreaterThanOrEqual($previousTrackedAt, $result->trackedAt);
            }

            $previousTrackedAt = $result->trackedAt;
            self::assertLessThanOrEqual($now, $result->trackedAt);
            self::assertGreaterThanOrEqual($result->finishedAt, $result->trackedAt);
            self::assertGreaterThanOrEqual($result->player->registeredAt, $result->finishedAt);

            // The add-time handler refuses 100 pieces a minute or more
            $puzzlers = 1 + count($result->groupPlayers);
            self::assertLessThan(100, $result->puzzle->piecesCount / $puzzlers / ($result->seconds / 60));
            self::assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', $result->timeInput());

            if ($result->groupPlayers !== []) {
                $groups++;
                // A pair/team never claims the first try, and never has the tracker in it twice
                self::assertFalse($result->firstAttempt);
                self::assertNotContains('#' . $result->player->code, $result->groupPlayers);
            }

            if ($result->firstAttempt) {
                $key = $result->player->id . '/' . $result->puzzle->id;
                self::assertArrayNotHasKey($key, $firstTries, 'Two first tries of one player on one puzzle');
                $firstTries[$key] = true;
            }
        }

        self::assertGreaterThan(0, $groups);
        self::assertGreaterThan(0, count($firstTries));
    }

    public function testRecentResultsAreFinishedMinutesAgoAndSavedNow(): void
    {
        $planner = new SandboxResultPlanner(new MockClock(self::NOW));
        $now = new DateTimeImmutable(self::NOW);
        $puzzles = self::puzzles();
        // Every player has solved every puzzle already - nothing may be a first try
        $solvedEverything = array_fill_keys(array_map(static fn (SandboxPuzzle $puzzle): string => $puzzle->id, $puzzles), 1);

        $results = $planner->recent(self::players(), $puzzles, static fn (string $playerId): array => $solvedEverything, 20, 3);

        self::assertCount(20, $results);

        foreach ($results as $result) {
            self::assertEquals($now, $result->trackedAt);
            self::assertLessThan($now, $result->finishedAt);
            self::assertGreaterThanOrEqual($now->modify('-40 minutes'), $result->finishedAt);
            self::assertFalse($result->firstAttempt);
        }
    }

    public function testNothingToPlanWithoutPlayersOrPuzzles(): void
    {
        $planner = new SandboxResultPlanner(new MockClock(self::NOW));

        self::assertSame([], $planner->history([], self::puzzles(), 100, 1));
        self::assertSame([], $planner->history(self::players(), [], 100, 1));
        self::assertSame([], $planner->recent([], self::puzzles(), static fn (string $id): array => [], 3));
    }

    /**
     * @return list<SandboxPlayer>
     */
    private static function players(): array
    {
        $now = new DateTimeImmutable(self::NOW);
        $players = [];

        for ($i = 1; $i <= 12; $i++) {
            $players[] = new SandboxPlayer(
                id: sprintf('018d0000-0000-7000-8000-%012d', $i),
                userId: 'msp|player-' . $i,
                code: 'code' . $i,
                registeredAt: $now->modify(sprintf('-%d days', 30 * $i)),
            );
        }

        return $players;
    }

    /**
     * @return list<SandboxPuzzle>
     */
    private static function puzzles(): array
    {
        $puzzles = [];

        foreach ([100, 300, 500, 500, 500, 1000, 1000, 1000, 1500, 500, 1000, 300] as $i => $pieces) {
            $puzzles[] = new SandboxPuzzle(sprintf('018d0003-0000-7000-8000-%012d', $i + 1), $pieces);
        }

        return $puzzles;
    }

    /**
     * @param list<SandboxPlannedResult> $results
     * @return list<string>
     */
    private static function fingerprint(array $results): array
    {
        return array_map(
            static fn (SandboxPlannedResult $result): string => implode('|', [
                $result->player->id,
                $result->puzzle->id,
                $result->seconds,
                $result->finishedAt->format('c'),
                $result->firstAttempt ? '1' : '0',
                implode(',', $result->groupPlayers),
            ]),
            $results,
        );
    }
}
