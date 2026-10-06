<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ApiUsageMonth;

final class ApiUsageMonthTest extends TestCase
{
    public function testCurrentMonthUpToToday(): void
    {
        $month = ApiUsageMonth::fromUserInput(null, new DateTimeImmutable('2026-10-07 01:30:00 Europe/Prague'));

        // 01:30 in Prague is still 6 October in UTC
        self::assertSame('2026-10', $month->value());
        self::assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'], $month->days());
        self::assertNull($month->next());
        self::assertSame('2026-09', $month->previous()?->value());
    }

    public function testPastMonthHasEveryDay(): void
    {
        $month = ApiUsageMonth::fromUserInput('2026-02', new DateTimeImmutable('2026-10-07 12:00:00 UTC'));

        self::assertCount(28, $month->days());
        self::assertSame('2026-03-01', $month->end()->format('Y-m-d'));
        self::assertSame('2026-03', $month->next()?->value());
    }

    public function testFallsBackToTheCurrentMonth(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00 UTC');

        foreach (['2026-11', '2024-09', '2026-13', 'october', ['2026-09']] as $input) {
            self::assertSame('2026-10', ApiUsageMonth::fromUserInput($input, $now)->value());
        }

        // The oldest month the 24-month retention keeps
        self::assertSame('2024-10', ApiUsageMonth::fromUserInput('2024-10', $now)->value());
        self::assertNull(ApiUsageMonth::fromUserInput('2024-10', $now)->previous());
    }
}
