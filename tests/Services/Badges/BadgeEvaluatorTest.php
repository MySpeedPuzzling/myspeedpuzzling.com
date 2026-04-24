<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Badges;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Repository\BadgeRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Tests\TestDouble\FakeBadgeCondition;
use SpeedPuzzling\Web\Tests\TestDouble\SavedBadgeRecorder;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\Clock\MockClock;

final class BadgeEvaluatorTest extends TestCase
{
    private const string PLAYER_ID = '018d0000-0000-0000-0000-000000000001';

    public function testReturnsEmptyWhenPlayerDoesNotExist(): void
    {
        $recorder = new SavedBadgeRecorder();
        $evaluator = $this->evaluator(
            conditions: [],
            existingBadges: [],
            playerRepository: $this->playerRepositoryThrowing(),
            recorder: $recorder,
        );

        self::assertSame([], $evaluator->recalculateForPlayer(self::PLAYER_ID));
        self::assertSame([], $recorder->saved);
    }

    public function testPersistsAllQualifiedTiersWhenNoneEarnedYet(): void
    {
        $recorder = new SavedBadgeRecorder();

        $evaluator = $this->evaluator(
            conditions: [
                new FakeBadgeCondition(BadgeType::PuzzlesSolved, [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold]),
            ],
            existingBadges: [],
            playerRepository: $this->playerRepositoryReturning($this->fakePlayer()),
            recorder: $recorder,
        );

        $result = $evaluator->recalculateForPlayer(self::PLAYER_ID);

        self::assertCount(3, $result);
        self::assertSame([1, 2, 3], array_map(static fn (Badge $b): null|int => $b->tier, $recorder->saved));
        self::assertSame(BadgeType::PuzzlesSolved, $recorder->saved[0]->type);
    }

    public function testSkipsTiersAlreadyInDatabase(): void
    {
        $recorder = new SavedBadgeRecorder();

        $evaluator = $this->evaluator(
            conditions: [
                new FakeBadgeCondition(BadgeType::Streak, [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold]),
            ],
            existingBadges: [
                new BadgeResult(BadgeType::Streak, BadgeTier::Bronze, new DateTimeImmutable('2026-01-01')),
                new BadgeResult(BadgeType::Streak, BadgeTier::Silver, new DateTimeImmutable('2026-02-01')),
            ],
            playerRepository: $this->playerRepositoryReturning($this->fakePlayer()),
            recorder: $recorder,
        );

        $result = $evaluator->recalculateForPlayer(self::PLAYER_ID);

        self::assertCount(1, $result);
        self::assertSame(3, $result[0]->tier);
    }

    public function testReturnsEmptyWhenAllQualifiedTiersAlreadyEarned(): void
    {
        $recorder = new SavedBadgeRecorder();

        $evaluator = $this->evaluator(
            conditions: [
                new FakeBadgeCondition(BadgeType::TeamPlayer, [BadgeTier::Bronze]),
            ],
            existingBadges: [
                new BadgeResult(BadgeType::TeamPlayer, BadgeTier::Bronze, new DateTimeImmutable('2026-01-01')),
            ],
            playerRepository: $this->playerRepositoryReturning($this->fakePlayer()),
            recorder: $recorder,
        );

        self::assertSame([], $evaluator->recalculateForPlayer(self::PLAYER_ID));
        self::assertSame([], $recorder->saved);
    }

    public function testIgnoresSingleTierBadgesWhenCheckingForGaps(): void
    {
        // Supporter badges live with tier = null. They must not collide with tier-1 of any type.
        $recorder = new SavedBadgeRecorder();

        $evaluator = $this->evaluator(
            conditions: [
                new FakeBadgeCondition(BadgeType::PuzzlesSolved, [BadgeTier::Bronze]),
            ],
            existingBadges: [
                new BadgeResult(BadgeType::Supporter, null, new DateTimeImmutable('2026-01-01')),
            ],
            playerRepository: $this->playerRepositoryReturning($this->fakePlayer()),
            recorder: $recorder,
        );

        self::assertCount(1, $evaluator->recalculateForPlayer(self::PLAYER_ID));
    }

    public function testEarnsBadgesAcrossMultipleConditions(): void
    {
        $recorder = new SavedBadgeRecorder();

        $evaluator = $this->evaluator(
            conditions: [
                new FakeBadgeCondition(BadgeType::PuzzlesSolved, [BadgeTier::Bronze]),
                new FakeBadgeCondition(BadgeType::PiecesSolved, [BadgeTier::Bronze, BadgeTier::Silver]),
                new FakeBadgeCondition(BadgeType::Streak, []),
            ],
            existingBadges: [],
            playerRepository: $this->playerRepositoryReturning($this->fakePlayer()),
            recorder: $recorder,
        );

        $result = $evaluator->recalculateForPlayer(self::PLAYER_ID);

        self::assertCount(3, $result);

        $byType = [];
        foreach ($recorder->saved as $badge) {
            $byType[$badge->type->value][] = $badge->tier;
        }

        self::assertSame([1], $byType['puzzles_solved']);
        self::assertSame([1, 2], $byType['pieces_solved']);
    }

    /**
     * @param iterable<BadgeConditionInterface> $conditions
     * @param list<BadgeResult> $existingBadges
     */
    private function evaluator(
        iterable $conditions,
        array $existingBadges,
        PlayerRepository $playerRepository,
        SavedBadgeRecorder $recorder,
    ): BadgeEvaluator {
        $getSnapshot = $this->createStub(GetPlayerStatsSnapshot::class);
        $getSnapshot->method('forPlayer')->willReturn($this->emptySnapshot());

        $getBadges = $this->createStub(GetBadges::class);
        $getBadges->method('forPlayer')->willReturn($existingBadges);

        $badgeRepository = $this->createStub(BadgeRepository::class);
        $badgeRepository->method('save')->willReturnCallback(function (Badge $badge) use ($recorder): void {
            $recorder->saved[] = $badge;
        });

        return new BadgeEvaluator(
            conditions: $conditions,
            getPlayerStatsSnapshot: $getSnapshot,
            getBadges: $getBadges,
            badgeRepository: $badgeRepository,
            playerRepository: $playerRepository,
            clock: new MockClock('2026-04-16 12:00:00'),
        );
    }

    private function emptySnapshot(): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: self::PLAYER_ID,
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
        );
    }

    private function fakePlayer(): Player
    {
        $player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
        (new \ReflectionClass(Player::class))->getProperty('id')->setValue($player, Uuid::fromString(self::PLAYER_ID));

        return $player;
    }

    private function playerRepositoryReturning(Player $player): PlayerRepository
    {
        $repository = $this->createStub(PlayerRepository::class);
        $repository->method('get')->willReturn($player);

        return $repository;
    }

    private function playerRepositoryThrowing(): PlayerRepository
    {
        $repository = $this->createStub(PlayerRepository::class);
        $repository->method('get')->willThrowException(new PlayerNotFound());

        return $repository;
    }
}
