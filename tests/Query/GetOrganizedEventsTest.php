<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetOrganizedEvents;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Value\OrganizerBadge;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetOrganizedEventsTest extends KernelTestCase
{
    public function testBadges(): void
    {
        self::bootKernel();
        $today = self::getContainer()->get(ClockInterface::class)->now();
        $items = $this->byId(self::getContainer()->get(GetOrganizedEvents::class)->byIds(
            [
                EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED,
                CompetitionFixture::COMPETITION_UNAPPROVED,
                EventsPageFixture::COMPETITION_RIVERSIDE_OPEN,
                EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR,
                EventsPageFixture::EDITION_CLOCK_LONG,
                'not-a-uuid',
            ],
            [
                EventsPageFixture::SERIES_HARBOR_NIGHTS,
                EventsPageFixture::SERIES_SUMMIT_LEAGUE,
                EventsPageFixture::SERIES_OLD_MILL_REJECTED,
                CompetitionSeriesFixture::SERIES_UNAPPROVED,
                CompetitionSeriesFixture::SERIES_PAST_ONLY,
            ],
        ));

        $garden = $items[EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED];
        self::assertSame(OrganizerBadge::Rejected, $garden->badge($today));
        self::assertSame(EventsPageFixture::GARDEN_SWAP_REJECTION_REASON, $garden->rejectionReason);
        self::assertSame(OrganizedEvent::KIND_EVENT, $garden->kind);

        self::assertSame(OrganizerBadge::WaitingForApproval, $items[CompetitionFixture::COMPETITION_UNAPPROVED]->badge($today));
        self::assertSame(OrganizerBadge::Upcoming, $items[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->badge($today));
        self::assertSame(OrganizerBadge::Past, $items[EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR]->badge($today));

        $clock = $items[EventsPageFixture::EDITION_CLOCK_LONG];
        self::assertSame(OrganizedEvent::KIND_EDITION, $clock->kind);
        self::assertSame(OrganizerBadge::Live, $clock->badge($today));

        $harbor = $items[EventsPageFixture::SERIES_HARBOR_NIGHTS];
        self::assertSame(OrganizedEvent::KIND_SERIES, $harbor->kind);
        // 3 upcoming, 2 past, 1 without a date
        self::assertSame(6, $harbor->editionCount);
        self::assertSame(OrganizerBadge::Upcoming, $harbor->badge($today));
        self::assertNotNull($harbor->nextEditionDate);
        self::assertNotNull($harbor->lastEditionDate);

        self::assertSame(OrganizerBadge::DateNotSet, $items[EventsPageFixture::SERIES_SUMMIT_LEAGUE]->badge($today));
        self::assertSame(0, $items[EventsPageFixture::SERIES_SUMMIT_LEAGUE]->editionCount);
        self::assertSame(OrganizerBadge::Rejected, $items[EventsPageFixture::SERIES_OLD_MILL_REJECTED]->badge($today));
        self::assertSame(OrganizerBadge::WaitingForApproval, $items[CompetitionSeriesFixture::SERIES_UNAPPROVED]->badge($today));
        self::assertSame(OrganizerBadge::Past, $items[CompetitionSeriesFixture::SERIES_PAST_ONLY]->badge($today));
    }

    public function testNothingAsked(): void
    {
        self::bootKernel();

        self::assertSame([], self::getContainer()->get(GetOrganizedEvents::class)->byIds([], []));
    }

    /**
     * @param list<OrganizedEvent> $items
     *
     * @return array<string, OrganizedEvent>
     */
    private function byId(array $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        return $byId;
    }
}
