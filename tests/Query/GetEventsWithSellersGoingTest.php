<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventsWithSellersGoing;
use SpeedPuzzling\Web\Results\EventWithSellersGoing;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventsWithSellersGoingTest extends KernelTestCase
{
    private GetEventsWithSellersGoing $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetEventsWithSellersGoing::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testMarketplaceEventsSellersAreGoingToNearestFirstWithTheirCounts(): void
    {
        // Meetup #1 (+14): A goes, nothing marked (7 published listings). Swap Fair (+21): A brings 2 of 7, B goes
        // with 5 published. WJPC / Czech Nationals qualify too, but nobody with a published listing goes there;
        // the online and the past edition never qualify.
        self::assertSame([
            [CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Puzzle Meetup #1', 0, 7],
            [MarketplaceEventFixture::COMPETITION_SWAP_FAIR, 'Puzzle Swap Fair', 2, 10],
        ], self::rows($this->query->load()));
    }

    public function testOnlyPublishedListingsOfSellersStillGoingCount(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_B],
        );
        $this->database->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE id = :id',
            ['id' => SellSwapListItemFixture::SELLSWAP_02],
        );

        self::assertSame([
            [CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Puzzle Meetup #1', 0, 6],
            [MarketplaceEventFixture::COMPETITION_SWAP_FAIR, 'Puzzle Swap Fair', 1, 5],
        ], self::rows($this->query->load()));
    }

    public function testTheListIsCachedForEveryone(): void
    {
        $cached = self::rows($this->query->all());

        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE competition_id = :id',
            ['id' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR],
        );

        self::assertSame($cached, self::rows($this->query->all()));
        self::assertCount(1, $this->query->load());
    }

    /**
     * @param list<EventWithSellersGoing> $events
     * @return list<array{string, string, int, int}>
     */
    private static function rows(array $events): array
    {
        return array_map(
            static fn (EventWithSellersGoing $event): array => [$event->event->competitionId, $event->event->shortName, $event->bringingCount, $event->askCount],
            $events,
        );
    }
}
