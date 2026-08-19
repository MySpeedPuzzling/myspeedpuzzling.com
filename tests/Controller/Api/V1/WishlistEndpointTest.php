<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\WishListItemFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/wishlist and GET /api/v1/players/{playerId}/wishlist - the
 * website's wish-list page: the owner's list in full, another player's when
 * they made it public (every list visibility is private in the fixtures), a
 * private profile zeroed. Each item carries the four insight objects with the
 * collection-item gates: difficulty for a member token owner, prediction =
 * the token owner's own forecast, solves = the list owner's.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_REGULAR (non-member) wishes for
 * PUZZLE_500_04, 500_05, 6000, 5000, 4000 (newest first); PLAYER_WITH_STRIPE
 * (member) for PUZZLE_500_01 (solved once, 2100 s), 3000, 9000; PLAYER_PRIVATE
 * for PUZZLE_4000; PLAYER_ADMIN (member) for nothing.
 */
final class WishlistEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    /** @var list<string> */
    private const array ITEM_KEYS = ['wishlist_item_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'added_at', ...self::INSIGHT_KEYS];

    public function testAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // a PAT is /me only
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // a machine token has no "me"
        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // collections:read is the scope of the whole puzzle library
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath('not-a-uuid'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnWishlistIsCompleteWithPatAndAuthorizationCodeToken(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_REGULAR, 5);

        // newest first, the website's order
        $this->assertSame(
            [PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_5000, PuzzleFixture::PUZZLE_4000],
            $this->column($items, 'puzzle_id'),
        );

        foreach ($items as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
        }

        $item = $items[0];
        $this->assertSame(WishListItemFixture::WISHLIST_09, $item['wishlist_item_id']);
        $this->assertSame('Puzzle 4', $item['puzzle_name']);
        $this->assertSame('Trefl', $item['manufacturer_name']);
        $this->assertSame(500, $item['pieces_count']);
        $this->assertNull($item['image']);
        $this->assertIsString($item['added_at']);
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $item['added_at']));

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_REGULAR, 5);
    }

    /**
     * The gates of a collection item: statistics always; difficulty for a
     * member; prediction for a member who has not opted out, with PAT or
     * results:read; solves (the owner's own) with PAT or results:read.
     */
    public function testInsightGatesOnTheOwnWishlist(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);

        // non-member PAT
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_REGULAR, 5), PuzzleFixture::PUZZLE_500_04);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        // member PAT: PLAYER_WITH_STRIPE solved PUZZLE_500_01 once (2100 s)
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3);
        $this->assertSame([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_9000], $this->column($items, 'puzzle_id'));

        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame(['score' => 1.18, 'level' => 'challenging', 'confidence' => 'medium', 'sample_size' => 20], $item['difficulty']);
        $this->assertSame(11, $this->statisticsOf($item)['solved_times']);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);
        $this->assertSame(1, $this->predictionOf($item)['personal_solve_count']);
        $this->assertSame(2100, $this->predictionOf($item)['last_time_seconds']);
        $this->assertSame(1, $this->solvesOf($item)['solo']['count']);
        $this->assertSame(2100, $this->solvesOf($item)['solo']['best_time_seconds']);

        // an unscored, never-solved puzzle: objects, not null
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_9000);
        $this->assertSame(['score' => null, 'level' => null, 'confidence' => 'insufficient', 'sample_size' => 0], $item['difficulty']);
        $this->assertFalse($this->predictionOf($item)['is_personalized']);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        // member authorization-code token without results:read: difficulty only
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: false);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);

        // opted out of time predictions: difficulty and solves stay, the prediction goes
        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: true);
    }

    /**
     * The website's rule: another player's wishlist is visible when they made
     * it public; the player behind the token sees their own through /players too.
     */
    public function testAnotherPlayersWishlistFollowsItsVisibility(): void
    {
        $browser = self::createClient();

        // private (the fixture default) - zeroed for a stranger and a machine token
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0));

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0);

        // ... but complete for its owner
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3);

        // public - everybody
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3);
        $this->assertSame([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_9000], $this->column($items, 'puzzle_id'));
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3);
    }

    /**
     * On somebody else's public wishlist: difficulty follows the token owner's
     * membership, the prediction is the token owner's own (what the website
     * shows a visitor), the solves are the list owner's and need results:read.
     */
    public function testInsightsOnAnotherPlayersWishlist(): void
    {
        $browser = self::createClient();
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);

        // PLAYER_ADMIN (member) has their own solo history on PUZZLE_500_01 (last 1780 s);
        // the list owner PLAYER_WITH_STRIPE solved it once in 2100 s
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame('challenging', $this->difficultyOf($item)['level']);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);
        $this->assertSame(1780, $this->predictionOf($item)['last_time_seconds']);
        $this->assertSame(2100, $this->solvesOf($item)['solo']['last_time_seconds']);

        // a non-member visitor: no difficulty, no prediction; the owner's solves only with results:read
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: false);
        $this->assertSame(11, $this->statisticsOf($item)['solved_times']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
        $this->assertSame(1, $this->solvesOf($item)['solo']['count']);

        // a machine token: never a member, never a prediction; the owner's public solves with results:read
        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
    }

    public function testPrivateProfileIsZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        // even a public wishlist is hidden behind a private profile
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_PRIVATE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0));
        // authentication (3) + the listed profile + the token owner's profile - nothing else
        $this->assertQueryCountAtMost($browser, 5, 'private profile short-circuit');

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0);

        // the private player sees their own
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_PRIVATE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertSame([PuzzleFixture::PUZZLE_4000], $this->column($this->items($browser, PlayerFixture::PLAYER_PRIVATE, 1), 'puzzle_id'));
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_9000, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_3000, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 3);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_9000)['image']);
        $this->assertSame('puzzles/test/other.jpg', $this->itemOf($items, PuzzleFixture::PUZZLE_3000)['image']);
    }

    /**
     * Measured (2026-08-19): authentication 1 (PAT) / 3 (OAuth2: access token,
     * player, consent usage) / 1-2 (client_credentials), the items 1, statistics
     * 1, the token owner's profile 1, then per entitlement solves 1, difficulty
     * 1 (member) and the token owner's predictions <= 4 (member with PAT /
     * results:read); /players adds the listed player's profile 1.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'non-member PAT (items, statistics, profile, solves)');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 10, 'member PAT (items, statistics, profile, difficulty, predictions, solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 12, 'member authorization-code token');

        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'client_credentials token on /players (profile, items, statistics, owner solves)');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token on /players (profile, items, statistics, own profile, difficulty, own predictions, owner solves)');
    }

    /**
     * A member pays the same number of queries for a wishlist of one puzzle
     * as for one of twelve.
     */
    public function testQueryCountDoesNotGrowWithTheListSize(): void
    {
        $browser = self::createClient();
        $this->seedWishlistItems($browser, PlayerFixture::PLAYER_ADMIN, [PuzzleFixture::PUZZLE_9000]);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);

        // warm-up: a token's first request may find entities the helper saved in
        // the entity manager (one lookup fewer) - a test artefact that would skew
        // the comparison
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_ADMIN, 1);
        $atOne = $this->queryCount($browser);

        $this->seedWishlistItems($browser, PlayerFixture::PLAYER_ADMIN, [
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_1000_01,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1500_01,
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
        $this->assertSame($atOne, $this->queryCount($browser), 'The same number of queries for 12 items as for 1');
    }

    public function testOpenApiDocumentsBothPaths(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->assertOpenApiDocumentsPaths($browser, ['/api/v1/me/wishlist', '/api/v1/players/{playerId}/wishlist']);
    }

    private function myPath(): string
    {
        return '/api/v1/me/wishlist';
    }

    private function playerPath(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/wishlist';
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function seedWishlistItems(KernelBrowser $browser, string $playerId, array $puzzleIds): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            $this->assertNotNull($puzzle);

            $entityManager->persist(new WishListItem(
                id: Uuid::uuid7(),
                player: $player,
                puzzle: $puzzle,
                removeOnCollectionAdd: false,
                addedAt: new DateTimeImmutable(),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
