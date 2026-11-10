<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pool every "#rank of total" counts (GetPlayerRatingRanking::sqlRankOf(), allForPlayer()'s total,
 * sqlPlaceOnStoredDayOf() - read by the profile's rating card and the header chip through GetPlayerProfile::byId()):
 * the ladder's players, without the private and opted-out ones, without the players the viewer hides, the ranked
 * player's own row always in their total. Pinned on main before the pool was read through the partial index
 * custom_player_off_ladder, so the rewrite ranks every player exactly as before.
 *
 * 500 pieces today: Private 1.9 (private), Admin 1.8 (opted out), Regular 1.5 = With stripe 1.5, With favorites 1.2.
 * Stored 7 Oct (the header chip's reference day on 14 Oct): Private 1.95, Admin 1.85, With favorites 1.7, Regular 1.6,
 * With stripe 1.3.
 */
final class GetPlayerRatingRankingPoolTest extends KernelTestCase
{
    private const int PIECES_COUNT = 500;

    private GetPlayerRatingRanking $ranking;
    private GetPlayerProfile $profiles;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->ranking = $container->get(GetPlayerRatingRanking::class);
        $this->profiles = $container->get(GetPlayerProfile::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]);

        foreach ([
            PlayerFixture::PLAYER_PRIVATE => 1.9,
            PlayerFixture::PLAYER_ADMIN => 1.8,
            PlayerFixture::PLAYER_REGULAR => 1.5,
            PlayerFixture::PLAYER_WITH_STRIPE => 1.5,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1.2,
        ] as $playerId => $rating) {
            $this->connection->executeStatement(
                'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, :pieces, :rating, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'pieces' => self::PIECES_COUNT, 'rating' => $rating],
            );
        }

        foreach ([
            PlayerFixture::PLAYER_PRIVATE => 1.95,
            PlayerFixture::PLAYER_ADMIN => 1.85,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1.7,
            PlayerFixture::PLAYER_REGULAR => 1.6,
            PlayerFixture::PLAYER_WITH_STRIPE => 1.3,
        ] as $playerId => $rating) {
            $this->connection->executeStatement(
                "INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (:id, :player, :pieces, '2026-10-07', :rating, 1, " . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'pieces' => self::PIECES_COUNT, 'rating' => $rating],
            );
        }
    }

    public function testEveryPlayersRankTotalAndPlaceAWeekAgoForAGuest(): void
    {
        self::assertSame([
            PlayerFixture::PLAYER_REGULAR => [1, 3, 1, 2],
            PlayerFixture::PLAYER_WITH_STRIPE => [1, 3, 1, 3],
            PlayerFixture::PLAYER_WITH_FAVORITES => [3, 3, 3, 1],
            // Off the ladder: their own card still ranks them among the ladder plus themselves, the header shows nothing
            PlayerFixture::PLAYER_PRIVATE => [1, 4, null, null],
            PlayerFixture::PLAYER_ADMIN => [1, 4, null, null],
        ], $this->standings());
    }

    public function testAPlayerTheViewerHidesClosesUpEveryRankTotalAndPlaceAWeekAgo(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([
            PlayerFixture::PLAYER_WITH_STRIPE => [1, 2, 1, 2],
            PlayerFixture::PLAYER_WITH_FAVORITES => [2, 2, 2, 1],
            PlayerFixture::PLAYER_PRIVATE => [1, 3, null, null],
            PlayerFixture::PLAYER_ADMIN => [1, 3, null, null],
        ], $this->standings([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN]));
    }

    public function testThePrivatePlayersAllowListNeverPutsThemOnTheLadder(): void
    {
        // PLAYER_WITH_FAVORITES may see PLAYER_PRIVATE (PrivateProfileViewerFixture) - global rankings stay closed to her
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame([
            PlayerFixture::PLAYER_REGULAR => [1, 3, 1, 2],
            PlayerFixture::PLAYER_WITH_STRIPE => [1, 3, 1, 3],
            PlayerFixture::PLAYER_WITH_FAVORITES => [3, 3, 3, 1],
            PlayerFixture::PLAYER_PRIVATE => [1, 4, null, null],
            PlayerFixture::PLAYER_ADMIN => [1, 4, null, null],
        ], $this->standings());
    }

    public function testThePrivatePlayerSeesTheirOwnRankAmongTheLadder(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);

        self::assertSame([
            PlayerFixture::PLAYER_REGULAR => [1, 3, 1, 2],
            PlayerFixture::PLAYER_WITH_STRIPE => [1, 3, 1, 3],
            PlayerFixture::PLAYER_WITH_FAVORITES => [3, 3, 3, 1],
            PlayerFixture::PLAYER_PRIVATE => [1, 4, null, null],
            PlayerFixture::PLAYER_ADMIN => [1, 4, null, null],
        ], $this->standings());
    }

    /**
     * @param list<string> $playerIds
     * @return array<string, array{int, int, null|int, null|int}> rank and total (rating card), rank and place a week ago (header chip)
     */
    private function standings(array $playerIds = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_WITH_STRIPE,
        PlayerFixture::PLAYER_WITH_FAVORITES,
        PlayerFixture::PLAYER_PRIVATE,
        PlayerFixture::PLAYER_ADMIN,
    ]): array {
        $standings = [];

        foreach ($playerIds as $playerId) {
            $card = $this->ranking->allForPlayer($playerId)[self::PIECES_COUNT];
            $profile = $this->profiles->byId($playerId);

            $standings[$playerId] = [$card['rank'], $card['total'], $profile->mspRatingRank, $profile->mspRatingRankBefore];
        }

        return $standings;
    }
}
