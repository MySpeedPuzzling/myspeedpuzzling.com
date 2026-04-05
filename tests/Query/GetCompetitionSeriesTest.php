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
}
