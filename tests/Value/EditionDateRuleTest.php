<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EditionDateRule;
use SpeedPuzzling\Web\Value\EditionDateRuleKind;

/**
 * "Add several dates" (docs/features/organizations/README.md): the days a rule proposes - weekly, the 1st-4th weekday of
 * the month, the last weekday of the month - from a starting day, across months and years.
 */
final class EditionDateRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{EditionDateRuleKind, int, string, int, int, list<string>}>
     */
    public static function provideRules(): iterable
    {
        // 5 October 2026 is a Monday
        yield 'weekly from the day itself' => [EditionDateRuleKind::Weekly, 1, '2026-10-05', 3, 1, ['2026-10-05', '2026-10-12', '2026-10-19']];
        yield 'weekly: the first match after the start' => [EditionDateRuleKind::Weekly, 3, '2026-10-05', 2, 1, ['2026-10-07', '2026-10-14']];
        yield 'weekly: Sunday' => [EditionDateRuleKind::Weekly, 7, '2026-10-05', 1, 1, ['2026-10-11']];
        yield 'weekly over the new year' => [EditionDateRuleKind::Weekly, 4, '2026-12-20', 3, 1, ['2026-12-24', '2026-12-31', '2027-01-07']];

        // First Monday of the month
        yield 'first Monday, starting on one' => [EditionDateRuleKind::NthWeekday, 1, '2026-10-05', 3, 1, ['2026-10-05', '2026-11-02', '2026-12-07']];
        yield 'first Monday, starting after this month\'s' => [EditionDateRuleKind::NthWeekday, 1, '2026-10-06', 2, 1, ['2026-11-02', '2026-12-07']];
        yield 'second Tuesday' => [EditionDateRuleKind::NthWeekday, 2, '2026-10-01', 2, 2, ['2026-10-13', '2026-11-10']];
        yield 'third Wednesday over the new year' => [EditionDateRuleKind::NthWeekday, 3, '2026-12-01', 3, 3, ['2026-12-16', '2027-01-20', '2027-02-17']];
        // A month starting on the weekday itself; the 4th always exists
        yield 'fourth Thursday' => [EditionDateRuleKind::NthWeekday, 4, '2026-10-01', 2, 4, ['2026-10-22', '2026-11-26']];

        // Last Tuesday: October 2026 has 5 Tuesdays (27th), February 2027 four
        yield 'last Tuesday' => [EditionDateRuleKind::LastWeekday, 2, '2026-10-01', 6, 1, ['2026-10-27', '2026-11-24', '2026-12-29', '2027-01-26', '2027-02-23', '2027-03-30']];
        yield 'last Tuesday, starting after this month\'s' => [EditionDateRuleKind::LastWeekday, 2, '2026-10-28', 1, 1, ['2026-11-24']];
        yield 'last Sunday on the last day of the month' => [EditionDateRuleKind::LastWeekday, 7, '2026-05-01', 1, 1, ['2026-05-31']];
        yield 'last Friday of a leap February' => [EditionDateRuleKind::LastWeekday, 5, '2028-02-01', 1, 1, ['2028-02-25']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideRules')]
    public function testTheDates(EditionDateRuleKind $kind, int $weekday, string $starting, int $count, int $nth, array $expected): void
    {
        $rule = new EditionDateRule($kind, $weekday, new DateTimeImmutable($starting, new DateTimeZone('UTC')), $count, $nth);

        self::assertSame($expected, array_map(static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'), $rule->dates()));
    }

    public function testTheDaysAreDatesAtMidnightUtcWhateverTheStartsZone(): void
    {
        // 23:30 on Sunday in Los Angeles is already Monday in UTC - the day typed is what counts
        $rule = new EditionDateRule(EditionDateRuleKind::Weekly, 7, new DateTimeImmutable('2026-10-04 23:30', new DateTimeZone('America/Los_Angeles')), 2);

        $dates = $rule->dates();

        self::assertSame('2026-10-04 00:00:00 UTC', $dates[0]->format('Y-m-d H:i:s T'));
        self::assertSame('2026-10-11', $dates[1]->format('Y-m-d'));
    }

    public function testTwentyFourAtMost(): void
    {
        $rule = new EditionDateRule(EditionDateRuleKind::LastWeekday, 2, new DateTimeImmutable('2026-10-01'), 24);
        self::assertCount(24, $rule->dates());
        self::assertSame('2028-09-26', $rule->dates()[23]->format('Y-m-d'));

        $this->expectException(InvalidArgumentException::class);
        new EditionDateRule(EditionDateRuleKind::Weekly, 1, new DateTimeImmutable('2026-10-01'), 25);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'weekday 0' => [0, 1, 1];
        yield 'weekday 8' => [8, 1, 1];
        yield 'count 0' => [1, 0, 1];
        yield 'fifth week' => [1, 1, 5];
    }

    #[DataProvider('provideInvalid')]
    public function testOutOfRangeIsRefused(int $weekday, int $count, int $nth): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EditionDateRule(EditionDateRuleKind::NthWeekday, $weekday, new DateTimeImmutable('2026-10-01'), $count, $nth);
    }
}
