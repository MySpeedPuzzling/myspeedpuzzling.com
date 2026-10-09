<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\CompetitionPickerDate;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * One date style for the whole add-time picker (docs/features/events-page/high-frequency-series.md "The form"): a day
 * with its weekday, several days as a range, the year only when it is not this year
 */
final class CompetitionPickerDateTest extends KernelTestCase
{
    public function testADayOrARangeWithTheYearOnlyWhenItIsNotThisYear(): void
    {
        self::bootKernel();
        $date = new CompetitionPickerDate(self::getContainer()->get(EventsPageDates::class), new MockClock('2026-10-09 12:00:00'));

        self::assertSame('Tue, 13 Oct', $date->format(new DateTimeImmutable('2026-10-13')));
        self::assertSame('Tue, 13 Oct', $date->format(new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-13')), 'one day, not a range');
        self::assertSame('Tue, 1 Apr 2025', $date->format(new DateTimeImmutable('2025-04-01')));
        self::assertSame('10–11 Oct', $date->format(new DateTimeImmutable('2026-10-10'), new DateTimeImmutable('2026-10-11')));
        self::assertSame('29 Sept – 8 Nov', $date->format(new DateTimeImmutable('2026-09-29'), new DateTimeImmutable('2026-11-08')));
        self::assertSame('30 Dec 2026 – 2 Jan 2027', $date->format(new DateTimeImmutable('2026-12-30'), new DateTimeImmutable('2027-01-02')));
    }
}
