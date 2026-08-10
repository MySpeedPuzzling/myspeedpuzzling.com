<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\EventsListing;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class EventsListingTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private function component(): EventsListing
    {
        return self::getContainer()->get(EventsListing::class);
    }

    /**
     * @param array<\SpeedPuzzling\Web\Results\CompetitionSeriesOverview> $series
     * @return array<string>
     */
    private function seriesIds(array $series): array
    {
        return array_map(static fn($s) => $s->id, $series);
    }

    public function testPastOnlySeriesIsGroupedAsPastNotUpcoming(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'all';

        $upcomingIds = $this->seriesIds($component->getUpcomingSeriesItems());
        $pastIds = $this->seriesIds($component->getPastSeriesItems());

        self::assertContains(CompetitionSeriesFixture::SERIES_OFFLINE, $upcomingIds, 'Series with a future edition belongs to the upcoming group');
        self::assertNotContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $upcomingIds, 'Series whose only edition already happened must not be presented as upcoming');
        self::assertContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $pastIds);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_OFFLINE, $pastIds);
    }

    public function testUpcomingPeriodShowsOnlyUpcomingSeries(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'upcoming';

        $upcomingIds = $this->seriesIds($component->getUpcomingSeriesItems());

        self::assertContains(CompetitionSeriesFixture::SERIES_OFFLINE, $upcomingIds);
        self::assertSame([], $component->getPastSeriesItems());
    }

    public function testPastPeriodShowsOnlyPastSeries(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'past';

        $pastIds = $this->seriesIds($component->getPastSeriesItems());

        self::assertContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $pastIds);
        self::assertSame([], $component->getUpcomingSeriesItems());
    }

    public function testLivePeriodShowsNoSeries(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'live';

        self::assertSame([], $component->getUpcomingSeriesItems());
        self::assertSame([], $component->getPastSeriesItems());
    }

    public function testSeriesRespectCountryFilter(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'all';
        $component->country = 'cz';

        $upcomingIds = $this->seriesIds($component->getUpcomingSeriesItems());
        $pastIds = $this->seriesIds($component->getPastSeriesItems());

        self::assertContains(CompetitionSeriesFixture::SERIES_OFFLINE, $upcomingIds);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $pastIds, 'German series must be hidden when filtering by Czech Republic');
        self::assertNotContains(CompetitionSeriesFixture::SERIES_EJJ, $upcomingIds, 'Online series without country must be hidden when filtering by country');
    }

    public function testSeriesRespectOnlineOnlyFilter(): void
    {
        self::bootKernel();
        $component = $this->component();
        $component->timePeriod = 'all';
        $component->onlineOnly = true;

        $upcomingIds = $this->seriesIds($component->getUpcomingSeriesItems());
        $pastIds = $this->seriesIds($component->getPastSeriesItems());

        self::assertContains(CompetitionSeriesFixture::SERIES_EJJ, $upcomingIds);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_OFFLINE, $upcomingIds);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_PAST_ONLY, $pastIds);
    }

    public function testRenderedUpcomingViewDoesNotContainPastOnlySeries(): void
    {
        $client = self::createClient();

        $testComponent = $this->createLiveComponent('EventsListing', ['timePeriod' => 'upcoming'], $client);
        $testComponent->setRouteLocale('en');

        $html = $testComponent->render()->toString();

        self::assertStringNotContainsString(CompetitionSeriesFixture::SERIES_PAST_ONLY_NAME, $html);
    }

    public function testRenderedPastViewContainsPastOnlySeries(): void
    {
        $client = self::createClient();

        $testComponent = $this->createLiveComponent('EventsListing', ['timePeriod' => 'past'], $client);
        $testComponent->setRouteLocale('en');

        $html = $testComponent->render()->toString();

        self::assertStringContainsString(CompetitionSeriesFixture::SERIES_PAST_ONLY_NAME, $html);
    }
}
