<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PLAYER_WITH_FAVORITES follows PLAYER_REGULAR and PLAYER_ADMIN. PLAYER_PRIVATE ("Jane Smith") allows
 * PLAYER_WITH_FAVORITES (PrivateProfileViewerFixture); PLAYER_REGULAR blocks PLAYER_PRIVATE (UserBlockFixture).
 */
final class GetFavoritePlayersTest extends KernelTestCase
{
    private GetFavoritePlayers $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetFavoritePlayers::class);
    }

    public function testSomeoneElsesFavoritesLeaveOutThePlayerTheViewerBlocks(): void
    {
        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN],
            $this->favoritesOfPlayerWithFavorites(),
        );

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->favoritesOfPlayerWithFavorites());

        // The follower's own list is untouched for themselves and for anyone else
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN],
            $this->favoritesOfPlayerWithFavorites(),
        );
    }

    public function testMostFavoriteLeavesOutTheBlockedPlayerAndTheLimitClosesUp(): void
    {
        $everyone = array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $everyone);

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $visible = array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100));
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $visible);
        self::assertCount(count($everyone) - 1, $visible);
        self::assertCount(1, $this->query->mostFavorite(1));

        TestingViewer::signOut(self::getContainer());
        self::assertContains(PlayerFixture::PLAYER_ADMIN, array_map(static fn ($p) => $p->playerId, $this->query->mostFavorite(100)));
    }

    public function testFavoritesAreOrderedByNameWithHiddenPrivatePlayersLastByCode(): void
    {
        $this->follow(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE);

        // Nobody signed in: Jane's private profile is hidden - listed last, by code only
        $favorites = $this->query->forPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE],
            array_map(static fn (PlayerIdentification $p): string => $p->playerId, $favorites),
        );
        self::assertTrue($favorites[2]->isPrivate);
        self::assertNull($favorites[2]->playerName);
        self::assertSame('player2', $favorites[2]->playerCode);
        self::assertFalse($favorites[0]->isPrivate);

        // Michael is on Jane's allow list: she is a name like any other
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $favorites = $this->query->forPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(
            ['Admin User', 'Jane Smith', PlayerFixture::PLAYER_REGULAR_NAME],
            array_map(static fn (PlayerIdentification $p): null|string => $p->playerName, $favorites),
        );
        self::assertFalse($favorites[1]->isPrivate);
    }

    public function testFollowersLeaveOutBlockedPlayersAndOnlyCountHiddenPrivateOnes(): void
    {
        // PLAYER_ADMIN is followed by PLAYER_WITH_FAVORITES (fixture), Jane (private, admin not allowed) and Sarah
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN);
        $this->follow(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);

        $followers = $this->query->followersOf(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES_NAME, PlayerFixture::PLAYER_WITH_STRIPE_NAME],
            array_map(static fn (PlayerIdentification $p): null|string => $p->playerName, $followers->players),
        );
        self::assertSame(1, $followers->privateCount);
        self::assertSame(3, $followers->total());

        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        $followers = $this->query->followersOf(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES],
            array_map(static fn (PlayerIdentification $p): string => $p->playerId, $followers->players),
        );
        self::assertSame(1, $followers->privateCount);
    }

    public function testAPrivateFollowerIsListedForTheirAllowedFriendAndNotCountedWhenBlocked(): void
    {
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR);

        // PrivateProfileViewerFixture: Jane allows Michael
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $followers = $this->query->followersOf(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(['Jane Smith'], array_map(static fn (PlayerIdentification $p): null|string => $p->playerName, $followers->players));
        self::assertSame(0, $followers->privateCount);

        // UserBlockFixture: John blocks Jane - neither listed nor counted
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $followers = $this->query->followersOf(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES],
            array_map(static fn (PlayerIdentification $p): string => $p->playerId, $followers->players),
        );
        self::assertSame(0, $followers->privateCount);
    }

    public function testAFollowerWhoBlockedThePlayerIsNeitherListedNorCounted(): void
    {
        // PLAYER_WITH_FAVORITES follows PLAYER_ADMIN (fixture) - an older block that left the favorite in place
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES],
            array_map(static fn (PlayerIdentification $p): string => $p->playerId, $this->query->followersOf(PlayerFixture::PLAYER_ADMIN)->players),
        );

        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN);

        $followers = $this->query->followersOf(PlayerFixture::PLAYER_ADMIN);
        self::assertSame([], $followers->players);
        self::assertSame(0, $followers->privateCount);
    }

    /**
     * @return list<string>
     */
    private function favoritesOfPlayerWithFavorites(): array
    {
        return array_values(array_map(
            static fn ($p) => $p->playerId,
            $this->query->forPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES),
        ));
    }

    private function follow(string $followerId, string $followedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = (favorite_players::jsonb || jsonb_build_array(:followed::text))::json WHERE id = :follower',
            ['followed' => $followedId, 'follower' => $followerId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
