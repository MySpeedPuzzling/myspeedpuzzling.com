<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET /api/v1/players/{playerId}/collections/{collectionId}/items - every item
 * carries statistics (public), difficulty (the *token owner* is a member) and
 * solves (the *collection owner's* history, only for a token with results:read);
 * never a prediction - predictions are self-only (plan §0 N1). The private
 * profile short-circuit is untouched.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE (member) owns
 * COLLECTION_PUBLIC with PUZZLE_500_01 (solved once, 2100 s) and PUZZLE_500_02
 * among its 8 puzzles; PLAYER_REGULAR is not a member.
 *
 * @phpstan-type StatisticsGroup array{count: int, fastest_seconds: null|int, average_seconds: null|int, slowest_seconds: null|int, median_seconds: null|int}
 * @phpstan-type SolvesGroup array{count: int, best_time_seconds: null|int, last_time_seconds: null|int, first_solved_at: null|string, last_solved_at: null|string}
 * @phpstan-type Item array{
 *     collection_item_id: string,
 *     puzzle_id: string,
 *     puzzle_name: string,
 *     manufacturer_name: null|string,
 *     pieces_count: int,
 *     image: null|string,
 *     comment: null|string,
 *     added_at: string,
 *     statistics: array{solved_times: int, solo: StatisticsGroup, duo: StatisticsGroup, team: StatisticsGroup},
 *     difficulty: null|array{score: null|float, level: null|string, confidence: string, sample_size: int},
 *     prediction: null|array<string, mixed>,
 *     solves: null|array{solo: SolvesGroup, duo: SolvesGroup, team: SolvesGroup},
 * }
 * @phpstan-type ListResponse array{collection_id: string, count: int, items: list<Item>}
 */
final class PlayerCollectionItemsEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    /** @var list<string> the item fields before the insight objects were added - must stay byte-identical */
    private const array ORIGINAL_ITEM_KEYS = ['collection_item_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'comment', 'added_at'];

    public function testExistingFieldsAreUnchangedAndTheInsightObjectsAreAppended(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();

        $raw = $this->decodeJson($browser);
        $this->assertSame(CollectionFixture::COLLECTION_PUBLIC, $raw['collection_id'] ?? null);
        $this->assertSame(8, $raw['count'] ?? null);
        $rawItems = $raw['items'] ?? null;
        $this->assertIsArray($rawItems);
        $this->assertCount(8, $rawItems);

        foreach ($rawItems as $rawItem) {
            $this->assertSame(
                [...self::ORIGINAL_ITEM_KEYS, 'statistics', 'difficulty', 'prediction', 'solves'],
                $this->keys($rawItem),
            );
            $this->assertIsArray($rawItem);
            $this->assertSame(['solved_times', 'solo', 'duo', 'team'], $this->keys($rawItem['statistics']));
        }

        // the values the endpoint returned before this change (ITEM_01 of COLLECTION_PUBLIC)
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        $this->assertSame(CollectionItemFixture::ITEM_01, $item['collection_item_id']);
        $this->assertSame('Puzzle 1', $item['puzzle_name']);
        $this->assertSame('Ravensburger', $item['manufacturer_name']);
        $this->assertSame(500, $item['pieces_count']);
        $this->assertNull($item['image']);
        $this->assertSame('Beautiful landscape puzzle, one of my favorites!', $item['comment']);
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $item['added_at']));
    }

    /**
     * Statistics are public; solves are the collection owner's results, so
     * they need results:read on the token (collections:read alone ⇒ null);
     * a prediction never appears on a /players endpoint.
     */
    public function testSolvesAreTheCollectionOwnersAndNeedResultsRead(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        // PUZZLE_500_01: eleven solo solves of several players
        $this->assertSame(11, $item['statistics']['solved_times']);
        $this->assertSame(11, $item['statistics']['solo']['count']);
        $this->assertSame(1200, $item['statistics']['solo']['fastest_seconds']);
        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertNull($item['solves']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read', 'results:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        // PLAYER_WITH_STRIPE solved PUZZLE_500_01 once (2100 s) - not the token owner's (PLAYER_REGULAR: 1800, 1850, 1750)
        $this->assertSame(['solo', 'duo', 'team'], $this->keys($this->rawItem($browser, PuzzleFixture::PUZZLE_500_01)['solves']));
        $solves = $this->item($response, PuzzleFixture::PUZZLE_500_01)['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(1, $solves['solo']['count']);
        $this->assertSame(2100, $solves['solo']['best_time_seconds']);
        $this->assertSame(2100, $solves['solo']['last_time_seconds']);
        $this->assertSame(0, $solves['duo']['count']);
        $this->assertSame(0, $solves['team']['count']);

        // a puzzle the owner never solved: the object is there, with zeros
        $this->assertSame(0, $this->item($response, PuzzleFixture::PUZZLE_500_04)['solves']['solo']['count'] ?? null);

        foreach ($response['items'] as $item) {
            $this->assertNull($item['prediction']);
        }
    }

    /**
     * Difficulty follows the token owner's membership, whoever owns the collection.
     */
    public function testDifficultyFollowsTheTokenOwnersMembership(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);

        // member token owner looking at a non-member's collection
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR, 'default'));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(3, $response['count']);

        foreach ($response['items'] as $item) {
            // present for every item - a seeded row or the synthesised "insufficient" one
            $this->assertIsArray($item['difficulty']);
            $this->assertNull($item['prediction']);
        }

        $this->assertSame(['score', 'level', 'confidence', 'sample_size'], $this->keys($this->rawItem($browser, PuzzleFixture::PUZZLE_1000_01)['difficulty']));

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20],
            $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_02)['difficulty'],
        );

        // a member looking at their own collection through /players: still no prediction (self-only lives on /me)
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        $this->assertIsArray($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertSame(1, $item['solves']['solo']['count'] ?? null);

        // non-member token owner looking at a member's collection
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_02)['difficulty']);
    }

    /**
     * A machine token has no player: statistics yes, difficulty never; the
     * owner's solves only with results:read (public results data, as
     * /players/{id}/results).
     */
    public function testClientCredentialsTokenGetsStatisticsButNoDifficulty(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_02);
        $this->assertGreaterThan(0, $item['statistics']['solved_times']);
        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertNull($item['solves']);

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertSame(1, $item['solves']['solo']['count'] ?? null);
    }

    public function testPrivateProfileStaysZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_PRIVATE, 'default'));
        $this->assertResponseIsSuccessful();

        $response = $this->decode($browser);
        $this->assertSame('default', $response['collection_id']);
        $this->assertSame(0, $response['count']);
        $this->assertSame([], $response['items']);
        // authentication (3) + the profile lookup - nothing else
        $this->assertQueryCountAtMost($browser, 4, 'private profile short-circuit');
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_01, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);

        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($this->item($response, PuzzleFixture::PUZZLE_500_02)['image']);
        $this->assertSame('puzzles/test/other.jpg', $this->item($response, PuzzleFixture::PUZZLE_500_01)['image']);
    }

    /**
     * Measured (2026-08-19): authentication 3 queries (OAuth2: access token,
     * player, consent usage; a client_credentials token 1-2), the listed
     * player's profile 1, the items 1; then statistics 1, and per entitlement
     * the token owner's profile 1, difficulty 1 (member), solves 1
     * (results:read).
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'client_credentials token (statistics, owner solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'non-member authorization-code token (statistics, profile, owner solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_REGULAR, 'default'));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 9, 'member authorization-code token (statistics, profile, difficulty, owner solves)');
    }

    public function testQueryCountDoesNotGrowWithTheCollectionSize(): void
    {
        $browser = self::createClient();
        $single = $this->seedCollection($browser, PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_9000]);
        $this->seedCollectionItems($browser, CollectionFixture::COLLECTION_PUBLIC, PlayerFixture::PLAYER_WITH_STRIPE, [
            PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_04,
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_2000,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_4000,
            PuzzleFixture::PUZZLE_5000,
            PuzzleFixture::PUZZLE_6000,
            PuzzleFixture::PUZZLE_9000,
            PuzzleFixture::PUZZLE_UNAPPROVED,
        ]);
        // a member with results:read: every object the endpoint can carry
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);

        // warm-up: the token's first request finds the access token in the
        // entity manager the helper saved it through (one lookup fewer) - a
        // test artefact that would skew the comparison
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, $single));
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, $single));
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->decode($browser)['count']);
        $atOne = $this->queryCount($browser);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertSame(20, $this->decode($browser)['count']);
        $this->assertSame($atOne, $this->queryCount($browser), 'The same number of queries for 20 items as for 1');
    }

    private function path(string $playerId, string $collectionId): string
    {
        return '/api/v1/players/' . $playerId . '/collections/' . $collectionId . '/items';
    }

    /**
     * @param array<non-empty-string> $scopes
     */
    private function authenticateOAuth2(KernelBrowser $browser, string $playerId, array $scopes): void
    {
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            $playerId,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    /**
     * @param array<non-empty-string> $scopes
     */
    private function authenticateClientCredentials(KernelBrowser $browser, array $scopes): void
    {
        // sub = aud = client id is exactly what the client_credentials grant issues
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function seedDifficulty(KernelBrowser $browser, string $puzzleId, float $score, MetricConfidence $confidence): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $difficulty = $entityManager->find(PuzzleDifficulty::class, $puzzleId) ?? new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, 20, new DateTimeImmutable());

        $entityManager->persist($difficulty);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function setImage(KernelBrowser $browser, string $puzzleId, string $image, null|DateTimeImmutable $hideUntil): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $puzzle->image = $image;
        $puzzle->hideImageUntil = $hideUntil;
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * A new public collection of the player holding exactly the given puzzles; returns its id.
     *
     * @param list<string> $puzzleIds
     */
    private function seedCollection(KernelBrowser $browser, string $playerId, array $puzzleIds): string
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        $collection = new Collection(
            id: Uuid::uuid7(),
            player: $player,
            name: 'Budget test',
            description: null,
            visibility: CollectionVisibility::Public,
            createdAt: new DateTimeImmutable(),
        );
        $entityManager->persist($collection);
        $entityManager->flush();
        $entityManager->clear();

        $this->seedCollectionItems($browser, $collection->id->toString(), $playerId, $puzzleIds);

        return $collection->id->toString();
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function seedCollectionItems(KernelBrowser $browser, string $collectionId, string $playerId, array $puzzleIds): void
    {
        $entityManager = $this->entityManager($browser);

        $collection = $entityManager->find(Collection::class, $collectionId);
        $this->assertNotNull($collection);
        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            $this->assertNotNull($puzzle);

            $entityManager->persist(new CollectionItem(
                id: Uuid::uuid7(),
                collection: $collection,
                player: $player,
                puzzle: $puzzle,
                comment: null,
                addedAt: new DateTimeImmutable(),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * @return ListResponse
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var ListResponse $decoded */
        $decoded = $this->decodeJson($browser);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * One item of the response as raw decoded JSON - for asserting field names
     * and their order, which the typed decode() cannot.
     *
     * @return array<string, mixed>
     */
    private function rawItem(KernelBrowser $browser, string $puzzleId): array
    {
        $items = $this->decodeJson($browser)['items'] ?? null;
        $this->assertIsArray($items);

        foreach ($items as $item) {
            $this->assertIsArray($item);

            if (($item['puzzle_id'] ?? null) === $puzzleId) {
                /** @var array<string, mixed> $item */
                return $item;
            }
        }

        $this->fail(sprintf('Puzzle %s is not in the response', $puzzleId));
    }

    /**
     * @return list<int|string>
     */
    private function keys(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_keys($value);
    }

    /**
     * @param ListResponse $response
     *
     * @return Item
     */
    private function item(array $response, string $puzzleId): array
    {
        foreach ($response['items'] as $item) {
            if ($item['puzzle_id'] === $puzzleId) {
                return $item;
            }
        }

        $this->fail(sprintf('Puzzle %s is not in the response', $puzzleId));
    }
}
