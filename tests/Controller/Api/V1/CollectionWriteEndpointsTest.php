<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CollectionWriteEndpointsTest extends WebTestCase
{
    public function testUpdateCollectionRenamesIt(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'PUT',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Renamed collection']),
        );

        $this->assertResponseIsSuccessful();

        /** @var array{collection_id: string, name: string} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $response['collection_id']);
        self::assertSame('Renamed collection', $response['name']);

        $name = $this->database()->fetchOne(
            'SELECT name FROM collection WHERE id = :id',
            ['id' => CollectionFixture::COLLECTION_PUBLIC],
        );

        self::assertSame('Renamed collection', $name);
    }

    public function testUpdateForeignCollectionReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'PUT',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PRIVATE,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Hijacked']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateUnknownCollectionReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'PUT',
            '/api/v1/me/collections/00000000-0000-0000-0000-000000000000',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Whatever']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateCollectionWithoutWriteScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_WITH_STRIPE,
            ['collections:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'No write scope']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteCollectionRemovesIt(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('DELETE', '/api/v1/me/collections/' . CollectionFixture::COLLECTION_STRIPE_TREFL);

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $exists = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM collection WHERE id = :id',
            ['id' => CollectionFixture::COLLECTION_STRIPE_TREFL],
        );

        self::assertSame(0, is_numeric($exists) ? (int) $exists : null);
    }

    public function testDeleteForeignCollectionReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('DELETE', '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PRIVATE);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteDefaultCollectionReturnsBadRequest(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('DELETE', '/api/v1/me/collections/default');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAddItemReturnsCreatedItemData(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'POST',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['puzzle_id' => PuzzleFixture::PUZZLE_1000_01]),
        );

        $this->assertResponseIsSuccessful();

        /** @var array{collection_item_id: string, puzzle_id: string, puzzle_name: string, pieces_count: int} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(Uuid::isValid($response['collection_item_id']));
        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $response['puzzle_id']);
        self::assertNotSame('', $response['puzzle_name']);
        self::assertSame(1000, $response['pieces_count']);

        $itemId = $this->database()->fetchOne(
            'SELECT id FROM collection_item WHERE collection_id = :collectionId AND puzzle_id = :puzzleId AND player_id = :playerId',
            [
                'collectionId' => CollectionFixture::COLLECTION_PUBLIC,
                'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
                'playerId' => PlayerFixture::PLAYER_WITH_STRIPE,
            ],
        );

        self::assertSame($itemId, $response['collection_item_id']);
    }

    public function testAddItemWithUnknownPuzzleReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'POST',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['puzzle_id' => '00000000-0000-0000-0000-000000000000']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAddItemToForeignCollectionReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'POST',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PRIVATE . '/items',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['puzzle_id' => PuzzleFixture::PUZZLE_1000_01]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteCollectionItemRemovesIt(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'DELETE',
            '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items/' . CollectionItemFixture::ITEM_21,
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $exists = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM collection_item WHERE id = :id',
            ['id' => CollectionItemFixture::ITEM_21],
        );

        self::assertSame(0, is_numeric($exists) ? (int) $exists : null);
    }

    public function testDeleteCollectionItemViaWrongCollectionReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'DELETE',
            '/api/v1/me/collections/default/items/' . CollectionItemFixture::ITEM_21,
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteForeignCollectionItemReturnsForbidden(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'DELETE',
            '/api/v1/me/collections/default/items/' . CollectionItemFixture::ITEM_25,
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteUnknownCollectionItemReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request(
            'DELETE',
            '/api/v1/me/collections/default/items/00000000-0000-0000-0000-000000000000',
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testItemsListWithGarbageCollectionIdReturnsNotFound(): void
    {
        $browser = $this->patBrowser(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/api/v1/me/collections/undefined/items');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function patBrowser(string $playerId): KernelBrowser
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, $playerId);
        PatTestHelper::addBearerToken($browser, $token);

        return $browser;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
