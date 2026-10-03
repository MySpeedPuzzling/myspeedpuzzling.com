<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetMarketplaceEventsHintState;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Value\MarketplaceEventsBanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMarketplaceEventsHintStateTest extends KernelTestCase
{
    private GetMarketplaceEventsHintState $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetMarketplaceEventsHintState::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testASellerGoingToAnEventWithNothingMarkedIsAskedToChoose(): void
    {
        // A's nearest is Meetup #1 (+14) - the 2 marked listings are for the Swap Fair (+21)
        $state = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertTrue($state->hasPublishedListing);
        self::assertSame(CompetitionSeriesFixture::EDITION_OFFLINE_1, $state->nearestEvent?->competitionId);
        self::assertSame(0, $state->markedForNearestEvent);
        self::assertSame(0, $state->broughtByOthers);
        self::assertSame(MarketplaceEventsBanner::Going, $state->banner(true));

        // Once they mark something there, no banner
        $this->database->executeStatement(
            'INSERT INTO sell_swap_list_item_event (sell_swap_list_item_id, competition_id, added_at) VALUES (:item, :competition, NOW())',
            ['item' => SellSwapListItemFixture::SELLSWAP_03, 'competition' => CompetitionSeriesFixture::EDITION_OFFLINE_1],
        );

        $state = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(1, $state->markedForNearestEvent);
        self::assertNull($state->banner(true));
    }

    public function testOthersBringingCountsOnlySellersStillGoing(): void
    {
        $state = $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN);

        self::assertSame(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $state->nearestEvent?->competitionId);
        self::assertSame(2, $state->broughtByOthers);
        self::assertSame(MarketplaceEventsBanner::Going, $state->banner(true));

        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_A],
        );

        self::assertSame(0, $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN)->broughtByOthers);
    }

    public function testABuyerGoingWherePuzzlesAreComingIsShownThem(): void
    {
        // C goes to the Swap Fair (+21) and WJPC (+30)
        $state = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($state->hasPublishedListing);
        self::assertSame(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $state->nearestEvent?->competitionId);
        self::assertSame(2, $state->broughtByOthers);
        self::assertSame(MarketplaceEventsBanner::Buyer, $state->banner(false));
    }

    public function testABuyerGoingWhereNothingIsComingGetsNoBanner(): void
    {
        $state = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $state->nearestEvent?->competitionId);
        self::assertSame(0, $state->broughtByOthers);
        self::assertNull($state->banner(false));
    }

    public function testASellerGoingNowhereIsShownTheEvents(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_B],
        );

        $state = $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN);

        self::assertTrue($state->hasPublishedListing);
        self::assertNull($state->nearestEvent);
        self::assertSame(MarketplaceEventsBanner::Seller, $state->banner(true));
        // Without a membership nobody can mark puzzles - no seller banner
        self::assertNull($state->banner(false));
    }

    public function testNobodyWithoutListingsGoingNowhere(): void
    {
        $state = $this->query->forPlayer(DuplicateResultsFixture::PLAYER_TWINS);

        self::assertFalse($state->hasPublishedListing);
        self::assertNull($state->nearestEvent);
        self::assertNull($state->banner(true));
    }
}
