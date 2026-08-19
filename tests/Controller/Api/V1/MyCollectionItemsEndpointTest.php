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
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET /api/v1/me/collections/{collectionId}/items - every item carries
 * statistics (public), difficulty (members), prediction (members, self-only)
 * and solves (own history), gated per token exactly as on the website and at
 * a fixed query cost whatever the collection size.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_REGULAR is not a member and owns
 * COLLECTION_FAVORITES (PUZZLE_500_01, PUZZLE_500_02) with three solo solves of
 * PUZZLE_500_02 (2200, 1900, 1700 s); PLAYER_WITH_STRIPE is a member and owns
 * COLLECTION_PUBLIC (8 puzzles, among them PUZZLE_500_01 solved once in 2100 s).
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
 *     prediction: null|array{predicted_seconds: null|int, range_low_seconds: null|int, range_high_seconds: null|int, is_personalized: bool, personal_solve_count: null|int, predicted_attempt_number: null|int, last_time_seconds: null|int},
 *     solves: null|array{solo: SolvesGroup, duo: SolvesGroup, team: SolvesGroup},
 * }
 * @phpstan-type ListResponse array{collection_id: string, count: int, items: list<Item>}
 */
final class MyCollectionItemsEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    /** @var list<string> the item fields before the insight objects were added - must stay byte-identical */
    private const array ORIGINAL_ITEM_KEYS = ['collection_item_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'comment', 'added_at'];

    private const array EMPTY_SOLVES_GROUP = ['count' => 0, 'best_time_seconds' => null, 'last_time_seconds' => null, 'first_solved_at' => null, 'last_solved_at' => null];

    /**
     * The pre-change fields keep their names, order and values; the four
     * objects are appended in a fixed order.
     */
    public function testExistingFieldsAreUnchangedAndTheInsightObjectsAreAppended(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();

        $raw = $this->decodeJson($browser);
        $this->assertSame(CollectionFixture::COLLECTION_FAVORITES, $raw['collection_id'] ?? null);
        $this->assertSame(2, $raw['count'] ?? null);
        $rawItems = $raw['items'] ?? null;
        $this->assertIsArray($rawItems);
        $this->assertCount(2, $rawItems);

        foreach ($rawItems as $rawItem) {
            $this->assertSame(
                [...self::ORIGINAL_ITEM_KEYS, 'statistics', 'difficulty', 'prediction', 'solves'],
                $this->keys($rawItem),
            );
            $this->assertIsArray($rawItem);
            $rawStatistics = $rawItem['statistics'];
            $this->assertIsArray($rawStatistics);
            $this->assertSame(['solved_times', 'solo', 'duo', 'team'], array_keys($rawStatistics));
            $this->assertSame(['count', 'fastest_seconds', 'average_seconds', 'slowest_seconds', 'median_seconds'], $this->keys($rawStatistics['solo']));
            $rawSolves = $rawItem['solves'];
            $this->assertIsArray($rawSolves);
            $this->assertSame(['solo', 'duo', 'team'], array_keys($rawSolves));
            $this->assertSame(['count', 'best_time_seconds', 'last_time_seconds', 'first_solved_at', 'last_solved_at'], $this->keys($rawSolves['solo']));
        }

        // the values the endpoint returned before this change (fixture data, newest first)
        $items = $this->decode($browser)['items'];
        $this->assertSame(CollectionItemFixture::ITEM_08, $items[0]['collection_item_id']);
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $items[0]['puzzle_id']);
        $this->assertSame('Puzzle 2', $items[0]['puzzle_name']);
        $this->assertSame('Ravensburger', $items[0]['manufacturer_name']);
        $this->assertSame(500, $items[0]['pieces_count']);
        $this->assertNull($items[0]['image']);
        $this->assertSame('Solved this 3 times, love it!', $items[0]['comment']);
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $items[0]['added_at']));

        $this->assertSame(CollectionItemFixture::ITEM_07, $items[1]['collection_item_id']);
        $this->assertSame(PuzzleFixture::PUZZLE_500_01, $items[1]['puzzle_id']);
        $this->assertSame('Puzzle 1', $items[1]['puzzle_name']);
        $this->assertSame('Ravensburger', $items[1]['manufacturer_name']);
        $this->assertSame(500, $items[1]['pieces_count']);
        $this->assertNull($items[1]['image']);
        $this->assertNull($items[1]['comment']);
        $this->assertGreaterThan(
            new DateTimeImmutable($items[1]['added_at']),
            new DateTimeImmutable($items[0]['added_at']),
        );
    }

    public function testNonMemberGetsStatisticsAndOwnSolvesButNoDifficultyOrPrediction(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();

        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_02);

        // community statistics, public, split by discipline: ten solo solves of PUZZLE_500_02 in the fixtures
        $this->assertSame(10, $item['statistics']['solved_times']);
        $this->assertSame(10, $item['statistics']['solo']['count']);
        $this->assertSame(1350, $item['statistics']['solo']['fastest_seconds']);
        $this->assertSame(2500, $item['statistics']['solo']['slowest_seconds']);
        $this->assertSame(0, $item['statistics']['duo']['count']);
        $this->assertSame(0, $item['statistics']['team']['count']);

        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);

        // own solves: three solo solves, 2200 → 1900 → 1700, the duo and team disciplines untouched
        $solves = $item['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(3, $solves['solo']['count']);
        $this->assertSame(1700, $solves['solo']['best_time_seconds']);
        $this->assertSame(1700, $solves['solo']['last_time_seconds']);
        $this->assertIsString($solves['solo']['first_solved_at']);
        $this->assertIsString($solves['solo']['last_solved_at']);
        $this->assertLessThan(new DateTimeImmutable($solves['solo']['last_solved_at']), new DateTimeImmutable($solves['solo']['first_solved_at']));
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['duo']);
        $this->assertSame(self::EMPTY_SOLVES_GROUP, $solves['team']);

        // PUZZLE_500_01: three solo solves (1800, a 1850 competition time, 1750) - competition times are the player's own results too
        $solves = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01)['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(3, $solves['solo']['count']);
        $this->assertSame(1750, $solves['solo']['best_time_seconds']);
        $this->assertSame(1750, $solves['solo']['last_time_seconds']);
    }

    public function testMemberGetsDifficultyAndPrediction(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_02, 1.18, MetricConfidence::Medium);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);
        $this->assertSame(8, $response['count']);

        $this->assertSame(
            ['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20],
            $this->item($response, PuzzleFixture::PUZZLE_500_02)['difficulty'],
        );
        // a puzzle without a difficulty row: "insufficient data", not "members only"
        $this->assertSame(
            ['score' => null, 'level' => null, 'confidence' => 'insufficient', 'sample_size' => 0],
            $this->item($response, PuzzleFixture::PUZZLE_500_04)['difficulty'],
        );

        // PUZZLE_500_01: solved once (2100 s) - personal prediction and own solves
        $rawSolved = $this->rawItem($browser, PuzzleFixture::PUZZLE_500_01);
        $this->assertSame(['score', 'level', 'confidence', 'sample_size'], $this->keys($rawSolved['difficulty']));
        $this->assertSame(
            ['predicted_seconds', 'range_low_seconds', 'range_high_seconds', 'is_personalized', 'personal_solve_count', 'predicted_attempt_number', 'last_time_seconds'],
            $this->keys($rawSolved['prediction']),
        );
        $solved = $this->item($response, PuzzleFixture::PUZZLE_500_01);
        $prediction = $solved['prediction'];
        $this->assertNotNull($prediction);
        $this->assertTrue($prediction['is_personalized']);
        $this->assertSame(1, $prediction['personal_solve_count']);
        $this->assertSame(2, $prediction['predicted_attempt_number']);
        $this->assertSame(2100, $prediction['last_time_seconds']);
        $this->assertIsInt($prediction['predicted_seconds']);
        $solves = $solved['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(1, $solves['solo']['count']);
        $this->assertSame(2100, $solves['solo']['best_time_seconds']);

        // PUZZLE_500_04: never solved, nothing to predict from - the object is present, all null
        $this->assertSame(
            ['predicted_seconds' => null, 'range_low_seconds' => null, 'range_high_seconds' => null, 'is_personalized' => false, 'personal_solve_count' => null, 'predicted_attempt_number' => null, 'last_time_seconds' => null],
            $this->item($response, PuzzleFixture::PUZZLE_500_04)['prediction'],
        );
    }

    /**
     * prediction and solves are results data: a collections:read token without
     * results:read gets difficulty only; with results:read it gets all three.
     */
    public function testAuthorizationCodeTokenNeedsResultsReadForPredictionAndSolves(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        $this->assertIsArray($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertNull($item['solves']);
        $this->assertGreaterThan(0, $item['statistics']['solved_times']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_01);
        $this->assertIsArray($item['difficulty']);
        $this->assertTrue($item['prediction']['is_personalized'] ?? null);
        $this->assertSame(1, $item['solves']['solo']['count'] ?? null);

        // a non-member with results:read: solves yes, the members-only objects no
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read', 'results:read']);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();
        $item = $this->item($this->decode($browser), PuzzleFixture::PUZZLE_500_02);
        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);
        $this->assertSame(3, $item['solves']['solo']['count'] ?? null);
    }

    public function testOptedOutMemberKeepsDifficultyAndSolvesButGetsNoPrediction(): void
    {
        $browser = self::createClient();
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();

        foreach ($this->decode($browser)['items'] as $item) {
            $this->assertNull($item['prediction']);
            $this->assertIsArray($item['difficulty']);
            $this->assertIsArray($item['solves']);
        }
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_02, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_01, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertNull($this->item($response, PuzzleFixture::PUZZLE_500_02)['image']);
        $this->assertSame('puzzles/test/other.jpg', $this->item($response, PuzzleFixture::PUZZLE_500_01)['image']);
    }

    /**
     * The query cost is fixed per request and does not grow with the number
     * of items (docs/features/api/v1-expansion-plan.md §6, N3). Measured
     * (2026-08-19): authentication 1 query (PAT) / 3 (OAuth2), the collection
     * lookup 1 (named collections), the items 1; then statistics 1, the
     * owner's profile 1, and per entitlement solves 1, difficulty 1 and
     * predictions 3-4 (GetPlayerPredictions runs its statistical query only
     * when the list holds a puzzle the owner has not solved).
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'non-member PAT (statistics, profile, solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_FAVORITES));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 7, 'non-member authorization-code token without results:read (statistics, profile)');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'member PAT (statistics, profile, difficulty, predictions, solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token');
    }

    /**
     * A member pays the same number of queries for a collection of one puzzle
     * as for one of twenty - both collections hold a puzzle the member has not
     * solved, so both take the complete prediction path and the comparison is
     * about the size only.
     */
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
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // warm-up: a token's first request may find entities the helper saved
        // in the entity manager (one lookup fewer) - a test artefact that would
        // skew the comparison
        $browser->request('GET', $this->path($single));
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path($single));
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->decode($browser)['count']);
        $atOne = $this->queryCount($browser);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path(CollectionFixture::COLLECTION_PUBLIC));
        $this->assertResponseIsSuccessful();
        $this->assertSame(20, $this->decode($browser)['count']);
        $this->assertSame($atOne, $this->queryCount($browser), 'A member pays the same number of queries for 20 items as for 1');
    }

    /**
     * POST /me/collections/{id}/items answers with the same item shape, the
     * four objects included (gated the same way; a non-member PAT here).
     */
    public function testAddedItemCarriesTheSameObjects(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'POST',
            $this->path('default'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['puzzle_id' => PuzzleFixture::PUZZLE_500_02]),
        );
        $this->assertResponseIsSuccessful();

        $raw = $this->decodeJson($browser);
        $this->assertSame([...self::ORIGINAL_ITEM_KEYS, 'statistics', 'difficulty', 'prediction', 'solves'], array_keys($raw));
        $this->assertSame(PuzzleFixture::PUZZLE_500_02, $raw['puzzle_id']);
        /** @var Item $item */
        $item = $raw;
        $this->assertSame(10, $item['statistics']['solved_times']);
        $this->assertNull($item['difficulty']);
        $this->assertNull($item['prediction']);
        $solves = $item['solves'];
        $this->assertNotNull($solves);
        $this->assertSame(3, $solves['solo']['count']);
        $this->assertSame(1700, $solves['solo']['best_time_seconds']);
    }

    public function testEmptyCollectionRunsNoBatchQuery(): void
    {
        $browser = self::createClient();
        $empty = $this->seedCollection($browser, PlayerFixture::PLAYER_WITH_STRIPE, []);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->path($empty));
        $this->assertResponseIsSuccessful();

        $response = $this->decode($browser);
        $this->assertSame($empty, $response['collection_id']);
        $this->assertSame(0, $response['count']);
        $this->assertSame([], $response['items']);
        // authentication, the collection lookup, the (empty) items - nothing else
        $this->assertQueryCountAtMost($browser, 3, 'member PAT, empty collection');
    }

    private function path(string $collectionId): string
    {
        return '/api/v1/me/collections/' . $collectionId . '/items';
    }

    private function authenticatePat(KernelBrowser $browser, string $playerId): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));
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

    private function optOutOfTimePredictions(KernelBrowser $browser, string $playerId): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        $player->changeTimePredictionsOptedOut(true);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * A new collection of the player holding exactly the given puzzles; returns its id.
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
            visibility: CollectionVisibility::Private,
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
     * and their order, which the typed decode() cannot (phpstan folds a typed
     * shape's key list to a constant).
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
     * Keys of a decoded JSON object, for asserting field names and their order.
     *
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
