<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\DateOrderResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DateOrderState;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DatePrecision;
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

    public function testTheYearFirstReadingOfTwoDigitDatesGoesWhenItPutsARowIntoTheFuture(): void
    {
        // "30/12/23" is 30 December 2023 day-first and 23 December 2030 year-first: nobody logged a puzzle of 2030
        $european = (new DateOrderResolver())->resolve(self::column(['30/12/23', '15/01/24', '28/02/24']));

        self::assertSame(DateOrderState::Resolved, $european->state);
        self::assertSame(DateOrder::DayMonthYear, $european->order());
    }

    public function testTheYearFirstReadingStaysWhenTheOtherOrdersPutRowsIntoTheFutureToo(): void
    {
        // Year-first: 2028 and 2027. Day-first: 2030 and 2029. Nothing tells them apart - all orders survive, one question
        $resolution = (new DateOrderResolver())->resolve(self::column(['28/11/30', '27/10/29']));

        self::assertSame(DateOrderState::Ambiguous, $resolution->state);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::YearMonthDay], $resolution->orders);
    }

    public function testADateInTheFutureRemovesNoOtherOrder(): void
    {
        // Day-first puts 5/12/2026 after today, month-first does not: both stay, the other is offered first
        $resolution = (new DateOrderResolver())->resolve(self::column(['5/12/2026', '3/4/2026']));

        self::assertSame(DateOrderState::Ambiguous, $resolution->state);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::MonthDayYear], $resolution->orders);
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

        // Month-first and year-first read a date without a year the same. "3/25" could be March 2025 - but "1/3" and
        // "4/5" cannot be a month of a year, so the column's short dates are days
        $monthFirst = $resolver->resolve(self::column(['1/3', '3/25', '4/5']));
        self::assertSame(DateOrderState::Resolved, $monthFirst->shortDates->state);
        self::assertSame(ShortDateMeaning::DayOfMonth, $monthFirst->shortDates->meaning);
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

    public function testAColumnWithoutShortDatesHasNoShortDateQuestion(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['3/4/2023', '2023-12-30', 'Sep-20', '20-Sep', 'March 2024']));

        self::assertSame(DateOrderState::NotNeeded, $resolution->shortDates->state);
        self::assertNull($resolution->shortDates->meaning);
        self::assertSame(0, $resolution->shortDates->rows);
        self::assertFalse($resolution->isSettled(), 'The order of 3/4/2023 is still open');
    }

    public function testMonthNamesWithTwoDigitsAreAskedOnceNeverTakenForDays(): void
    {
        // The review's column: the months of 2023, written "Jan 23". As days they would be three exact 23rds
        $dates = self::column(['Jan 23', 'Feb 23', 'Mar 23']);
        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame(DateOrderState::Ambiguous, $resolution->shortDates->state);
        self::assertNull($resolution->shortDates->meaning);
        self::assertSame(ShortDateMeaning::MonthOfYear, $resolution->shortDates->suggested, 'They march month by month');
        self::assertSame([0, 1, 2], $resolution->shortDates->exampleRows);
        self::assertSame(3, $resolution->shortDates->rows);
        self::assertFalse($resolution->isSettled());

        $untouched = $resolution->apply($dates[0]);
        self::assertSame(DateCertainty::DayOrYearOpen, $untouched->certainty);
        self::assertNull($untouched->date, 'No day and no month is taken while the question is open');
    }

    public function testNumbersOfMonthAndYearAreAskedOnceNeverTakenForDays(): void
    {
        // The review's other column: 10/23, 11/23, 12/23, 01/24 - October 2023 to January 2024
        $dates = self::column(['10/23', '11/23', '12/23', '01/24']);
        $resolver = new DateOrderResolver();
        $resolution = $resolver->resolve($dates);

        self::assertSame(DateOrderState::Ambiguous, $resolution->shortDates->state);
        self::assertSame(ShortDateMeaning::MonthOfYear, $resolution->shortDates->suggested);
        self::assertSame(DateOrderState::NotNeeded, $resolution->state, 'No order is asked before the short dates are settled');

        // The player says: months
        $months = $resolver->resolve($dates, ShortDateMeaning::MonthOfYear);
        self::assertSame(DateOrderState::Resolved, $months->shortDates->state);
        self::assertSame(ShortDateMeaning::MonthOfYear, $months->shortDates->meaning);
        self::assertTrue($months->isSettled());
        self::assertSame('2023-10-01', $months->apply($dates[0])->date?->format('Y-m-d'));
        self::assertSame(DatePrecision::Month, $months->apply($dates[0])->precision);
        self::assertSame('2024-01-01', $months->apply($dates[3])->date?->format('Y-m-d'));

        // … or: days - then the order follows from the values (23 is no month)
        $days = $resolver->resolve($dates, ShortDateMeaning::DayOfMonth);
        self::assertSame(ShortDateMeaning::DayOfMonth, $days->shortDates->meaning);
        self::assertSame(DateOrder::MonthDayYear, $days->order());
        self::assertSame('2000-10-23', $days->apply($dates[0])->date?->format('Y-m-d'));
        self::assertFalse($days->apply($dates[0])->yearKnown);
    }

    public function testASecondNumberThatCannotBeADaySettlesTheYear(): void
    {
        $dates = self::column(['Jan 23', 'Feb 30', 'Mar 23']);
        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame(DateOrderState::Resolved, $resolution->shortDates->state);
        self::assertSame(ShortDateMeaning::MonthOfYear, $resolution->shortDates->meaning);
        self::assertSame('2023-01-01', $resolution->apply($dates[0])->date?->format('Y-m-d'));

        $numbers = (new DateOrderResolver())->resolve(self::column(['01/23', '02/30', '03/23']));
        self::assertSame(ShortDateMeaning::MonthOfYear, $numbers->shortDates->meaning);
    }

    public function testANumberThatCannotBeAYearSettlesTheDay(): void
    {
        // One digit is no year; a first part above 12 is no month of a year
        $named = (new DateOrderResolver())->resolve(self::column(['Jan 5', 'Jan 12', 'Jan 23']));
        self::assertSame(ShortDateMeaning::DayOfMonth, $named->shortDates->meaning);

        $dates = self::column(['25/03', '10/11', '04/05']);
        $numbers = (new DateOrderResolver())->resolve($dates);

        self::assertSame(ShortDateMeaning::DayOfMonth, $numbers->shortDates->meaning);
        self::assertSame(DateOrder::DayMonthYear, $numbers->order(), '25 is no month');
        self::assertSame('2000-11-10', $numbers->apply($dates[1])->date?->format('Y-m-d'));
    }

    public function testShortDatesThatCanOnlyBeDaysBesideSomeThatCanOnlyBeYearsAreAProblem(): void
    {
        $resolution = (new DateOrderResolver())->resolve(self::column(['Jan 5', 'Jan 7', 'Feb 30']));

        self::assertSame(DateOrderState::Mixed, $resolution->shortDates->state);
        self::assertNull($resolution->shortDates->meaning);
        self::assertSame([2], $resolution->shortDates->exampleRows, 'The row that does not go the way most go');
        self::assertFalse($resolution->isSettled());
    }

    public function testShortDatesThatDoNotMarchGetNoSuggestionAndFutureMonthsSuggestDays(): void
    {
        $resolver = new DateOrderResolver();

        // 20, 21 and 22 September - or the Septembers of three years
        $noMarch = $resolver->resolve(self::column(['Sep 20', 'Sep 21', 'Sep 22']));
        self::assertSame(DateOrderState::Ambiguous, $noMarch->shortDates->state);
        self::assertNull($noMarch->shortDates->suggested);

        // Too few to see a march
        self::assertNull($resolver->resolve(self::column(['Jan 23', 'Feb 23']))->shortDates->suggested);

        // January 2027 has not come (today is 14 October 2026): rather the 27th
        $future = $resolver->resolve(self::column(['Jan 27', 'Jan 28', 'Feb 27']));
        self::assertSame(ShortDateMeaning::DayOfMonth, $future->shortDates->suggested);

        // Backwards, newest first
        self::assertSame(ShortDateMeaning::MonthOfYear, $resolver->resolve(self::column(['Mar 23', 'Feb 23', 'Feb 23', 'Jan 23']))->shortDates->suggested);
    }

    public function testTheClassicDamageOfASpreadsheetIsAQuestionNeverTwoSilentOrders(): void
    {
        // A day-first file opened by a month-first spreadsheet: what it could read became typed dates - turned around
        // (3 April is stored as 4 March) - and what it could not (a "month" above 12) stayed text
        $reader = new ImportDateReader(new MockClock(TestClock::now()));
        $dates = [
            $reader->read(CellInput::date(self::day('2023-03-04'))),
            $reader->read(CellInput::text('13/04/2023')),
            $reader->read(CellInput::date(self::day('2023-05-06'))),
            $reader->read(CellInput::text('25/04/2023')),
            $reader->read(CellInput::date(self::day('2023-12-01'))),
            $reader->read(CellInput::text('31/05/2023')),
        ];

        $resolution = (new DateOrderResolver())->resolve($dates);

        self::assertSame(DateOrderState::TypedDatesInDoubt, $resolution->state);
        self::assertNull($resolution->order(), 'Not resolved: the typed dates may be in the other order');
        self::assertSame([DateOrder::DayMonthYear], $resolution->orders, 'The order of the text dates');
        self::assertSame([0, 2, 4], $resolution->exampleRows, 'The typed rows to show');
        self::assertFalse($resolution->isSettled());

        // Nothing is changed silently; once the player says "yes, turned around", each typed date is turned back
        self::assertSame($dates[0], $resolution->apply($dates[0]));
        self::assertSame('2023-04-03', $reader->withDayAndMonthSwapped($dates[0])->date?->format('Y-m-d'));
        self::assertSame('2023-04-13', $resolution->apply($dates[1])->date?->format('Y-m-d'));
    }

    public function testTheSameDamageTheOtherWayRound(): void
    {
        $reader = new ImportDateReader(new MockClock(TestClock::now()));

        $resolution = (new DateOrderResolver())->resolve([
            $reader->read(CellInput::date(self::day('2023-04-03'))),
            $reader->read(CellInput::text('04/13/2023')),
            $reader->read(CellInput::text('12/31/2023')),
        ]);

        self::assertSame(DateOrderState::TypedDatesInDoubt, $resolution->state);
        self::assertSame([DateOrder::MonthDayYear], $resolution->orders);
    }

    public function testTypedDatesAreNotInDoubtWithoutThePattern(): void
    {
        $reader = new ImportDateReader(new MockClock(TestClock::now()));
        $resolver = new DateOrderResolver();

        // A typed date with a day above 12: the spreadsheet read days where days are
        self::assertSame(DateOrderState::Resolved, $resolver->resolve([
            $reader->read(CellInput::date(self::day('2023-03-04'))),
            $reader->read(CellInput::date(self::day('2023-03-14'))),
            $reader->read(CellInput::text('13/04/2023')),
        ])->state);

        // Text dates the spreadsheet could have read either way: not what it leaves behind
        self::assertSame(DateOrderState::Resolved, $resolver->resolve([
            $reader->read(CellInput::date(self::day('2023-03-04'))),
            $reader->read(CellInput::text('13/04/2023')),
            $reader->read(CellInput::text('05/04/2023')),
        ])->state);

        // Typed dates only, and typed months, are nobody's question
        self::assertSame(DateOrderState::NotNeeded, $resolver->resolve([
            $reader->read(CellInput::date(self::day('2023-03-04'))),
            $reader->read(CellInput::date(self::day('2023-05-06'))),
        ])->state);
        self::assertSame(DateOrderState::Resolved, $resolver->resolve([
            $reader->read(CellInput::date(self::day('2023-03-04'), DatePrecision::Month)),
            $reader->read(CellInput::text('13/04/2023')),
        ])->state);

        // Year-first text is not what a turned-around column looks like
        self::assertSame(DateOrderState::NotNeeded, $resolver->resolve([
            $reader->read(CellInput::date(self::day('2023-03-04'))),
            $reader->read(CellInput::text('2023-04-13')),
        ])->state);
    }

    private static function day(string $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        self::assertNotFalse($date);

        return $date;
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
