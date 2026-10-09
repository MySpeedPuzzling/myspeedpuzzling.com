<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\FollowTarget;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventsViewerDataTest extends KernelTestCase
{
    public function testRegularPlayer(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // Going: WJPC 2024 (CompetitionParticipantFixture)
        self::assertTrue($viewer->isGoing(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertFalse($viewer->isGoing(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));

        self::assertTrue($viewer->follows(FollowTarget::series(EventsPageFixture::SERIES_HARBOR_NIGHTS)));
        self::assertTrue($viewer->follows(FollowTarget::competition(EventsPageFixture::COMPETITION_MEADOW_TBA)));
        self::assertFalse($viewer->follows(FollowTarget::competition(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN)));
        // Harbor Jigsaw Nights (EventsPageFixture), the Lantern nights and Quiet Pines (OrganizationFixture)
        self::assertEqualsCanonicalizing(
            [EventsPageFixture::SERIES_HARBOR_NIGHTS, OrganizationFixture::SERIES_LANTERN_NIGHTS, OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
            $viewer->followedSeriesIds(),
        );
        self::assertSame([EventsPageFixture::COMPETITION_MEADOW_TBA], $viewer->followedCompetitionIds());

        // An organization (docs/features/organizations/README.md "Follow")
        self::assertSame([OrganizationFixture::ORGANIZATION_RIVERBEND], $viewer->followedOrganizationIds());
        self::assertTrue($viewer->followsOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND));
        self::assertTrue($viewer->followsOrganization(strtoupper(OrganizationFixture::ORGANIZATION_RIVERBEND)));
        self::assertTrue($viewer->follows(FollowTarget::organization(OrganizationFixture::ORGANIZATION_RIVERBEND)));
        self::assertFalse($viewer->follows(FollowTarget::organization(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT)));
        // The kinds never mix: the organization's id is no followed series
        self::assertFalse($viewer->follows(FollowTarget::series(OrganizationFixture::ORGANIZATION_RIVERBEND)));
        self::assertSame([], $viewer->organizedOrganizationIds());

        // Organised: the unapproved event and Euro Jigsaw Jam (maintainer + creator), Garden Swap (created, rejected)
        $organized = $viewer->organizedCompetitionIds();
        sort($organized);
        $expected = [
            CompetitionFixture::COMPETITION_UNAPPROVED,
            CompetitionFixture::COMPETITION_RECURRING_ONLINE,
            EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED,
        ];
        sort($expected);

        self::assertSame($expected, $organized);
        self::assertSame([], $viewer->organizedSeriesIds());
        self::assertSame(3, $viewer->organizedCount());
    }

    /**
     * An edition is under its series when the viewer organises the series too - one item, not two
     */
    public function testEditionsOfAnOrganisedSeriesAreNotCountedTwice(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_ADMIN);

        self::assertContains(CompetitionSeriesFixture::SERIES_EJJ, $viewer->organizedSeriesIds());
        self::assertContains(EventsPageFixture::SERIES_HARBOR_NIGHTS, $viewer->organizedSeriesIds());
        self::assertContains(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $viewer->organizedCompetitionIds());
        self::assertNotContains(EventsPageFixture::EDITION_HARBOR_1, $viewer->organizedCompetitionIds());
        self::assertSame(count($viewer->organizedCompetitionIds()) + count($viewer->organizedSeriesIds()), $viewer->organizedCount());
    }

    /**
     * The team of an organization organises it, its series and its one-time events (docs/features/organizations/
     * README.md "Permissions"): PLAYER_WITH_FAVORITES maintains Riverbend and created Maple and Cedar
     */
    public function testTheTeamOfAnOrganizationOrganisesEverythingUnderIt(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertEqualsCanonicalizing([
            OrganizationFixture::ORGANIZATION_RIVERBEND,
            OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
            OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT,
        ], $viewer->organizedOrganizationIds());

        // Riverbend's series through its team, Maple's as its creator
        self::assertEqualsCanonicalizing([
            OrganizationFixture::SERIES_LANTERN_NIGHTS,
            OrganizationFixture::SERIES_RIVERBEND_VIRTUAL,
            OrganizationFixture::SERIES_MAPLE_PENDING,
        ], $viewer->organizedSeriesIds());
        // Riverbend's one-time event through its team; editions are under their series
        self::assertSame([OrganizationFixture::COMPETITION_RIVERBEND_OPEN], $viewer->organizedCompetitionIds());

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $viewer->organizationOf(OrganizationFixture::SERIES_LANTERN_NIGHTS));
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $viewer->organizationOf(strtoupper(OrganizationFixture::COMPETITION_RIVERBEND_OPEN)));
        self::assertSame(OrganizationFixture::ORGANIZATION_MAPLE_PENDING, $viewer->organizationOf(OrganizationFixture::SERIES_MAPLE_PENDING));
        self::assertNull($viewer->organizationOf(EventsPageFixture::SERIES_HARBOR_NIGHTS));

        // P9: the three organizations - their series and the Spring Open are listed under them, nothing else is organised
        self::assertSame(3, $viewer->organizedCount());
    }

    /**
     * An item under an organization the viewer is not on the team of is counted on its own; an edition's organization
     * is its series'
     */
    public function testItemsUnderSomebodyElsesOrganizationAreCountedOnTheirOwn(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->insert('competition_maintainer', [
            'competition_id' => OrganizationFixture::EDITION_HARBOR_CLUB_1,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);

        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertContains(OrganizationFixture::EDITION_HARBOR_CLUB_1, $viewer->organizedCompetitionIds());
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $viewer->organizationOf(OrganizationFixture::EDITION_HARBOR_CLUB_1));
        self::assertSame(4, $viewer->organizedCount());
    }

    public function testAPlayerWithNothing(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(0, $viewer->organizedCount());
        self::assertSame([], $viewer->followedSeriesIds());
    }
}
