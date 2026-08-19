<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\LentPuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/lend-borrow and GET /api/v1/players/{playerId}/lend-borrow -
 * the website's lend-borrow page: the puzzles the player lent out (direction
 * "lent", the two tabs' order: lent first), then the ones they are borrowing
 * ("borrowed"), each newest first; the owner's list in full, another player's
 * when public, a private profile zeroed.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE (member) lent LENT_04
 * (PUZZLE_500_03 to PLAYER_WITH_FAVORITES), LENT_02 (PUZZLE_1500_01 to "Jane
 * Doe", not registered), LENT_01 (PUZZLE_2000 to PLAYER_REGULAR), LENT_03
 * (PUZZLE_1000_01, returned - no holder) and borrows LENT_06 (PUZZLE_3000)
 * and LENT_05 (PUZZLE_1500_02) from PLAYER_REGULAR - 6 entries;
 * PLAYER_REGULAR (non-member) has 4 lent + 1 borrowed; PLAYER_ADMIN (member)
 * borrows LENT_07 only.
 */
final class LendBorrowEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    /** @var list<string> */
    private const array ITEM_KEYS = ['lent_puzzle_id', 'direction', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'counterparty', 'lent_at', 'notes', ...self::INSIGHT_KEYS];

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

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath('undefined'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnListIsLentThenBorrowedWithTheCounterpartyTheWebsiteShows(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);

        $this->assertSame(
            [LentPuzzleFixture::LENT_04, LentPuzzleFixture::LENT_02, LentPuzzleFixture::LENT_01, LentPuzzleFixture::LENT_03, LentPuzzleFixture::LENT_06, LentPuzzleFixture::LENT_05],
            $this->column($items, 'lent_puzzle_id'),
        );
        $this->assertSame(['lent', 'lent', 'lent', 'lent', 'borrowed', 'borrowed'], $this->column($items, 'direction'));

        foreach ($items as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
            $this->assertSame(['player_id', 'name'], $this->keys($item['counterparty']));
            $this->assertIsString($item['lent_at']);
            $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $item['lent_at']));
        }

        // lent to a registered player: id + name; notes as on the list
        $this->assertSame(PuzzleFixture::PUZZLE_500_03, $items[0]['puzzle_id']);
        $this->assertSame('Puzzle 3', $items[0]['puzzle_name']);
        $this->assertSame('Ravensburger', $items[0]['manufacturer_name']);
        $this->assertSame(500, $items[0]['pieces_count']);
        $this->assertNull($items[0]['image']);
        $this->assertSame(['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'name' => 'Michael Johnson'], $items[0]['counterparty']);
        $this->assertSame('For testing purposes', $items[0]['notes']);

        // lent to somebody entered by name only
        $this->assertSame(['player_id' => null, 'name' => 'Jane Doe'], $items[1]['counterparty']);
        $this->assertNull($items[1]['notes']);

        // returned: nobody holds it (the website lists it with an empty holder)
        $this->assertSame(['player_id' => null, 'name' => ''], $items[3]['counterparty']);
        $this->assertSame('Returned in good condition', $items[3]['notes']);

        // borrowed from a registered player
        $this->assertSame(PuzzleFixture::PUZZLE_3000, $items[4]['puzzle_id']);
        $this->assertSame(['player_id' => PlayerFixture::PLAYER_REGULAR, 'name' => 'John Doe'], $items[4]['counterparty']);

        // the non-member sees their own list too
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_REGULAR, 5);
        $this->assertSame(['lent', 'lent', 'lent', 'lent', 'borrowed'], $this->column($items, 'direction'));
        $this->assertSame(LentPuzzleFixture::LENT_01, $items[4]['lent_puzzle_id']);
        $this->assertSame(['player_id' => PlayerFixture::PLAYER_WITH_STRIPE, 'name' => 'Sarah Williams'], $items[4]['counterparty']);
    }

    public function testInsightGatesOnTheOwnList(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_2000, 1.18, MetricConfidence::Medium);

        // non-member PAT: PLAYER_REGULAR borrowed PUZZLE_2000 and solved it
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_REGULAR, 5), PuzzleFixture::PUZZLE_2000);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
        $this->assertGreaterThan(0, $this->solvesOf($item)['solo']['count']);

        // member PAT: PLAYER_WITH_STRIPE lent PUZZLE_2000 out and solved it too
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_2000);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame('challenging', $this->difficultyOf($item)['level']);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);
        $this->assertGreaterThan(0, $this->solvesOf($item)['solo']['count']);
        // the borrowed, unsolved PUZZLE_3000: statistical prediction, zero solves, synthesised difficulty
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_3000);
        $this->assertSame('insufficient', $this->difficultyOf($item)['confidence']);
        $this->assertFalse($this->predictionOf($item)['is_personalized']);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6), PuzzleFixture::PUZZLE_2000);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: false);

        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6), PuzzleFixture::PUZZLE_2000);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: true);
    }

    public function testAnotherPlayersListFollowsItsVisibility(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0));

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 0);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);

        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        // the counterparty is what the website prints on the public list - id and display name, nothing more
        $this->assertSame(['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'name' => 'Michael Johnson'], $items[0]['counterparty']);

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);
    }

    public function testInsightsOnAnotherPlayersList(): void
    {
        $browser = self::createClient();
        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_1000_01, 1.18, MetricConfidence::Medium);

        // PLAYER_ADMIN (member) solved PUZZLE_1000_01 (LENT_03) - personal prediction, the owner's solves
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6), PuzzleFixture::PUZZLE_1000_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6), PuzzleFixture::PUZZLE_1000_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: false);

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6), PuzzleFixture::PUZZLE_1000_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);
    }

    public function testPrivateProfileIsZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_PRIVATE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0));
        $this->assertQueryCountAtMost($browser, 5, 'private profile short-circuit');
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        // one lent, one borrowed - both queries apply the embargo
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_03, 'puzzles/test/lent.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_3000, 'puzzles/test/borrowed.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_2000, 'puzzles/test/visible.jpg', hideUntil: null);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 6);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_500_03)['image']);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_3000)['image']);
        $this->assertSame('puzzles/test/visible.jpg', $this->itemOf($items, PuzzleFixture::PUZZLE_2000)['image']);
    }

    /**
     * Measured (2026-08-19): authentication 1 (PAT) / 3 (OAuth2) / 1-2
     * (client_credentials), the two item queries (lent, borrowed), statistics
     * 1, the token owner's profile 1, then per entitlement solves 1, difficulty
     * 1 and predictions <= 4; /players adds the listed profile 1.
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

        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

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
     * A member pays the same number of queries for two entries as for thirteen.
     * PLAYER_ADMIN borrows one puzzle in the fixtures (PUZZLE_500_05, which they
     * solved); a lent-out PUZZLE_9000 (never solved) is added first so that both
     * sizes take the complete prediction path - GetPlayerPredictions runs its
     * statistical query only for puzzles the member has not solved - and the
     * comparison is about the size only; then eleven more.
     */
    public function testQueryCountDoesNotGrowWithTheListSize(): void
    {
        $browser = self::createClient();
        $this->seedLentPuzzles($browser, PlayerFixture::PLAYER_ADMIN, [PuzzleFixture::PUZZLE_9000]);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_ADMIN);

        // warm-up (see WishlistEndpointTest)
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertSame([PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_05], $this->column($this->items($browser, PlayerFixture::PLAYER_ADMIN, 2), 'puzzle_id'));
        $atTwo = $this->queryCount($browser);

        $this->seedLentPuzzles($browser, PlayerFixture::PLAYER_ADMIN, [
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
        $this->items($browser, PlayerFixture::PLAYER_ADMIN, 13);
        $this->assertSame($atTwo, $this->queryCount($browser), 'The same number of queries for 13 entries as for 2');
    }

    public function testOpenApiDocumentsBothPaths(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->assertOpenApiDocumentsPaths($browser, ['/api/v1/me/lend-borrow', '/api/v1/players/{playerId}/lend-borrow']);
    }

    private function myPath(): string
    {
        return '/api/v1/me/lend-borrow';
    }

    private function playerPath(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/lend-borrow';
    }

    /**
     * The player lends the given puzzles to somebody entered by name.
     *
     * @param list<string> $puzzleIds
     */
    private function seedLentPuzzles(KernelBrowser $browser, string $ownerId, array $puzzleIds): void
    {
        $entityManager = $this->entityManager($browser);

        $owner = $entityManager->find(Player::class, $ownerId);
        $this->assertNotNull($owner);

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            $this->assertNotNull($puzzle);

            $entityManager->persist(new LentPuzzle(
                id: Uuid::uuid7(),
                puzzle: $puzzle,
                ownerPlayer: $owner,
                ownerName: null,
                currentHolderPlayer: null,
                currentHolderName: 'Budget test',
                lentAt: new DateTimeImmutable(),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
