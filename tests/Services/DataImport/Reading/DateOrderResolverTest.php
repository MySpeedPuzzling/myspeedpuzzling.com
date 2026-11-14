<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\DateOrderResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DateOrderState;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use Symfony\Component\Clock\MockClock;

/**
 * "Text dates start with every order allowed and lose the orders a value contradicts (a 13 in the first place settles
 * it); still open = one question" (docs/features/data-import/README.md "Dates").
 */
final class DateOrderResolverTest extends TestCase
{
    public function testAColumnWithoutDatesInNumbersNeedsNoOrder(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column([
            '2023-12-30',
            '3 March 2024',
            'Sept 2020',
            '2020',
            '',
            'garbage',
        ]));

        self::assertSame(DateOrderState::NotNeeded, $resolution->state);
        self::assertNull($resolution->order());
        self::assertSame(0, $resolution->rows);
    }

    public function testAnEmptyColumnNeedsNoOrder(): void
    {
        self::assertSame(DateOrderState::NotNeeded, (new DateOrderResolver())->resolve([])->state);
    }

    public function testAThirteenInTheFirstPlaceSettlesDayFirst(): void
    {
        $dates = self::column(['3/4/2023', '5/6/2023', '13/4/2023', '1/2/2023']);
        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame(DateOrderState::Resolved, $resolution->state);
        self::assertSame(DateOrder::DayMonthYear, $resolution->order());
        self::assertSame([DateOrder::DayMonthYear], $resolution->orders);
        self::assertSame(4, $resolution->rows);
        self::assertSame(['dmy' => 4, 'mdy' => 3, 'ymd' => 0], $resolution->rowsByOrder);

        $applied = $resolution->apply($dates[0]);
        self::assertSame(DateCertainty::Certain, $applied->certainty);
        self::assertSame('2023-04-03', $applied->date?->format('Y-m-d'));
    }

    public function testAThirteenInTheSecondPlaceSettlesMonthFirst(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['3/4/2023', '4/13/2023', '12/1/2023']));

        self::assertSame(DateOrder::MonthDayYear, $resolution->order());
    }

    public function testAColumnNoValueSettlesIsAQuestion(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['3/4/2023', '5/6/2023', '1/2/2023', '2023-12-30', 'March 2024']));

        self::assertSame(DateOrderState::Ambiguous, $resolution->state);
        self::assertNull($resolution->order());
        self::assertSame([DateOrder::DayMonthYear, DateOrder::MonthDayYear], $resolution->orders);
        self::assertSame([0, 1, 2], $resolution->exampleRows);
        self::assertSame(3, $resolution->rows);
        self::assertNull($resolution->suggested);
    }

    public function testAnOpenQuestionChangesNothing(): void
    {
        $dates = self::column(['3/4/2023', '5/6/2023']);
        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame($dates[0], $resolution->apply($dates[0]));
    }

    public function testValuesThatReadTheSameEitherWayAskNothing(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['3/3/2023', '5/5/2023', '12/12/2023']));

        self::assertSame(DateOrderState::Resolved, $resolution->state);
        self::assertSame(DateOrder::DayMonthYear, $resolution->order(), 'Both orders read every value the same - the first stands for both');
    }

    public function testADateInTheFutureDoesNotSettleTheOrderButOffersTheOtherFirst(): void
    {
        // Today is 14 October 2026: day-first, 5/12/2026 is 5 December - people mistype years, so it is only a hint
        $resolution = (new DateOrderResolver())->resolve(self::column(['5/12/2026', '3/4/2026']));

        self::assertSame(DateOrderState::Ambiguous, $resolution->state);
        self::assertSame(DateOrder::MonthDayYear, $resolution->suggested);
    }

    public function testAColumnThatMixesOrdersIsAProblem(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['30/12/2023', '25/11/2023', '12/30/2023', '3/4/2023']));

        self::assertSame(DateOrderState::Mixed, $resolution->state);
        self::assertNull($resolution->order());
        self::assertSame([], $resolution->orders);
        self::assertSame(['dmy' => 3, 'mdy' => 2, 'ymd' => 0], $resolution->rowsByOrder);
        self::assertSame([2], $resolution->exampleRows, 'The row that is no date in the order most rows follow');
    }

    public function testTwoDigitYearsLeaveTheYearFirstOrderOpen(): void
    {
        // "30/12/23" is 30 December 2023 day-first and 23 December 2030 year-first: no value contradicts either
        $european = (new DateOrderResolver())->resolve(self::column(['30/12/23', '15/01/24', '28/02/24']));

        self::assertSame(DateOrderState::Ambiguous, $european->state);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::YearMonthDay], $european->orders);
        self::assertSame(DateOrder::DayMonthYear, $european->suggested, 'Year-first puts a date into 2030');
    }

    public function testAYearThatIsNoDaySettlesYearFirst(): void
    {
        // Japan's yy/mm/dd: only a first part that is no day rules day-first out
        $resolution = (new DateOrderResolver())->resolve(self::column(['23/12/30', '24/01/15', '99/12/31']));

        self::assertSame(DateOrder::YearMonthDay, $resolution->order(), '99 is no day of a month');
    }

    public function testYearFirstDatesOfThisCenturyStayAQuestionWithTheRightOrderOfferedFirst(): void
    {
        // 23/12/30 day-first is 23 December 2030 - possible, so it is asked; but it is in the future, so year-first is offered
        $resolution = (new DateOrderResolver())->resolve(self::column(['23/12/30', '24/01/15', '22/11/05']));

        self::assertSame(DateOrderState::Ambiguous, $resolution->state);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::YearMonthDay], $resolution->orders);
        self::assertSame(DateOrder::YearMonthDay, $resolution->suggested);
    }

    public function testAFourDigitYearRulesTheYearFirstOrderOut(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['30/12/23', '15/01/2024']));

        self::assertSame(DateOrder::DayMonthYear, $resolution->order());
    }

    public function testDatesWithoutAYearFollowTheSameOrder(): void
    {
        $resolver = new DateOrderResolver();

        $dayFirst = $resolver->resolve(self::column(['1/3', '25/3', '4/5']));
        self::assertSame(DateOrder::DayMonthYear, $dayFirst->order());

        // Month-first and year-first read a date without a year the same
        $monthFirst = $resolver->resolve(self::column(['1/3', '3/25', '4/5']));
        self::assertSame(DateOrderState::Resolved, $monthFirst->state);
        self::assertSame(DateOrder::MonthDayYear, $monthFirst->order());

        $open = $resolver->resolve(self::column(['1/3', '4/5']));
        self::assertSame(DateOrderState::Ambiguous, $open->state);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::MonthDayYear], $open->orders, 'Year-first is the same answer as month-first here');
    }

    public function testDatesWithAndWithoutAYearSettleEachOther(): void
    {
        $dates = self::column(['1/3', '13/4/2023', '5/6']);
        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame(DateOrder::DayMonthYear, $resolution->order());
        self::assertSame('2000-03-01', $resolution->apply($dates[0])->date?->format('Y-m-d'));
        self::assertFalse($resolution->apply($dates[0])->yearKnown);
    }

    public function testOneValueIsEnough(): void
    {
        self::assertSame(DateOrder::MonthDayYear, (new DateOrderResolver())->resolve(self::column(['12/30/2023']))->order());
        self::assertSame(DateOrderState::Ambiguous, (new DateOrderResolver())->resolve(self::column(['3/4/2023']))->state);
    }

    public function testAtMostFiveRowsAreShown(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['1/2/2023', '2/3/2023', '3/4/2023', '4/5/2023', '5/6/2023', '6/7/2023', '7/8/2023']));

        self::assertSame([0, 1, 2, 3, 4], $resolution->exampleRows);
    }

    /**
     * @param list<string> $texts
     * @return list<ReadDate>
     */
    private static function column(array $texts): array
    {
        $reader = new ImportDateReader(new MockClock(TestClock::now()));

        return array_map(static fn (string $text): ReadDate => $reader->read(CellInput::text($text)), $texts);
    }
}
