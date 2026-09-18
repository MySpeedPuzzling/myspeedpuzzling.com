<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/favorites and GET /api/v1/me/followers - the favorites link
 * in both directions, /me only. A private player is listed and counted but
 * masked, in either list.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_FAVORITES follows PLAYER_REGULAR
 * and PLAYER_ADMIN; nobody else follows anyone. connectPlayers() adds:
 * PLAYER_REGULAR -> PLAYER_WITH_FAVORITES (mutual) and PLAYER_PRIVATE,
 * PLAYER_PRIVATE -> PLAYER_REGULAR (mutual), PLAYER_WITH_STRIPE -> PLAYER_REGULAR.
 */
final class PlayerConnectionsEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    private const string FAVORITES_PATH = '/api/v1/me/favorites';
    private const string FOLLOWERS_PATH = '/api/v1/me/followers';

    /** @var list<string> */
    private const array ITEM_KEYS = ['id', 'name', 'code', 'avatar', 'country', 'is_private', 'is_mutual'];

    public function testAuthentication(): void
    {
        $browser = self::createClient();

        foreach ([self::FAVORITES_PATH, self::FOLLOWERS_PATH] as $path) {
            $browser->setServerParameter('HTTP_AUTHORIZATION', '');
            $browser->request('GET', $path);
            $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

            // a machine token has no "me"
            $this->authenticateClientCredentials($browser, ['profile:read']);
            $browser->request('GET', $path);
            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            // profile:read is the scope
            $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read', 'collections:read']);
            $browser->request('GET', $path);
            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
            $browser->request('GET', $path);
            $this->assertResponseIsSuccessful();

            $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
            $browser->request('GET', $path);
            $this->assertResponseIsSuccessful();
        }
    }

    public function testFavoritesFromFixtures(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', self::FAVORITES_PATH);
        $this->assertResponseIsSuccessful();

        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_FAVORITES, 2);

        // by name: Admin User, John Doe
        $this->assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR], $this->column($items, 'id'));
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        $this->assertSame('Admin User', $items[0]['name']);
        $this->assertSame('admin', $items[0]['code']);
        $this->assertSame('cz', $items[0]['country']);
        $this->assertFalse($items[0]['is_private']);
        $this->assertFalse($items[0]['is_mutual']);

        // nobody follows PLAYER_WITH_FAVORITES
        $browser->request('GET', self::FOLLOWERS_PATH);
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_WITH_FAVORITES, 0);
    }

    public function testFavoritesMaskPrivatePlayersAndFlagMutualOnes(): void
    {
        $browser = self::createClient();
        $this->connectPlayers($browser);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', self::FAVORITES_PATH);
        $this->assertResponseIsSuccessful();

        $items = $this->items($browser, PlayerFixture::PLAYER_REGULAR, 2);

        // private players come last
        $this->assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE], $this->column($items, 'id'));

        $this->assertSame(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $items[0]['name']);
        $this->assertTrue($items[0]['is_mutual']);

        $this->assertMasked($items[1]);
        $this->assertTrue($items[1]['is_mutual']);
    }

    public function testFollowersMaskPrivatePlayersAndFlagMutualOnes(): void
    {
        $browser = self::createClient();
        $this->connectPlayers($browser);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::FOLLOWERS_PATH);
        $this->assertResponseIsSuccessful();

        $items = $this->items($browser, PlayerFixture::PLAYER_REGULAR, 3);

        // by name (Michael Johnson, Sarah Williams), the private follower last
        $this->assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE],
            $this->column($items, 'id'),
        );

        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        $this->assertSame(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $items[0]['name']);
        $this->assertSame('player3', $items[0]['code']);
        $this->assertTrue($items[0]['is_mutual']);

        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $items[1]['name']);
        $this->assertFalse($items[1]['is_mutual']);

        // counted and listed, but their favorites list is hidden on the website
        $this->assertMasked($items[2]);
        $this->assertTrue($items[2]['is_mutual']);
    }

    public function testCurrentUserCarriesBothCounts(): void
    {
        $browser = self::createClient();
        $this->connectPlayers($browser);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/api/v1/me');
        $this->assertResponseIsSuccessful();

        $response = $this->decodeJson($browser);
        $this->assertSame(2, $response['favorites_count']);
        $this->assertSame(3, $response['followers_count']);

        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/api/v1/me');

        $response = $this->decodeJson($browser);
        $this->assertSame(0, $response['favorites_count']);
        $this->assertSame(1, $response['followers_count']);
    }

    public function testEachListIsOneQuery(): void
    {
        $browser = self::createClient();
        $this->connectPlayers($browser);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        foreach ([self::FAVORITES_PATH, self::FOLLOWERS_PATH] as $path) {
            $this->startCountingQueries($browser);
            $browser->request('GET', $path);
            $this->assertResponseIsSuccessful();

            $this->assertQueryCountAtMost($browser, 3, $path . ': PAT authentication + the list query, whatever the list size');
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertMasked(array $item): void
    {
        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $item['id']);
        $this->assertSame('player2', $item['code']);
        $this->assertNull($item['name']);
        $this->assertNull($item['avatar']);
        $this->assertNull($item['country']);
        $this->assertTrue($item['is_private']);
    }

    private function connectPlayers(KernelBrowser $browser): void
    {
        $entityManager = $this->entityManager($browser);

        $player = static function (string $playerId) use ($entityManager): Player {
            $player = $entityManager->find(Player::class, $playerId);
            assert($player !== null);

            return $player;
        };

        $regular = $player(PlayerFixture::PLAYER_REGULAR);
        $regular->addFavoritePlayer($player(PlayerFixture::PLAYER_WITH_FAVORITES));
        $regular->addFavoritePlayer($player(PlayerFixture::PLAYER_PRIVATE));
        $player(PlayerFixture::PLAYER_PRIVATE)->addFavoritePlayer($regular);
        $player(PlayerFixture::PLAYER_WITH_STRIPE)->addFavoritePlayer($regular);

        $entityManager->flush();
        $entityManager->clear();
    }
}
