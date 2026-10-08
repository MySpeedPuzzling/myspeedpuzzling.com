<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetEventGoingCounts;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
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

        self::assertSame([EventsPageFixture::COMPETITION_RIVERSIDE_OPEN => 2], $counts);
    }

    public function testNothingAskedNothingRun(): void
    {
        self::bootKernel();

        self::assertSame([], self::getContainer()->get(GetEventGoingCounts::class)->forCompetitions([]));
    }
}
