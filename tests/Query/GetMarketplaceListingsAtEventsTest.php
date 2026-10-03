<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The marketplace's event filter and the event labels (docs/features/marketplace/11-events.md). Fixture roles:
 * seller A = PLAYER_WITH_STRIPE (published SELLSWAP_01-07, brings 01 + 02 to the Swap Fair, also goes to Meetup #1),
 * seller B = PLAYER_ADMIN (published 08, 09, 11, 12, 13 - 10 is not published - goes to the Swap Fair, marks nothing),
 * buyer C = PLAYER_REGULAR (goes to the Swap Fair).
 */
final class GetMarketplaceListingsAtEventsTest extends KernelTestCase
{
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;

    private const array BRINGING = [SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02];

    private const array A_TO_ASK = [
        SellSwapListItemFixture::SELLSWAP_03,
        SellSwapListItemFixture::SELLSWAP_04,
        SellSwapListItemFixture::SELLSWAP_05,
        SellSwapListItemFixture::SELLSWAP_06,
        SellSwapListItemFixture::SELLSWAP_07,
    ];

    private const array B_TO_ASK = [
        SellSwapListItemFixture::SELLSWAP_08,
        SellSwapListItemFixture::SELLSWAP_09,
        SellSwapListItemFixture::SELLSWAP_11,
        SellSwapListItemFixture::SELLSWAP_12,
        SellSwapListItemFixture::SELLSWAP_13,
    ];

    private GetMarketplaceListings $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetMarketplaceListings::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheEventListsThePublishedListingsOfTheSellersGoingThere(): void
    {
        $items = $this->query->search(event: self::FAIR, limit: 100);

        self::assertEqualsCanonicalizing([...self::BRINGING, ...self::A_TO_ASK, ...self::B_TO_ASK], self::ids($items));
        self::assertEqualsCanonicalizing(self::BRINGING, self::ids(array_filter($items, static fn (MarketplaceListingItem $item): bool => $item->bringing)));
    }

    public function testShippingFiltersDoNotApplyUnderAnEvent(): void
    {
        self::assertSame([], $this->query->search(shipsToCountry: 'zz', sellerCountry: 'zz', limit: 100));

        $items = $this->query->search(shipsToCountry: 'zz', sellerCountry: 'zz', event: self::FAIR, limit: 100);

        self::assertCount(12, $items);
        self::assertSame(12, $this->query->count(shipsToCountry: 'zz', sellerCountry: 'zz', event: self::FAIR));
    }

    public function testAnEditionOfASeriesWorksLikeAStandaloneEvent(): void
    {
        $items = $this->query->search(event: CompetitionSeriesFixture::EDITION_OFFLINE_1, limit: 100);

        self::assertEqualsCanonicalizing([...self::BRINGING, ...self::A_TO_ASK], self::ids($items));
        self::assertSame([], array_filter($items, static fn (MarketplaceListingItem $item): bool => $item->bringing));

        $counts = $this->query->countParts(event: CompetitionSeriesFixture::EDITION_OFFLINE_1);
        self::assertSame([7, 0, 7], [$counts->total, $counts->bringing, $counts->toAsk]);
    }

    public function testAnEventThatIsNoMarketplaceEventListsNothing(): void
    {
        // B goes to the online edition, A to the past one (and still marks SELLSWAP_07 for it)
        foreach ([CompetitionSeriesFixture::EDITION_EJJ_69, CompetitionSeriesFixture::EDITION_PAST_ONLY_1] as $event) {
            self::assertSame([], $this->query->search(event: $event, limit: 100));
            self::assertSame(0, $this->query->count(event: $event));
        }
    }

    public function testAValueThatIsNoIdIsIgnored(): void
    {
        $all = $this->query->count();

        self::assertCount($all, $this->query->search(event: 'not-an-event', limit: 100));
        self::assertSame($all, $this->query->count(event: 'not-an-event'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSorts(): iterable
    {
        foreach (['newest', 'price_asc', 'price_desc', 'name_asc', 'name_desc'] as $sort) {
            yield $sort => [$sort];
        }
    }

    #[DataProvider('provideSorts')]
    public function testWhatTheyAreBringingComesFirstWhateverTheSort(string $sort): void
    {
        $withoutEvent = self::ids($this->query->search(sort: $sort, limit: 100));
        $items = $this->query->search(sort: $sort, event: self::FAIR, limit: 100);

        self::assertSame([true, true], array_map(static fn (MarketplaceListingItem $item): bool => $item->bringing, array_slice($items, 0, 2)));
        self::assertNotContains(true, array_map(static fn (MarketplaceListingItem $item): bool => $item->bringing, array_slice($items, 2)));

        // Within each part the chosen sort, the same as without the event
        $ids = self::ids($items);
        self::assertSame(array_values(array_intersect($withoutEvent, array_slice($ids, 0, 2))), array_slice($ids, 0, 2));
        self::assertSame(array_values(array_intersect($withoutEvent, array_slice($ids, 2))), array_slice($ids, 2));
    }

    public function testOnlyWhatTheyAreBringing(): void
    {
        self::assertEqualsCanonicalizing(self::BRINGING, self::ids($this->query->search(event: self::FAIR, onlyBringing: true)));

        // Both parts are still counted, the total is what is listed
        $counts = $this->query->countParts(event: self::FAIR, onlyBringing: true);
        self::assertSame([2, 2, 10], [$counts->total, $counts->bringing, $counts->toAsk]);

        $counts = $this->query->countParts(event: self::FAIR);
        self::assertSame([12, 2, 10], [$counts->total, $counts->bringing, $counts->toAsk]);

        // Without an event the switch means nothing
        self::assertSame($this->query->count(), $this->query->count(onlyBringing: true));
    }

    public function testAGuestSeesWhatIsBroughtWhereAndNothingElse(): void
    {
        $items = self::byId($this->query->search(limit: 100));

        foreach ($items as $id => $item) {
            self::assertNull($item->sellerGoesTo, $id);

            if (in_array($id, self::BRINGING, true)) {
                self::assertSame(self::FAIR, $item->bringingTo?->competitionId, $id);
                self::assertSame('Puzzle Swap Fair', $item->bringingTo->shortName);
            } else {
                // SELLSWAP_07 is still marked for an event that is over: a history row labels nothing
                self::assertNull($item->bringingTo, $id);
            }
        }
    }

    public function testTheLabelNamesTheNearestEvent(): void
    {
        // A goes to Meetup #1 (+14 days) too: marked for both, the nearer one labels the listing
        $this->mark(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $items = self::byId($this->query->search(limit: 100));

        self::assertSame(CompetitionSeriesFixture::EDITION_OFFLINE_1, $items[SellSwapListItemFixture::SELLSWAP_01]->bringingTo?->competitionId);
        self::assertSame(self::FAIR, $items[SellSwapListItemFixture::SELLSWAP_02]->bringingTo?->competitionId);
    }

    public function testABuyerGoingThereSeesWhatToAskTheSellersToBring(): void
    {
        $items = self::byId($this->query->search(limit: 100, viewerId: PlayerFixture::PLAYER_REGULAR));

        foreach ([...self::A_TO_ASK, ...self::B_TO_ASK] as $id) {
            self::assertSame(self::FAIR, $items[$id]->sellerGoesTo?->competitionId, $id);
        }

        // Already coming - nothing to ask for
        foreach (self::BRINGING as $id) {
            self::assertNull($items[$id]->sellerGoesTo, $id);
            self::assertSame(self::FAIR, $items[$id]->bringingTo?->competitionId);
        }
    }

    public function testASellerIsNeverAskedAboutTheirOwnListings(): void
    {
        // A goes to the fair and Meetup #1, B only to the fair
        $items = self::byId($this->query->search(limit: 100, viewerId: PlayerFixture::PLAYER_WITH_STRIPE));

        foreach ([...self::BRINGING, ...self::A_TO_ASK] as $id) {
            self::assertNull($items[$id]->sellerGoesTo, $id);
        }

        foreach (self::B_TO_ASK as $id) {
            self::assertSame(self::FAIR, $items[$id]->sellerGoesTo?->competitionId, $id);
        }
    }

    public function testAViewerGoingNowhereGetsNoAskLabel(): void
    {
        $items = $this->query->search(limit: 100, viewerId: PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame([], array_filter($items, static fn (MarketplaceListingItem $item): bool => $item->sellerGoesTo !== null));
    }

    public function testASellerWhoLeftTheEventLosesTheLabelsAndTheEventList(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_A],
        );

        $items = self::byId($this->query->search(limit: 100, viewerId: PlayerFixture::PLAYER_REGULAR));

        foreach ([...self::BRINGING, ...self::A_TO_ASK] as $id) {
            self::assertNull($items[$id]->bringingTo, $id);
            self::assertNull($items[$id]->sellerGoesTo, $id);
        }

        self::assertEqualsCanonicalizing(self::B_TO_ASK, self::ids($this->query->search(event: self::FAIR, limit: 100)));
    }

    public function testTheListingStreamOfAReservationKeepsItsLabel(): void
    {
        self::assertSame(self::FAIR, $this->query->byItemId(SellSwapListItemFixture::SELLSWAP_01)->bringingTo?->competitionId);
        self::assertNull($this->query->byItemId(SellSwapListItemFixture::SELLSWAP_07)->bringingTo);
    }

    public function testABlockedSellerStaysHiddenUnderTheEvent(): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $items = $this->query->search(event: self::FAIR, limit: 100, viewerId: PlayerFixture::PLAYER_REGULAR);

        self::assertEqualsCanonicalizing([...self::BRINGING, ...self::A_TO_ASK], self::ids($items));
        $counts = $this->query->countParts(event: self::FAIR);
        self::assertSame([7, 2, 5], [$counts->total, $counts->bringing, $counts->toAsk]);
    }

    public function testTheLabelsCostNoQueryPerRow(): void
    {
        /** @var DebugDataHolder $debug */
        $debug = self::getContainer()->get('doctrine.debug_data_holder');

        foreach ([1, 100] as $limit) {
            $debug->reset();
            $this->query->search(limit: $limit, viewerId: PlayerFixture::PLAYER_REGULAR);
            $this->query->search(limit: $limit, event: self::FAIR, viewerId: PlayerFixture::PLAYER_REGULAR);
            $this->query->countParts(event: self::FAIR);

            $queries = $debug->getData()['default'] ?? [];
            self::assertIsArray($queries);
            self::assertCount(3, $queries, "limit {$limit}");
        }
    }

    private function mark(string $listItemId, string $competitionId): void
    {
        $this->database->executeStatement(
            'INSERT INTO sell_swap_list_item_event (sell_swap_list_item_id, competition_id, added_at) VALUES (:item, :competition, :at)',
            ['item' => $listItemId, 'competition' => $competitionId, 'at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array<MarketplaceListingItem> $items
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_values(array_map(static fn (MarketplaceListingItem $item): string => $item->itemId, $items));
    }

    /**
     * @param array<MarketplaceListingItem> $items
     * @return array<string, MarketplaceListingItem>
     */
    private static function byId(array $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $byId[$item->itemId] = $item;
        }

        return $byId;
    }
}
