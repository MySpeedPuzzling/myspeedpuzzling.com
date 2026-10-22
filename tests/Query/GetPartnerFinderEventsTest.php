<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPartnerFinderEvents;
use SpeedPuzzling\Web\Results\PartnerFinderEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gate page's one list (docs/features/partner-finder.md "Part D as built"): every event with a finder, nearest
 * first, with its open listings counted.
 */
final class GetPartnerFinderEventsTest extends KernelTestCase
{
    private GetPartnerFinderEvents $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPartnerFinderEvents::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testQualifyingEventsWithTheirOpenListingsNearestFirst(): void
    {
        $gate = $this->query->all();

        // Two open listings - the closed one is not counted
        $open = $gate->find(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertNotNull($open);
        self::assertSame(2, $open->listingCount);
        self::assertSame(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $open->event->reference->slug);

        // No rounds at all = both kinds = a finder, nobody listed
        $swapFair = $gate->find(MarketplaceEventFixture::COMPETITION_SWAP_FAIR);
        self::assertNotNull($swapFair);
        self::assertSame(0, $swapFair->listingCount);

        $dates = array_map(static fn (PartnerFinderEvent $event): string => $event->event->dateFrom->format('Y-m-d'), $gate->events);
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'nearest first');

        self::assertSame([PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN], array_map(
            static fn (PartnerFinderEvent $event): string => $event->event->competitionId,
            $gate->mostLooking(6),
        ), 'only events with somebody listed');
    }

    public function testEventsWithoutAFinderAreLeftOut(): void
    {
        $gate = $this->query->all();

        self::assertNull($gate->find(OrganizationFixture::COMPETITION_DRAFT_NIGHT), 'a draft');
        self::assertNull($gate->find(OrganizationFixture::EDITION_LANTERN_DRAFT), 'a draft edition');
        self::assertNull($gate->find(OrganizationFixture::EDITION_VIRTUAL_NEXT), 'an online edition');
        self::assertNull($gate->find(OfficialResultsFixture::COMPETITION_RESULTS_CUP), 'a past event');
        self::assertNull($gate->find('not-a-uuid'));

        // Solo rounds only - no finder
        $this->database->executeStatement(
            "UPDATE competition_round SET category = 'solo' WHERE competition_id = :competition",
            ['competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN],
        );
        self::assertNull($this->query->all()->find(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN), 'solo rounds only');
    }

    public function testAPublishedEditionIsNamedWithItsSeries(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::EDITION_LANTERN_DRAFT]);

        $edition = $this->query->all()->find(OrganizationFixture::EDITION_LANTERN_DRAFT);

        self::assertNotNull($edition);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, $edition->event->reference->seriesSlug);
        self::assertStringStartsWith(OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME . ' · ', $edition->event->reference->displayName());
    }

    public function testAnEditionWithoutItsOwnLogoShowsItsSeriesLogo(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_draft = false, logo = NULL WHERE id = :id', ['id' => OrganizationFixture::EDITION_LANTERN_DRAFT]);
        $this->database->executeStatement("UPDATE competition_series SET logo = 'series-logo.png' WHERE slug = :slug", ['slug' => OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG]);

        $edition = $this->query->all()->find(OrganizationFixture::EDITION_LANTERN_DRAFT);
        self::assertNotNull($edition);
        self::assertSame('series-logo.png', $edition->logo);

        $this->database->executeStatement("UPDATE competition SET logo = 'edition-logo.png' WHERE id = :id", ['id' => OrganizationFixture::EDITION_LANTERN_DRAFT]);

        $edition = $this->query->all()->find(OrganizationFixture::EDITION_LANTERN_DRAFT);
        self::assertNotNull($edition);
        self::assertSame('edition-logo.png', $edition->logo);
    }

    public function testMostLookingOrdersByOpenListings(): void
    {
        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN] as $playerId) {
            $this->database->executeStatement(
                "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, co_member_ids, strengths, custom_strengths, languages, created_at, updated_at)
                 VALUES (gen_random_uuid(), :competition, :player, 1, '[\"pair\"]', '[]', '[]', '[]', '[]', " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
                ['competition' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR, 'player' => $playerId],
            );
        }

        $mostLooking = $this->query->all()->mostLooking(6);

        self::assertSame(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $mostLooking[0]->event->competitionId);
        self::assertSame(3, $mostLooking[0]->listingCount);
        self::assertSame(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, $mostLooking[1]->event->competitionId);
        self::assertCount(1, $this->query->all()->mostLooking(1));
    }
}
