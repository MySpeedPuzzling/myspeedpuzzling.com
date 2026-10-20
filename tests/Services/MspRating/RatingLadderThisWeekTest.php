<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MspRating;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RatingLadderSpotlight;
use SpeedPuzzling\Web\Services\MspRating\RatingLadderThisWeek;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "This week on the ladder" (docs/features/msp-rating-history.md): the biggest climber with a new result, the best new
 * entry, the most active - one player in one tile, the viewer's hidden players left out.
 */
final class RatingLadderThisWeekTest extends KernelTestCase
{
    private const string NOW = '2026-06-21 12:00';

    public function testTheClimberAndTheNewEntry(): void
    {
        self::bootKernel();
        $this->world();

        $tiles = $this->tiles();

        // Admin: #3 a week ago, #1 now, with a new result - also the most active, but one player gets one tile
        self::assertSame(['climber', 'new_entry'], array_map(static fn (RatingLadderSpotlight $tile): string => $tile->kind, $tiles));
        self::assertSame([PlayerFixture::PLAYER_ADMIN, 1, 2], [$tiles[0]->playerId, $tiles[0]->rank, $tiles[0]->value]);
        // With favorites: not rated a week ago
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, 4], [$tiles[1]->playerId, $tiles[1]->rank]);
    }

    public function testWithoutANewResultARiseIsNoClimb(): void
    {
        self::bootKernel();
        $this->world(withNewResult: false);

        self::assertSame(['new_entry'], array_map(static fn (RatingLadderSpotlight $tile): string => $tile->kind, $this->tiles()));
    }

    public function testTheMostActiveSolvedTheMostPuzzlesForTheRating(): void
    {
        self::bootKernel();
        $this->world();
        // Regular: two puzzles since the compared day, Admin (the climber) one; an older solve does not count
        $this->entry(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01, '2026-06-16');
        $this->entry(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02, '2026-06-19');
        $this->entry(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_03, '2026-06-01');

        $tiles = $this->tiles();

        self::assertSame(['climber', 'new_entry', 'most_active'], array_map(static fn (RatingLadderSpotlight $tile): string => $tile->kind, $tiles));
        self::assertSame([PlayerFixture::PLAYER_REGULAR, 3, 2], [$tiles[2]->playerId, $tiles[2]->rank, $tiles[2]->value]);
    }

    public function testAHiddenPlayerIsLeftOut(): void
    {
        self::bootKernel();
        $this->world();
        $this->connection()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $tiles = $this->tiles(viewerId: PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([PlayerFixture::PLAYER_ADMIN], array_map(static fn (RatingLadderSpotlight $tile): string => $tile->playerId, $tiles));
    }

    /**
     * Today: Admin 1.6 (#1), With stripe 1.5 (#2), Regular 1.4 (#3), With favorites 1.0 (#4).
     * Stored days 7-20 Jun: With stripe 1.55 (#1), Regular 1.45 (#2), Admin 1.3 (#3); With favorites not rated.
     */
    private function world(bool $withNewResult = true): void
    {
        $connection = $this->connection();

        foreach ([PlayerFixture::PLAYER_ADMIN => 1.6, PlayerFixture::PLAYER_WITH_STRIPE => 1.5, PlayerFixture::PLAYER_REGULAR => 1.4, PlayerFixture::PLAYER_WITH_FAVORITES => 1.0] as $playerId => $rating) {
            $connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $rating],
            );
        }

        for ($day = 7; $day <= 20; $day++) {
            foreach ([PlayerFixture::PLAYER_WITH_STRIPE => [1.55, 1], PlayerFixture::PLAYER_REGULAR => [1.45, 2], PlayerFixture::PLAYER_ADMIN => [1.3, 3]] as $playerId => [$rating, $place]) {
                $connection->executeStatement(
                    'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, :rating, :place, NOW())',
                    ['player' => $playerId, 'day' => sprintf('2026-06-%02d', $day), 'rating' => $rating, 'place' => $place],
                );
            }
        }

        if ($withNewResult) {
            $this->entry(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_500_01, '2026-06-18');
        }
    }

    private function entry(string $playerId, string $puzzleId, string $solvedAt): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO player_rating_entry (player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at)
             VALUES (:player, 500, :puzzle, 'first_attempt', 3000, :solvedAt, 90.0, 1.0, 1.0, 0.9, true, NOW())",
            ['player' => $playerId, 'puzzle' => $puzzleId, 'solvedAt' => $solvedAt],
        );
    }

    /**
     * @return list<RatingLadderSpotlight>
     */
    private function tiles(null|string $viewerId = null): array
    {
        $container = self::getContainer();
        $container->set(ClockInterface::class, new MockClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))));

        if ($viewerId !== null) {
            TestingViewer::signIn($container, $viewerId);
        }

        /** @var RatingLadderThisWeek $service */
        $service = $container->get(RatingLadderThisWeek::class);

        return $service->spotlights(500);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
