<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetEventSeriesDirectory;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
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
