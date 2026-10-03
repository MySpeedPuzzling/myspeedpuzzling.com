<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CountryCupPeriod;

final class CountryCupPeriodTest extends TestCase
{
    #[DataProvider('provideDates')]
    public function testTheFirstSevenDaysOfAMonthShowLastMonth(string $now, CountryCupPeriod $expected): void
    {
        self::assertSame($expected, CountryCupPeriod::defaultAt(new DateTimeImmutable($now)));
    }

    /**
     * @return iterable<string, array{string, CountryCupPeriod}>
     */
    public static function provideDates(): iterable
    {
        yield 'the 1st just after midnight' => ['2026-10-01 00:00:01', CountryCupPeriod::LastMonth];
        yield 'the 3rd' => ['2026-10-03 12:00:00', CountryCupPeriod::LastMonth];
        yield 'the end of the 7th' => ['2026-10-07 23:59:59', CountryCupPeriod::LastMonth];
        yield 'the 8th' => ['2026-10-08 00:00:00', CountryCupPeriod::ThisMonth];
        yield 'the last day of a month' => ['2026-10-31 23:59:59', CountryCupPeriod::ThisMonth];
    }
}
