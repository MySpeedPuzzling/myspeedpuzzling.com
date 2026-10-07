<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
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

    public function testAllApprovedReturnsNextEditionDate(): void
    {
        $series = $this->query->allApproved();

        $found = null;
        foreach ($series as $item) {
            if ($item->id === CompetitionSeriesFixture::SERIES_EJJ) {
                $found = $item;
                break;
            }
        }

        self::assertNotNull($found, 'EJJ series should be in approved list');
        self::assertNotNull($found->nextEditionDate, 'Series with upcoming edition should have nextEditionDate');
    }

    public function testByIdDoesNotIncludeNextEditionDate(): void
    {
        $series = $this->query->byId(CompetitionSeriesFixture::SERIES_EJJ);

        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $series->id);
        self::assertNull($series->nextEditionDate);
    }

    public function testBySlugDoesNotIncludeNextEditionDate(): void
    {
        $series = $this->query->bySlug('euro-jigsaw-jam-series');

        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $series->id);
        self::assertNull($series->nextEditionDate);
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

    public function testAllApprovedFiltersByCountry(): void
    {
        $czech = $this->query->allApproved(country: 'cz');

        $ids = array_map(static fn($s) => $s->id, $czech);
        self::assertContains(CompetitionSeriesFixture::SERIES_OFFLINE, $ids);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $ids, 'German series must not match a Czech country filter');
        self::assertNotContains(CompetitionSeriesFixture::SERIES_EJJ, $ids, 'Online series without country must not match a country filter');
    }

    public function testAllApprovedCountryFilterIsCaseInsensitive(): void
    {
        $german = $this->query->allApproved(country: 'DE');

        $ids = array_map(static fn($s) => $s->id, $german);
        self::assertContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $ids);
    }

    public function testAllApprovedFiltersOnlineOnly(): void
    {
        $online = $this->query->allApproved(onlineOnly: true);

        $ids = array_map(static fn($s) => $s->id, $online);
        self::assertContains(CompetitionSeriesFixture::SERIES_EJJ, $ids);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_OFFLINE, $ids);
    }

    public function testAllApprovedIncludesOfflineSeries(): void
    {
        $all = $this->query->allApproved();

        $offlineFound = false;
        foreach ($all as $series) {
            if ($series->id === CompetitionSeriesFixture::SERIES_OFFLINE) {
                $offlineFound = true;
                self::assertFalse($series->isOnline);
                self::assertSame('Prague', $series->location);
                break;
            }
        }

        self::assertTrue($offlineFound, 'Offline series should be in approved list');
    }
}
