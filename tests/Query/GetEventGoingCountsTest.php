<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventGoingCounts;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventGoingCountsTest extends KernelTestCase
{
    public function testCountsGoingWithoutTheWaitlist(): void
    {
        self::bootKernel();
        $counts = self::getContainer()->get(GetEventGoingCounts::class)->forCompetitions([
            strtoupper(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN),
            EventsPageFixture::COMPETITION_MEADOW_TBA,
        ]);

        self::assertSame([EventsPageFixture::COMPETITION_RIVERSIDE_OPEN => 2], $counts->going);
        self::assertSame([], $counts->partnerListings);
    }

    /**
     * docs/features/partner-finder.md: open listings only, in the same statement
     */
    public function testCountsOpenPartnerListings(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(GetEventGoingCounts::class);
        $ids = [PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, EventsPageFixture::COMPETITION_RIVERSIDE_OPEN];

        $counts = $query->forCompetitions($ids);

        // Two open listings, the withdrawn one does not count; two going
        self::assertSame([PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN => 2], $counts->partnerListings);
        self::assertSame(2, $counts->going[PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN] ?? null);

        // Listings without anybody going (managed registration: listing needs no spot) still count
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM competition_participant WHERE competition_id = :id',
            ['id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN],
        );
        $counts = $query->forCompetitions($ids);

        self::assertSame([PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN => 2], $counts->partnerListings);
        self::assertSame([EventsPageFixture::COMPETITION_RIVERSIDE_OPEN => 2], $counts->going);
    }

    public function testNothingAskedNothingRun(): void
    {
        self::bootKernel();
        $counts = self::getContainer()->get(GetEventGoingCounts::class)->forCompetitions([]);

        self::assertSame([], $counts->going);
        self::assertSame([], $counts->partnerListings);
    }
}
