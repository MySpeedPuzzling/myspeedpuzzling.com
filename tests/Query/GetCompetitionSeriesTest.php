<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
