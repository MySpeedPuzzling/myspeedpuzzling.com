<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/sell-swap and GET /api/v1/players/{playerId}/sell-swap - the
 * website's sell-swap page: public for everyone (no visibility setting), so
 * only a private profile is zeroed; items newest first with the public offer
 * fields - who an offer is reserved for is not exposed.
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE (member, currency GBP)
 * offers SELLSWAP_07 (PUZZLE_1000_03, sell 35), 06 (PUZZLE_1500_01, both 60,
 * missing pieces), 05 (PUZZLE_1000_02, swap, reserved), 04 (PUZZLE_500_03,
 * sell 15, reserved for PLAYER_ADMIN), 03 (PUZZLE_1000_01, both 45,
 * reserved), 02 (PUZZLE_500_02, swap), 01 (PUZZLE_500_01, sell 25) - 7;
 * PLAYER_ADMIN (member, custom currency "Kč") 6, SELLSWAP_10 not published on
 * the marketplace; PLAYER_REGULAR (non-member) none.
 */
final class SellSwapEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    /** @var list<string> */
    private const array ITEM_KEYS = ['item_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'image', 'listing_type', 'price', 'currency', 'condition', 'comment', 'is_reserved', 'is_published_on_marketplace', 'added_at', ...self::INSIGHT_KEYS];

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

    public function testOwnListCarriesThePublicOfferFields(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);

        $this->assertSame(
            [
                SellSwapListItemFixture::SELLSWAP_07,
                SellSwapListItemFixture::SELLSWAP_06,
                SellSwapListItemFixture::SELLSWAP_05,
                SellSwapListItemFixture::SELLSWAP_04,
                SellSwapListItemFixture::SELLSWAP_03,
                SellSwapListItemFixture::SELLSWAP_02,
                SellSwapListItemFixture::SELLSWAP_01,
            ],
            $this->column($items, 'item_id'),
        );

        foreach ($items as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item));
            $this->assertIsString($item['added_at']);
            $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $item['added_at']));
            $this->assertTrue($item['is_published_on_marketplace']);
        }

        // sell: price in the seller's currency
        $sell = $this->itemOf($items, PuzzleFixture::PUZZLE_500_01);
        $this->assertSame('Puzzle 1', $sell['puzzle_name']);
        $this->assertSame('Ravensburger', $sell['manufacturer_name']);
        $this->assertSame(500, $sell['pieces_count']);
        $this->assertNull($sell['image']);
        $this->assertSame('sell', $sell['listing_type']);
        $this->assertSame(25.0, $sell['price']);
        $this->assertSame('GBP', $sell['currency']);
        $this->assertSame('like_new', $sell['condition']);
        $this->assertSame('Perfect condition, only solved once', $sell['comment']);
        $this->assertFalse($sell['is_reserved']);

        // swap: no price, no currency
        $swap = $this->itemOf($items, PuzzleFixture::PUZZLE_500_02);
        $this->assertSame('swap', $swap['listing_type']);
        $this->assertNull($swap['price']);
        $this->assertNull($swap['currency']);
        $this->assertSame('normal', $swap['condition']);
        $this->assertNull($swap['comment']);

        // both, missing pieces
        $both = $this->itemOf($items, PuzzleFixture::PUZZLE_1500_01);
        $this->assertSame('both', $both['listing_type']);
        $this->assertSame(60.0, $both['price']);
        $this->assertSame('missing_pieces', $both['condition']);

        // reserved - for whom is the seller's business and not in the response
        $reserved = $this->itemOf($items, PuzzleFixture::PUZZLE_500_03);
        $this->assertTrue($reserved['is_reserved']);
        $this->assertSame('not_so_good', $reserved['condition']);
        $this->assertSame('Some wear on box', $reserved['comment']);
        $this->assertArrayNotHasKey('reserved_for', $reserved);

        // custom currency, an offer off the marketplace
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_ADMIN, 6);
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_500_01);
        $this->assertSame(SellSwapListItemFixture::SELLSWAP_10, $item['item_id']);
        $this->assertSame(22.0, $item['price']);
        $this->assertSame('Kč', $item['currency']);
        $this->assertFalse($item['is_published_on_marketplace']);

        // an empty list
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_REGULAR, 0));
    }

    public function testInsightGatesOnTheOwnList(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);

        // member PAT: PLAYER_WITH_STRIPE solved PUZZLE_500_01 once (2100 s)
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame('challenging', $this->difficultyOf($item)['level']);
        $this->assertSame(11, $this->statisticsOf($item)['solved_times']);
        $this->assertTrue($this->predictionOf($item)['is_personalized']);
        $this->assertSame(2100, $this->predictionOf($item)['last_time_seconds']);
        $this->assertSame(1, $this->solvesOf($item)['solo']['count']);
        // never solved by the seller: zeros, statistical prediction, synthesised difficulty
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_1000_03);
        $this->assertSame('insufficient', $this->difficultyOf($item)['confidence']);
        $this->assertFalse($this->predictionOf($item)['is_personalized']);
        $this->assertSame(0, $this->solvesOf($item)['solo']['count']);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: false);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);

        $this->optOutOfTimePredictions($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $this->myPath());
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: false, solves: true);
    }

    /**
     * The list is public on the website: everybody sees it - a stranger, a
     * machine token, a non-member; only a private profile hides it.
     */
    public function testAnotherPlayersListIsPublic(): void
    {
        $browser = self::createClient();
        $this->seedDifficulty($browser, PuzzleFixture::PUZZLE_500_01, 1.18, MetricConfidence::Medium);

        // a non-member stranger without results:read: the public fields + statistics only
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $this->assertSame(self::ITEM_KEYS, array_keys($items[0]));
        $item = $this->itemOf($items, PuzzleFixture::PUZZLE_500_01);
        $this->assertSame(25.0, $item['price']);
        $this->assertSame('GBP', $item['currency']);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: false);

        // PLAYER_ADMIN (member) solved PUZZLE_500_01 (last 1780 s): their own prediction, the seller's solves
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame('challenging', $this->difficultyOf($item)['level']);
        $this->assertSame(1780, $this->predictionOf($item)['last_time_seconds']);
        $this->assertSame(2100, $this->solvesOf($item)['solo']['last_time_seconds']);

        // a machine token
        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: false, prediction: false, solves: true);

        // the owner through /players, as under /me
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $item = $this->itemOf($this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7), PuzzleFixture::PUZZLE_500_01);
        $this->assertInsightsGated($item, difficulty: true, prediction: true, solves: true);
        $this->assertSame(2100, $this->predictionOf($item)['last_time_seconds']);
    }

    public function testPrivateProfileIsZeroedWithoutBatchQueries(): void
    {
        $browser = self::createClient();
        $this->seedSellSwapItems($browser, PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_500_01]);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0));
        $this->assertQueryCountAtMost($browser, 5, 'private profile short-circuit');

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->items($browser, PlayerFixture::PLAYER_PRIVATE, 0);

        // the private player sees their own
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_PRIVATE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertSame([PuzzleFixture::PUZZLE_500_01], $this->column($this->items($browser, PlayerFixture::PLAYER_PRIVATE, 1), 'puzzle_id'));
    }

    public function testEmbargoedImageIsNull(): void
    {
        $browser = self::createClient();
        $this->setImage($browser, PuzzleFixture::PUZZLE_1000_03, 'puzzles/test/box.jpg', hideUntil: new DateTimeImmutable('+30 days'));
        $this->setImage($browser, PuzzleFixture::PUZZLE_500_01, 'puzzles/test/other.jpg', hideUntil: null);
        $this->authenticateClientCredentials($browser, ['collections:read']);

        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $items = $this->items($browser, PlayerFixture::PLAYER_WITH_STRIPE, 7);
        $this->assertNull($this->itemOf($items, PuzzleFixture::PUZZLE_1000_03)['image']);
        $this->assertSame('puzzles/test/other.jpg', $this->itemOf($items, PuzzleFixture::PUZZLE_500_01)['image']);
    }

    /**
     * Measured (2026-08-19): authentication 1 (PAT) / 3 (OAuth2) / 1-2
     * (client_credentials), the token owner's profile 1 (it carries the
     * currency; memoised, the insights reuse it), the items 1, statistics 1,
     * then per entitlement solves 1, difficulty 1 and predictions <= 4;
     * /players adds the listed profile 1. An empty list runs no batch at all.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        // empty list, non-member PAT: authentication, profile, items
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 3, 'non-member PAT, empty list (no batch)');

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 10, 'member PAT');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 12, 'member authorization-code token');

        $this->authenticateClientCredentials($browser, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'client_credentials token on /players');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'non-member authorization-code token on /players');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['collections:read', 'results:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'member authorization-code token on /players');
    }

    /**
     * A member pays the same number of queries for six offers as for nineteen
     * (PLAYER_ADMIN's fixture list holds a puzzle they have not solved, so both
     * sizes take the complete prediction path).
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
        $this->items($browser, PlayerFixture::PLAYER_ADMIN, 6);
        $atSix = $this->queryCount($browser);

        $this->seedSellSwapItems($browser, PlayerFixture::PLAYER_ADMIN, [
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
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
            PuzzleFixture::PUZZLE_9000,
        ]);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->items($browser, PlayerFixture::PLAYER_ADMIN, 19);
        $this->assertSame($atSix, $this->queryCount($browser), 'The same number of queries for 19 offers as for 6');
    }

    public function testOpenApiDocumentsBothPaths(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->assertOpenApiDocumentsPaths($browser, ['/api/v1/me/sell-swap', '/api/v1/players/{playerId}/sell-swap']);
    }

    private function myPath(): string
    {
        return '/api/v1/me/sell-swap';
    }

    private function playerPath(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/sell-swap';
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function seedSellSwapItems(KernelBrowser $browser, string $playerId, array $puzzleIds): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            $this->assertNotNull($puzzle);

            $entityManager->persist(new SellSwapListItem(
                id: Uuid::uuid7(),
                player: $player,
                puzzle: $puzzle,
                listingType: ListingType::Sell,
                price: 10.0,
                condition: PuzzleCondition::Normal,
                comment: null,
                addedAt: new DateTimeImmutable(),
            ));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
