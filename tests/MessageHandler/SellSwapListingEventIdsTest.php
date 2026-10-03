<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Message\EditSellSwapListItem;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `eventIds` on AddPuzzleToSellSwapList / EditSellSwapListItem (ListingEventLinks): seller A goes to the fair and to
 * EDITION_OFFLINE_1, SELLSWAP_01 is brought to the fair, SELLSWAP_07 is still marked for a past edition.
 */
final class SellSwapListingEventIdsTest extends KernelTestCase
{
    private const string SELLER_A = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;
    private const string PRAGUE = CompetitionSeriesFixture::EDITION_OFFLINE_1;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testWithoutEventIdsTheLinksStayAsTheyAre(): void
    {
        $this->edit(SellSwapListItemFixture::SELLSWAP_01, null);

        self::assertSame([self::FAIR], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));
    }

    public function testEditSyncsAmongTheEventsTheSellerGoesTo(): void
    {
        $this->edit(SellSwapListItemFixture::SELLSWAP_01, [self::PRAGUE]);

        self::assertSame([self::PRAGUE], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));

        $this->edit(SellSwapListItemFixture::SELLSWAP_01, [self::FAIR, strtoupper(self::PRAGUE)]);

        self::assertSame($this->sorted([self::FAIR, self::PRAGUE]), $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));
    }

    public function testHistoryOfPastEventsStays(): void
    {
        $this->edit(SellSwapListItemFixture::SELLSWAP_07, []);

        self::assertSame([CompetitionSeriesFixture::EDITION_PAST_ONLY_1], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_07));
    }

    public function testEventsTheSellerDoesNotGoToAreIgnored(): void
    {
        // WJPC 2024 is a marketplace event, but A is not going; the past edition is A's but over
        $this->edit(SellSwapListItemFixture::SELLSWAP_03, [CompetitionFixture::COMPETITION_WJPC_2024, CompetitionSeriesFixture::EDITION_PAST_ONLY_1, 'not-a-uuid']);

        self::assertSame([], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_03));
    }

    public function testUnpublishedListingGetsNoNewLinkButUntickingStillRemoves(): void
    {
        $this->edit(SellSwapListItemFixture::SELLSWAP_01, [self::FAIR, self::PRAGUE], published: false);

        // The fair's link was there already and stays ticked, Prague is not added for an unpublished listing
        self::assertSame([self::FAIR], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));

        $this->edit(SellSwapListItemFixture::SELLSWAP_01, [], published: false);

        self::assertSame([], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_01));
    }

    public function testNewListingIsBroughtToTheChosenEvent(): void
    {
        $this->messageBus->dispatch(new AddPuzzleToSellSwapList(
            playerId: self::SELLER_A,
            puzzleId: PuzzleFixture::PUZZLE_2000,
            listingType: ListingType::Sell,
            price: 12.0,
            condition: PuzzleCondition::LikeNew,
            comment: null,
            eventIds: [self::PRAGUE],
        ));

        $itemId = $this->database->fetchOne(
            'SELECT id FROM sell_swap_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::SELLER_A, 'puzzle' => PuzzleFixture::PUZZLE_2000],
        );
        self::assertIsString($itemId);

        self::assertSame([self::PRAGUE], $this->eventsOf($itemId));
    }

    public function testAddingAnExistingListingAgainSyncsItsEvents(): void
    {
        // SELLSWAP_02 is PUZZLE_500_02 of seller A, brought to the fair
        $this->messageBus->dispatch(new AddPuzzleToSellSwapList(
            playerId: self::SELLER_A,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            listingType: ListingType::Swap,
            price: null,
            condition: PuzzleCondition::Normal,
            comment: null,
            eventIds: [self::PRAGUE],
        ));

        self::assertSame([self::PRAGUE], $this->eventsOf(SellSwapListItemFixture::SELLSWAP_02));
    }

    /**
     * @param null|list<string> $eventIds
     */
    private function edit(string $listItemId, null|array $eventIds, bool $published = true): void
    {
        $this->messageBus->dispatch(new EditSellSwapListItem(
            sellSwapListItemId: $listItemId,
            playerId: self::SELLER_A,
            listingType: ListingType::Sell,
            price: 20.0,
            condition: PuzzleCondition::Normal,
            comment: null,
            publishedOnMarketplace: $published,
            eventIds: $eventIds,
        ));
    }

    /**
     * @return list<string>
     */
    private function eventsOf(string $listItemId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            'SELECT competition_id FROM sell_swap_list_item_event WHERE sell_swap_list_item_id = :id ORDER BY competition_id',
            ['id' => $listItemId],
        );

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
