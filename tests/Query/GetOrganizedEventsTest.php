<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetOrganizedEvents;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\OrganizerBadge;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
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
                EventsPageFixture::EDITION_SPRINT_SEASON,
                'not-a-uuid',
            ],
            [
                EventsPageFixture::SERIES_HARBOR_NIGHTS,
                EventsPageFixture::SERIES_SUMMIT_LEAGUE,
                EventsPageFixture::SERIES_OLD_MILL_REJECTED,
                CompetitionSeriesFixture::SERIES_UNAPPROVED,
                CompetitionSeriesFixture::SERIES_PAST_ONLY,
                EventsPageFixture::SERIES_SPRINT_LEAGUE,
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
        self::assertSame(OrganizerBadge::Live, $clock->badge($today), 'ongoing (no rounds) is live for its organiser');

        // Rounds on separate days: the next session stands for the edition - between two, it is upcoming, not live
        $sprint = $items[EventsPageFixture::EDITION_SPRINT_SEASON];
        self::assertSame(OrganizerBadge::Upcoming, $sprint->badge($today));
        $roundDays = EventsPageFixture::storedSprintRoundDays(self::getContainer()->get(Connection::class));
        self::assertSame($roundDays[2], $sprint->startDate?->format('Y-m-d'));
        $sprintSeries = $items[EventsPageFixture::SERIES_SPRINT_LEAGUE];
        self::assertSame(1, $sprintSeries->editionCount);
        self::assertSame(OrganizerBadge::Upcoming, $sprintSeries->badge($today));
        self::assertSame($roundDays[2], $sprintSeries->nextEditionDate?->format('Y-m-d'));
        self::assertSame($roundDays[1], $sprintSeries->lastEditionDate?->format('Y-m-d'));

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
     * A series-level time (a series pick no edition holds - docs/features/events-page/high-frequency-series.md P20)
     * keeps the series from going back to draft, not its editions
     */
    public function testSeriesLevelTimeBlocksTheSeriesRowOnly(): void
    {
        self::bootKernel();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series();
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', '2026-09-16');
        $timeId = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $scenario->puzzle(), '2026-08-01', seriesId: $seriesId);
        self::assertNull($scenario->link($timeId)['competition_id'], 'Far from the only edition - series-level');

        $items = $this->byId(self::getContainer()->get(GetOrganizedEvents::class)->byIds([$editionId], [$seriesId]));

        self::assertSame([UnpublishBlocker::SolvingTimes], $items[$seriesId]->unpublishBlockers);
        self::assertSame(1, $items[$seriesId]->editionCount);
        self::assertSame([], $items[$editionId]->unpublishBlockers);
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
