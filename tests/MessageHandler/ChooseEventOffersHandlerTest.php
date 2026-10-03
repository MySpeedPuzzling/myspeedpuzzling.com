<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Exceptions\PlayerNotGoingToCompetition;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\ChooseEventOffers;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Seller A (PLAYER_WITH_STRIPE) brings SELLSWAP_01 + 02 to the fair, SELLSWAP_07 is still marked for a past edition
 * (.claude/fixtures.md "Marketplace at events").
 */
final class ChooseEventOffersHandlerTest extends KernelTestCase
{
    private const string SELLER_A = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testExactlyTheChosenListingsAreBroughtToThisEventOnly(): void
    {
        // A link to the other event A goes to - must survive the fair's choice
        $this->link(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $this->choose([SellSwapListItemFixture::SELLSWAP_02, SellSwapListItemFixture::SELLSWAP_03, SellSwapListItemFixture::SELLSWAP_03]);

        self::assertSame(
            $this->sorted([SellSwapListItemFixture::SELLSWAP_02, SellSwapListItemFixture::SELLSWAP_03]),
            $this->listingsAt(self::FAIR),
        );
        self::assertSame([SellSwapListItemFixture::SELLSWAP_01], $this->listingsAt(CompetitionSeriesFixture::EDITION_OFFLINE_1));
        // History of the past edition stays
        self::assertSame([SellSwapListItemFixture::SELLSWAP_07], $this->listingsAt(CompetitionSeriesFixture::EDITION_PAST_ONLY_1));
        // Seller B's listings were never touched (B has none marked, still none)
        self::assertNotNull($this->addedAt(SellSwapListItemFixture::SELLSWAP_03, self::FAIR));
    }

    public function testChoosingAgainKeepsExistingRowsAsTheyAre(): void
    {
        $before = $this->addedAt(SellSwapListItemFixture::SELLSWAP_01, self::FAIR);
        self::assertNotNull($before);

        $this->choose([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]);
        $this->choose([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]);

        self::assertSame($this->sorted([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]), $this->listingsAt(self::FAIR));
        self::assertSame($before, $this->addedAt(SellSwapListItemFixture::SELLSWAP_01, self::FAIR));
    }

    public function testChoosingNothingRemovesOnlyThisPair(): void
    {
        $this->choose([]);

        self::assertSame([], $this->listingsAt(self::FAIR));
        self::assertSame([SellSwapListItemFixture::SELLSWAP_07], $this->listingsAt(CompetitionSeriesFixture::EDITION_PAST_ONLY_1));
    }

    public function testUnpublishedListingIsSkipped(): void
    {
        $this->database->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE id IN (:a, :b)',
            ['a' => SellSwapListItemFixture::SELLSWAP_01, 'b' => SellSwapListItemFixture::SELLSWAP_06],
        );

        $this->choose([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_06]);

        // No new link for the unpublished SELLSWAP_06; SELLSWAP_01's existing one is kept (still chosen), 02 is gone
        self::assertSame([SellSwapListItemFixture::SELLSWAP_01], $this->listingsAt(self::FAIR));
    }

    public function testSomeoneElsesListingIsRefused(): void
    {
        try {
            $this->choose([SellSwapListItemFixture::SELLSWAP_03, SellSwapListItemFixture::SELLSWAP_08]);
            self::fail('Expected SellSwapListItemNotFound');
        } catch (SellSwapListItemNotFound) {
        }

        self::assertSame($this->sorted([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]), $this->listingsAt(self::FAIR));
    }

    public function testListingThatNoLongerExistsIsLeftOut(): void
    {
        // Sold or removed in another tab while the picker was open - the rest of the choice is saved
        $this->choose(['018d0014-ffff-0000-0000-000000000001', SellSwapListItemFixture::SELLSWAP_03, 'not-a-uuid']);

        self::assertSame([SellSwapListItemFixture::SELLSWAP_03], $this->listingsAt(self::FAIR));
    }

    public function testPlayerNotGoingIsRefused(): void
    {
        $this->expectException(PlayerNotGoingToCompetition::class);

        // Seller B does not go to the Prague edition
        $this->messageBus->dispatch(new ChooseEventOffers(
            playerId: PlayerFixture::PLAYER_ADMIN,
            competitionId: CompetitionSeriesFixture::EDITION_OFFLINE_1,
            listItemIds: [SellSwapListItemFixture::SELLSWAP_08],
        ));
    }

    public function testPastEventIsRefused(): void
    {
        $this->expectException(CompetitionNotEligibleForMarketplace::class);

        $this->messageBus->dispatch(new ChooseEventOffers(
            playerId: self::SELLER_A,
            competitionId: CompetitionSeriesFixture::EDITION_PAST_ONLY_1,
            listItemIds: [],
        ));
    }

    public function testOnlineEventIsRefused(): void
    {
        $this->expectException(CompetitionNotEligibleForMarketplace::class);

        // Seller B goes to the online edition
        $this->messageBus->dispatch(new ChooseEventOffers(
            playerId: PlayerFixture::PLAYER_ADMIN,
            competitionId: CompetitionSeriesFixture::EDITION_EJJ_69,
            listItemIds: [SellSwapListItemFixture::SELLSWAP_08],
        ));
    }

    public function testBannedSellerIsRefused(): void
    {
        $this->database->executeStatement(
            'UPDATE player SET marketplace_banned = true WHERE id = :id',
            ['id' => self::SELLER_A],
        );

        try {
            $this->choose([SellSwapListItemFixture::SELLSWAP_03]);
            self::fail('Expected MarketplaceBanned');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(MarketplaceBanned::class, $exception->getPrevious());
        }

        self::assertSame($this->sorted([SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02]), $this->listingsAt(self::FAIR));
    }

    /**
     * @param list<string> $listItemIds
     */
    private function choose(array $listItemIds): void
    {
        $this->messageBus->dispatch(new ChooseEventOffers(
            playerId: self::SELLER_A,
            competitionId: self::FAIR,
            listItemIds: $listItemIds,
        ));
    }

    private function link(string $listItemId, string $competitionId): void
    {
        $this->database->insert('sell_swap_list_item_event', [
            'sell_swap_list_item_id' => $listItemId,
            'competition_id' => $competitionId,
            'added_at' => '2026-01-01 10:00:00',
        ]);
    }

    /**
     * @return list<string>
     */
    private function listingsAt(string $competitionId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            'SELECT sell_swap_list_item_id FROM sell_swap_list_item_event WHERE competition_id = :id ORDER BY sell_swap_list_item_id',
            ['id' => $competitionId],
        );

        return $ids;
    }

    private function addedAt(string $listItemId, string $competitionId): null|string
    {
        $addedAt = $this->database->fetchOne(
            'SELECT added_at FROM sell_swap_list_item_event WHERE sell_swap_list_item_id = :item AND competition_id = :competition',
            ['item' => $listItemId, 'competition' => $competitionId],
        );

        return is_string($addedAt) ? $addedAt : null;
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
