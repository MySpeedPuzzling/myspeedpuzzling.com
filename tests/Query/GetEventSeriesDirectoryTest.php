<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventSeriesDirectory;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventSeriesDirectoryTest extends KernelTestCase
{
    public function testPublicSeries(): void
    {
        self::bootKernel();
        $rows = $this->byId(self::getContainer()->get(GetEventSeriesDirectory::class)->all(false));

        self::assertArrayHasKey(EventsPageFixture::SERIES_HARBOR_NIGHTS, $rows);
        self::assertArrayHasKey(EventsPageFixture::SERIES_SUMMIT_LEAGUE, $rows);
        // Rejected after its approval - not listed (the old allApproved() listed it)
        self::assertArrayNotHasKey(EventsPageFixture::SERIES_OLD_MILL_REJECTED, $rows);
        self::assertArrayNotHasKey(CompetitionSeriesFixture::SERIES_UNAPPROVED, $rows);

        $harbor = $rows[EventsPageFixture::SERIES_HARBOR_NIGHTS];
        self::assertTrue($harbor->isOnline);
        self::assertSame(CountryCode::ca, $harbor->countryCode);
        self::assertSame('harbor-jigsaw-nights', $harbor->slug);
        self::assertTrue($harbor->isPublic);
    }

    public function testAdminsAlsoGetTheOnesWaitingForApproval(): void
    {
        self::bootKernel();
        $rows = $this->byId(self::getContainer()->get(GetEventSeriesDirectory::class)->all(true));

        self::assertArrayHasKey(CompetitionSeriesFixture::SERIES_UNAPPROVED, $rows);
        self::assertFalse($rows[CompetitionSeriesFixture::SERIES_UNAPPROVED]->isPublic);
        self::assertArrayNotHasKey(EventsPageFixture::SERIES_OLD_MILL_REJECTED, $rows);
    }

    /**
     * docs/features/organizations/README.md: a draft series is listed nowhere (not even for admins), one waiting for
     * approval only for admins
     */
    public function testDraftsAreNeverListedPendingOnesOnlyForAdmins(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(GetEventSeriesDirectory::class);
        $public = $this->byId($query->all(false));
        $admin = $this->byId($query->all(true));

        self::assertArrayNotHasKey(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $public);
        self::assertArrayNotHasKey(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $admin);
        self::assertArrayNotHasKey(OrganizationFixture::SERIES_MAPLE_PENDING, $public);
        self::assertArrayHasKey(OrganizationFixture::SERIES_MAPLE_PENDING, $admin);
        self::assertFalse($admin[OrganizationFixture::SERIES_MAPLE_PENDING]->isPublic);

        // A draft organization never hides its series
        self::assertArrayHasKey(OrganizationFixture::SERIES_HARBOR_CLUB_MEETS, $public);
    }

    public function testRowsCarryTheOrganizationEligibilityAndSchedule(): void
    {
        self::bootKernel();
        $rows = $this->byId(self::getContainer()->get(GetEventSeriesDirectory::class)->all(false));

        $lantern = $rows[OrganizationFixture::SERIES_LANTERN_NIGHTS];
        self::assertNotNull($lantern->organization);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $lantern->organization->id);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $lantern->organization->name);
        self::assertSame('RJA', $lantern->organization->shortName);
        self::assertTrue($lantern->organization->isPublic);
        self::assertSame('18+', $lantern->eligibility);
        self::assertSame('Second Thursday of the month, 7:30 pm', $lantern->schedule);
        self::assertFalse($lantern->isDraft);
        self::assertSame(CountryCode::us, $lantern->countryCode);

        $harborMeets = $rows[OrganizationFixture::SERIES_HARBOR_CLUB_MEETS];
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $harborMeets->organization?->id);
        self::assertFalse($harborMeets->organization->isPublic);

        $harborNights = $rows[EventsPageFixture::SERIES_HARBOR_NIGHTS];
        self::assertNull($harborNights->organization);
        self::assertNull($harborNights->eligibility);
        self::assertNull($harborNights->schedule);
    }

    public function testForOrganization(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(GetEventSeriesDirectory::class);

        // By name
        self::assertSame(
            [OrganizationFixture::SERIES_LANTERN_NIGHTS, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL],
            array_keys($this->byId($query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND))),
        );
        self::assertSame(
            [OrganizationFixture::SERIES_HARBOR_CLUB_MEETS],
            array_keys($this->byId($query->forOrganization(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT))),
        );

        // Waiting for approval: only for its team
        self::assertSame([], $query->forOrganization(OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        self::assertSame(
            [OrganizationFixture::SERIES_MAPLE_PENDING],
            array_keys($this->byId($query->forOrganization(OrganizationFixture::ORGANIZATION_MAPLE_PENDING, includeDrafts: true))),
        );

        self::assertSame([], $query->forOrganization('not-a-uuid'));
    }

    public function testADraftSeriesOfAnOrganizationOnlyForItsTeam(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET organization_id = :organizationId WHERE id = :id',
            ['organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'id' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );
        $query = self::getContainer()->get(GetEventSeriesDirectory::class);

        self::assertArrayNotHasKey(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $this->byId($query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND)));

        $team = $this->byId($query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND, includeDrafts: true));
        self::assertArrayHasKey(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $team);
        self::assertTrue($team[OrganizationFixture::SERIES_QUIET_PINES_DRAFT]->isDraft);
        self::assertFalse($team[OrganizationFixture::SERIES_QUIET_PINES_DRAFT]->isPublic);
    }

    /**
     * @param list<EventSeriesRow> $rows
     *
     * @return array<string, EventSeriesRow>
     */
    private function byId(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        return $byId;
    }
}
