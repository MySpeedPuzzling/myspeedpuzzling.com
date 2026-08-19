<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/unsolved-puzzles and GET /api/v1/players/{playerId}/unsolved-puzzles
 * - the website's unsolved-puzzles page: the puzzles of the player's
 * collections they have not solved (one entry per puzzle) plus the borrowed
 * puzzles they have not solved, borrowed first; the owner's list in full,
 * another player's when they made it public, a private profile zeroed.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE (member) has not solved
 * PUZZLE_500_05, 500_04, 300, 1000_05, 1000_04, 1000_03 of their collections
 * (newest first) and is borrowing the unsolved PUZZLE_3000 (LENT_06, from
 * PLAYER_REGULAR) - 7; PLAYER_REGULAR (non-member) has PUZZLE_1500_02 and
 * PUZZLE_3000 unsolved in their collections - 2; PLAYER_ADMIN (member) only
 * PUZZLE_500_04 - 1.
 */
final class UnsolvedPuzzlesEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    /** @var list<string> */
    private const array ITEM_KEYS = ['puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'added_at', 'is_borrowed', ...self::INSIGHT_KEYS];

    public function testAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath('00000000-0000-0000-0000-000000000000'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnListIsBorrowedFirstThenCollectionsNewestFirst(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);

        $this->assertSame(
            [
                PuzzleFixture::PUZZLE_3000,
                PuzzleFixture::PUZZLE_500_05,
                PuzzleFixture::PUZZLE_500_04,
                PuzzleFixture::PUZZLE_300,
                PuzzleFixture::PUZZLE_1000_05,
                PuzzleFixture::PUZZLE_1000_04,
                PuzzleFixture::PUZZLE_1000_03,
            ],
            $this->column($items, 'puzzle_id'),
        );
        $this->assertSame([true, false, false, false, false, false, false], $this->column($items, 'is_borrowed'));

        foreach ($items as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
            $this->assertIsString($item['added_at']);
            $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $item['added_at']));
        }

        $borrowed = $items[0];
        $this->assertSame('Puzzle 15', $borrowed['puzzle_name']);
        $this->assertSame('Trefl', $borrowed['manufacturer_name']);
        $this->assertSame(3000, $borrowed['pieces_count']);
        $this->assertNull($borrowed['image']);

        // a puzzle in two collections of the player is listed once (PUZZLE_500_04: COLLECTION_PUBLIC and the wishlist)
        $this->assertCount(1, array_filter($items, static fn (array $item): bool => $item['puzzle_id'] === PuzzleFixture::PUZZLE_500_04));

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_REGULAR, 2);
        $this->assertSame([PuzzleFixture::PUZZLE_1500_02, PuzzleFixture::PUZZLE_3000], $this->column($items, 'puzzle_id'));
        $this->assertSame([false, false], $this->column($items, 'is_borrowed'));
    }

    public function testInsightGatesOnTheOwnList(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_3000, 0.9, MetricConfidence::Low);

        // non-member PAT: statistics and (zero) solves - unsolved by definition
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_REGULAR, 2), PuzzleFixture::PUZZLE_3000);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        // member PAT: difficulty (seeded / synthesised), a statistical prediction, zero solves
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_3000);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame(['score' => 0.9, 'level' => 'average', 'confidence' => 'low', 'sample_size' => 20], $item['difficulty']);
        $this->assertFalse($this->predictionOf($item)['is_personalized']);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);
        $this->assertSame('insufficient', $this->difficultyOf($this->itemOf($items, PuzzleFixture::PUZZLE_300))['confidence']);

        // member authorization-code token: prediction and solves need results:read
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_3000);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: false);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_3000);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);

        // opted out: no prediction
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_3000);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: true);
    }

    public function testAnotherPlayersListFollowsItsVisibility(): void
    {
        $browser = self::createClient();

        // private (the fixture default)
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0));

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0);

        // the owner through /players
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);

        // public
        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        $this->assertTrue($items[0]['is_borrowed']);

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
    }

    /**
     * On somebody else's public list: difficulty follows the token owner, the
     * prediction is the token owner's own, the solves are the list owner's
     * (zeros here - unsolved) and need results:read.
     */
    public function testInsightsOnAnotherPlayersList(): void
    {
        $browser = self::createClient();
        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_05, 1.4, MetricConfidence::High);

        // PLAYER_ADMIN (member) solved PUZZLE_500_05; PLAYER_WITH_STRIPE has not - the
        // prediction is the visitor's (personalised), the solves the owner's (zero)
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_05);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame('hard', $this->difficultyOf($item)['level']);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_05);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: false);

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_05);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
    }

    public function testPrivateProfileIsZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_PRIVATE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0));
        $this->assertQueryCountAtMost($browser, 5, 'private profile short-circuit');

        // the private player sees their own (PUZZLE_500_04 unsolved)
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_PRIVATE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertSame([PuzzleFixture::PUZZLE_500_04], $this->column($this->items($browser, PlayerFixture::PLAYER_PRIVATE, 1), 'puzzle_id'));
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        // one borrowed, one from a collection - both queries apply the embargo
        $this->setImage($browser, PuzzleFixture::PUZZLE_3000, 'puzzles/test/borrowed.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_05, 'puzzles/test/collection.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_300, 'puzzles/test/visible.jpg', hideUntil: new DateTimeImmutable('-1 day'));
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_3000)['image']);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_500_05)['image']);
        $this->assertSame('puzzles/test/visible.jpg', $this->itemOf($items, PuzzleFixture::PUZZLE_300)['image']);
    }

    /**
     * Measured (2026-08-19): authentication 1 (PAT) / 3 (OAuth2) / 1-2
     * (client_credentials), the two item queries (collections, borrowed),
     * statistics 1, the token owner's profile 1, then per entitlement solves 1,
     * difficulty 1 and predictions <= 4; /players adds the listed profile 1.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'non-member PAT');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'member PAT');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token');

        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 7, 'client_credentials token on /players');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 14, 'member authorization-code token on /players');
    }

    /**
     * A member pays the same number of queries for one unsolved puzzle as
     * for twelve (PLAYER_ADMIN has one in the fixtures; eleven more unsolved
     * puzzles are added to their system collection).
     */
    public function testQueryCountDoesNotGrowWithTheListSize(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);

        // warm-up (see WishlistEndpointTest)
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertSame([PuzzleFixture::PUZZLE_500_04], $this->column($this->items($browser, PlayerFixture::PLAYER_ADMIN, 1), 'puzzle_id'));
        $atOne = $this->queryCount($browser);

        $this->seedSystemCollectionItems($browser, PlayerFixture::PLAYER_ADMIN, [
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_03,
            PuzzleFixture::PUZZLE_1000_04,
            PuzzleFixture::PUZZLE_1000_05,
            PuzzleFixture::PUZZLE_300,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_2000,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_4000,
            PuzzleFixture::PUZZLE_5000,
            PuzzleFixture::PUZZLE_6000,
        ]);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_ADMIN, 12);
        $this->assertSame($atOne, $this->queryCount($browser), 'The same number of queries for 12 unsolved puzzles as for 1');
    }

    public function testOpenApiDocumentsBothPaths(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->assertOpenApiDocumentsPaths($browser, ['/api/v1/me/unsolved-puzzles', '/api/v1/players/{playerId}/unsolved-puzzles']);
    }

    private function myPath(): string
    {
        return '/api/v1/me/unsolved-puzzles';
    }

    private function playerPath(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/unsolved-puzzles';
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function seedSystemCollectionItems(KernelBrowser $browser, string $playerId, array $puzzleIds): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            $this->assertNotNull($puzzle);

            $entityManager->persist(new CollectionItem(
                id: Uuid::uuid7(),
                collection: null,
                player: $player,
                puzzle: $puzzle,
                comment: null,
                addedAt: new DateTimeImmutable(),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
