<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Value\OrganizerBadge;

/**
 * "You organize" reads "today" in the event's own zone, like the events page (docs/features/events-page/README.md,
 * "Dates") - not the UTC date.
 */
final class OrganizedEventTest extends TestCase
{
    public function testAnEventIsLiveOnItsDayInItsOwnZone(): void
    {
        $edition = new OrganizedEvent(
            kind: OrganizedEvent::KIND_EDITION,
            id: '018d0042-0000-0000-0000-00000000aaaa',
            name: 'Evening Contest',
            startDate: new DateTimeImmutable('2026-01-14', new DateTimeZone('UTC')),
            zone: 'America/New_York',
            isApproved: true,
        );

        // 00:30 UTC on the 15th is 7:30 pm on the 14th in New York
        self::assertSame(OrganizerBadge::Live, $edition->badge(new DateTimeImmutable('2026-01-15 00:30', new DateTimeZone('UTC'))));
        self::assertSame(OrganizerBadge::Past, $edition->badge(new DateTimeImmutable('2026-01-15 06:00', new DateTimeZone('UTC'))));
    }

    public function testASeriesIsLiveWhenItsNextEditionRunsInItsZone(): void
    {
        $series = new OrganizedEvent(
            kind: OrganizedEvent::KIND_SERIES,
            id: '018d0042-0000-0000-0000-00000000bbbb',
            name: 'Harbourside Puzzle Days',
            isApproved: true,
            editionCount: 1,
            nextEditionDate: new DateTimeImmutable('2026-01-16', new DateTimeZone('UTC')),
            nextEditionZone: 'Pacific/Auckland',
        );

        // 23:30 UTC on the 15th is the 16th in Auckland already
        self::assertSame(OrganizerBadge::Live, $series->badge(new DateTimeImmutable('2026-01-15 23:30', new DateTimeZone('UTC'))));
        self::assertSame(OrganizerBadge::Upcoming, $series->badge(new DateTimeImmutable('2026-01-15 09:00', new DateTimeZone('UTC'))));
    }
}
