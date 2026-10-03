<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMarketplaceEventsTest extends KernelTestCase
{
    private GetMarketplaceEvents $query;
    private Connection $database;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetMarketplaceEvents::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
    }

    public function testUpcomingInPersonStandaloneEventsQualify(): void
    {
        self::assertTrue($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
        self::assertTrue($this->query->qualifies(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertTrue($this->query->qualifies(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testUpcomingInPersonEditionOfApprovedSeriesQualifies(): void
    {
        self::assertTrue($this->query->qualifies(CompetitionSeriesFixture::EDITION_OFFLINE_1));
    }

    public function testOnlineEventsNeverQualify(): void
    {
        self::assertFalse($this->query->qualifies(CompetitionSeriesFixture::EDITION_EJJ_69));
        self::assertFalse($this->query->qualifies(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testPastEventsDoNotQualify(): void
    {
        self::assertFalse($this->query->qualifies(CompetitionSeriesFixture::EDITION_PAST_ONLY_1));

        $this->setDates(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, '-3 days', '-1 day');

        self::assertFalse($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testEventStillQualifiesOnItsLastDay(): void
    {
        $this->setDates(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, '-2 days', 'today 23:00');
        self::assertTrue($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));

        // A one-day event without date_to: the start is the last day
        $this->setDates(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, 'today 08:00', null);
        self::assertTrue($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testUndatedEventDoesNotQualify(): void
    {
        $this->setDates(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, null, '+20 days');

        self::assertFalse($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testEventsThatAreNotPubliclyVisibleDoNotQualify(): void
    {
        // In person and upcoming, but waiting for approval
        self::assertFalse($this->query->qualifies(CompetitionFixture::COMPETITION_UNAPPROVED));

        $this->database->executeStatement(
            'UPDATE competition SET rejected_at = now() WHERE id = :id',
            ['id' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR],
        );
        self::assertFalse($this->query->qualifies(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));

        // An in-person edition of a series that is not approved
        $this->database->executeStatement(
            'UPDATE competition SET is_online = false WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_UNAPPROVED_1],
        );
        self::assertFalse($this->query->qualifies(CompetitionSeriesFixture::EDITION_UNAPPROVED_1));

        self::assertFalse($this->query->qualifies(CompetitionApiFixture::COMPETITION_API_REJECTED));
    }

    public function testUnknownAndInvalidIdsDoNotQualify(): void
    {
        self::assertFalse($this->query->qualifies('00000000-0000-0000-0000-000000000000'));
        self::assertFalse($this->query->qualifies('not-a-uuid'));
    }

    public function testPlayerIsGoingWhenConnectedToTheEvent(): void
    {
        self::assertTrue($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertTrue($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, PlayerFixture::PLAYER_ADMIN));
        self::assertTrue($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, PlayerFixture::PLAYER_WITH_FAVORITES));

        // Attendance only - the event does not have to qualify
        self::assertTrue($this->query->isPlayerGoing(CompetitionSeriesFixture::EDITION_PAST_ONLY_1, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertTrue($this->query->isPlayerGoing(CompetitionSeriesFixture::EDITION_EJJ_69, PlayerFixture::PLAYER_ADMIN));
    }

    public function testSoftDeletedParticipantIsNotGoing(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = now() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_B],
        );

        self::assertFalse($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, PlayerFixture::PLAYER_ADMIN));
        self::assertSame([], $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN));
    }

    public function testInvalidIdsAreNotGoing(): void
    {
        self::assertFalse($this->query->isPlayerGoing('not-a-uuid', PlayerFixture::PLAYER_ADMIN));
        self::assertFalse($this->query->isPlayerGoing(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, 'not-a-uuid'));
        self::assertSame([], $this->query->forPlayer('not-a-uuid'));
    }

    public function testForPlayerListsQualifyingEventsTheyGoToNearestFirst(): void
    {
        // Seller A: the edition (+14 days) before the fair (+21 days); the past edition is left out
        self::assertSame(
            [CompetitionSeriesFixture::EDITION_OFFLINE_1, MarketplaceEventFixture::COMPETITION_SWAP_FAIR],
            self::ids($this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE)),
        );

        // Seller B: the online edition is left out
        self::assertSame(
            [MarketplaceEventFixture::COMPETITION_SWAP_FAIR],
            self::ids($this->query->forPlayer(PlayerFixture::PLAYER_ADMIN)),
        );

        // Buyer C: also on the WJPC list (+30 days)
        self::assertSame(
            [MarketplaceEventFixture::COMPETITION_SWAP_FAIR, CompetitionFixture::COMPETITION_WJPC_2024],
            self::ids($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR)),
        );

        self::assertSame(
            [CompetitionFixture::COMPETITION_WJPC_2024],
            self::ids($this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );

        self::assertSame([], $this->query->forPlayer('00000000-0000-0000-0000-000000000000'));
    }

    public function testStandaloneEventCarriesWhatItsLinksAndLabelsNeed(): void
    {
        $event = $this->query->byId(MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertSame(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $event->competitionId);
        self::assertSame('Puzzle Swap Fair', $event->name);
        self::assertSame('Puzzle Swap Fair', $event->shortName);
        self::assertSame('Olomouc', $event->location);
        self::assertSame(CountryCode::cz, $event->countryCode);
        self::assertSame($this->clock->now()->modify('+21 days')->format('Y-m-d'), $event->dateFrom->format('Y-m-d'));
        self::assertNotNull($event->dateTo);
        self::assertSame('event_detail', $event->reference->routeName());
        self::assertSame(['slug' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR_SLUG], $event->reference->routeParameters());
        self::assertSame('Puzzle Swap Fair', $event->reference->displayName());

        $wjpc = $this->query->byId(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertSame('WJPC24', $wjpc->shortName);
        self::assertSame('WJPC 2024', $wjpc->name);
    }

    public function testEditionLinksToTheEditionPageAndFallsBackToTheSeriesLocation(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET location = NULL, location_country_code = NULL WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1],
        );

        $events = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        $edition = $events[0];

        self::assertSame(CompetitionSeriesFixture::EDITION_OFFLINE_1, $edition->competitionId);
        self::assertSame('Puzzle Meetup #1', $edition->name);
        self::assertSame('edition_detail', $edition->reference->routeName());
        self::assertSame(
            ['seriesSlug' => 'puzzle-meetup-prague', 'editionSlug' => 'puzzle-meetup-1'],
            $edition->reference->routeParameters(),
        );
        self::assertSame('Puzzle Meetup Prague · Puzzle Meetup #1', $edition->reference->displayName());
        self::assertSame('Prague', $edition->location);
        self::assertSame(CountryCode::cz, $edition->countryCode);
    }

    public function testByIdRefusesEventsThatDoNotQualify(): void
    {
        $this->expectException(CompetitionNotEligibleForMarketplace::class);

        $this->query->byId(CompetitionSeriesFixture::EDITION_EJJ_69);
    }

    public function testByIdRefusesInvalidId(): void
    {
        $this->expectException(CompetitionNotEligibleForMarketplace::class);

        $this->query->byId('not-a-uuid');
    }

    public function testFragmentsEmbedIntoOtherStatements(): void
    {
        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
        $going = GetMarketplaceEvents::sqlPlayerGoing('c.id', ':viewerId');

        $count = $this->database->fetchOne(
            <<<SQL
SELECT COUNT(*)
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$qualifies} AND {$going}
SQL,
            ['viewerId' => PlayerFixture::PLAYER_WITH_STRIPE, ...$this->query->todayParameter()],
        );

        self::assertSame(2, $count);
        self::assertSame(['marketplace_today' => $this->clock->now()->format('Y-m-d')], $this->query->todayParameter());
    }

    /**
     * @param list<MarketplaceEvent> $events
     * @return list<string>
     */
    private static function ids(array $events): array
    {
        return array_map(static fn (MarketplaceEvent $event): string => $event->competitionId, $events);
    }

    private function setDates(string $competitionId, null|string $from, null|string $to): void
    {
        $now = $this->clock->now();

        $this->database->executeStatement(
            'UPDATE competition SET date_from = :dateFrom, date_to = :dateTo WHERE id = :id',
            [
                'id' => $competitionId,
                'dateFrom' => $from !== null ? $now->modify($from)->format('Y-m-d H:i:s') : null,
                'dateTo' => $to !== null ? $now->modify($to)->format('Y-m-d H:i:s') : null,
            ],
        );
    }
}
