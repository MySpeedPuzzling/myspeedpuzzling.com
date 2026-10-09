<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetCompetitionSeriesTest extends KernelTestCase
{
    private GetCompetitionSeries $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCompetitionSeries::class);
    }

    public function testById(): void
    {
        $series = $this->query->byId(CompetitionSeriesFixture::SERIES_EJJ);

        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $series->id);
    }

    public function testBySlug(): void
    {
        $series = $this->query->bySlug('euro-jigsaw-jam-series');

        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $series->id);
    }

    /**
     * docs/features/organizations/README.md: the series page's statement carries the organization (the "Organized by"
     * byline), "Who can enter", "When it happens" and the draft flag
     */
    public function testTheSeriesPageReadsItsOrganizationAndExtras(): void
    {
        $lantern = $this->query->bySlug(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);

        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $lantern->id);
        self::assertNotNull($lantern->organization);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $lantern->organization->id);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $lantern->organization->name);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $lantern->organization->slug);
        self::assertTrue($lantern->organization->isPublic);
        self::assertSame('18+', $lantern->eligibility);
        self::assertSame('Second Thursday of the month, 7:30 pm', $lantern->schedule);
        self::assertFalse($lantern->isDraft);
        self::assertTrue($lantern->isPubliclyVisible());
        self::assertEquals($lantern, $this->query->byId(OrganizationFixture::SERIES_LANTERN_NIGHTS));

        // A draft organization: the ref says it is not public
        $harborMeets = $this->query->byId(OrganizationFixture::SERIES_HARBOR_CLUB_MEETS);
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $harborMeets->organization?->id);
        self::assertFalse($harborMeets->organization->isPublic);
        self::assertTrue($harborMeets->isPubliclyVisible(), 'a draft organization never hides its series');

        // Without an organization
        $ejj = $this->query->byId(CompetitionSeriesFixture::SERIES_EJJ);
        self::assertNull($ejj->organization);
        self::assertNull($ejj->eligibility);
        self::assertNull($ejj->schedule);
    }

    public function testADraftOrPendingSeriesIsNotPubliclyVisible(): void
    {
        $quietPines = $this->query->byId(OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        self::assertTrue($quietPines->isDraft);
        self::assertNotNull($quietPines->approvedAt);
        self::assertFalse($quietPines->isPubliclyVisible());

        self::assertFalse($this->query->byId(OrganizationFixture::SERIES_MAPLE_PENDING)->isPubliclyVisible());
    }

    /**
     * The admin approval queue: waiting for approval and not a draft - a draft is submitted by publishing it
     */
    public function testTheApprovalQueueHasNoDrafts(): void
    {
        $ids = array_map(static fn (CompetitionSeriesOverview $series): string => $series->id, $this->query->allUnapproved());

        self::assertContains(OrganizationFixture::SERIES_MAPLE_PENDING, $ids);
        self::assertContains(CompetitionSeriesFixture::SERIES_UNAPPROVED, $ids);

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::SERIES_MAPLE_PENDING],
        );

        $ids = array_map(static fn (CompetitionSeriesOverview $series): string => $series->id, $this->query->allUnapproved());
        self::assertNotContains(OrganizationFixture::SERIES_MAPLE_PENDING, $ids);
        self::assertContains(CompetitionSeriesFixture::SERIES_UNAPPROVED, $ids);
    }

    public function testUpcomingEditionsOnlineReturnsRoundCount(): void
    {
        $editions = $this->query->upcomingEditions(CompetitionSeriesFixture::SERIES_EJJ);

        self::assertNotEmpty($editions);

        $edition = $editions[0];
        self::assertSame(1, $edition->roundCount);
        self::assertNotNull($edition->startsAt);
        self::assertNotNull($edition->minutesLimit);
    }

    public function testUpcomingEditionsOfflineReturnsMultipleRoundCount(): void
    {
        $editions = $this->query->upcomingEditions(CompetitionSeriesFixture::SERIES_OFFLINE);

        self::assertNotEmpty($editions);

        $edition = $editions[0];
        self::assertSame(2, $edition->roundCount, 'Offline edition with 2 rounds should have roundCount=2');
        self::assertNotNull($edition->startsAt);
    }

    public function testUndatedEditionWithoutRoundsIsListedLastWithTheUpcomingOnes(): void
    {
        $undatedId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddEdition(
            competitionId: $undatedId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'EJJ #70 — date to come',
            dateFrom: null,
            dateTo: null,
            registrationLink: null,
            resultsLink: null,
        ));

        $upcoming = $this->query->upcomingEditions(CompetitionSeriesFixture::SERIES_EJJ);
        $past = $this->query->pastEditions(CompetitionSeriesFixture::SERIES_EJJ);

        $upcomingIds = array_map(static fn (SeriesEdition $edition): string => $edition->competitionId, $upcoming);
        self::assertSame([CompetitionSeriesFixture::EDITION_EJJ_69, $undatedId->toString()], $upcomingIds);
        self::assertNotContains($undatedId->toString(), array_map(static fn (SeriesEdition $edition): string => $edition->competitionId, $past));

        $undated = $upcoming[1];
        self::assertTrue($undated->isUndated());
        self::assertNull($undated->startsAt);
        self::assertNull($undated->dateFrom);
        self::assertSame(0, $undated->roundCount);
        self::assertFalse($upcoming[0]->isUndated());
    }

    public function testEditionWithItsOwnDateButNoRoundCarriesTheDate(): void
    {
        $editionId = Uuid::uuid7();
        $dateFrom = self::getContainer()->get(ClockInterface::class)->now()->modify('+10 days')->setTime(0, 0);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddEdition(
            competitionId: $editionId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'EJJ #70 — no round yet',
            dateFrom: $dateFrom,
            dateTo: $dateFrom,
            registrationLink: null,
            resultsLink: null,
        ));

        $upcoming = $this->query->upcomingEditions(CompetitionSeriesFixture::SERIES_EJJ);

        // Dated by its own date_from: +10 days comes before EJJ #69 (+30 days)
        self::assertSame($editionId->toString(), $upcoming[0]->competitionId);
        self::assertNull($upcoming[0]->startsAt);
        self::assertNotNull($upcoming[0]->dateFrom);
        self::assertSame($dateFrom->format('Y-m-d'), $upcoming[0]->dateFrom->format('Y-m-d'));
        self::assertFalse($upcoming[0]->isUndated());
    }
}
