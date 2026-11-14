<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;
use SpeedPuzzling\Web\Value\DataImport\DateProblem;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use Symfony\Component\Clock\MockClock;

/**
 * Every shape of a date the real spreadsheets hold (docs/features/data-import/README.md "Dates",
 * docs/features/data-import/value-readers.md). Today is TestClock's 14 October 2026.
 */
final class ImportDateReaderTest extends TestCase
{
    #[DataProvider('certainTexts')]
    public function testATextWithOneReading(string $text, string $day, DatePrecision $precision = DatePrecision::Day): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(DateCertainty::Certain, $read->certainty, $text);
        self::assertSame($day, $read->date?->format('Y-m-d H:i:s e'), $text);
        self::assertSame($precision, $read->precision, $text);
        self::assertTrue($read->yearKnown, $text);
        self::assertSame([], $read->problems, $text);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: DatePrecision}>
     */
    public static function certainTexts(): iterable
    {
        $day = '2023-12-30 00:00:00 UTC';
        $march = '2024-03-03 00:00:00 UTC';

        // The task's examples
        yield 'day first, the day settles it' => ['30/12/2023', $day];
        yield 'month first, the day settles it' => ['12/30/2023', $day];
        yield 'ISO' => ['2023-12-30', $day];
        yield 'dots' => ['30.12.2023', $day];
        yield 'dots and spaces' => ['30. 12. 2023', $day];
        yield 'two-digit year, month first' => ['12/30/23', $day];
        yield 'a short month and a year' => ['Sept 2020', '2020-09-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a month and a year' => ['September 2020', '2020-09-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a month and a two-digit year, as a spreadsheet shows a month' => ['Sep-20', '2020-09-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a year' => ['2020', '2020-01-01 00:00:00 UTC', DatePrecision::Year];
        yield 'en: month, day, year' => ['March 3, 2024', $march];
        yield 'en: day, month, year' => ['3 March 2024', $march];
        yield 'cs: the form after a day' => ['3. března 2024', $march];
        yield 'de' => ['3. März 2024', $march];
        yield 'es' => ['3 de marzo de 2024', $march];
        yield 'fr' => ['3 mars 2024', $march];
        yield 'ja' => ['2024年3月3日', $march];

        // Numbers
        yield 'ISO with slashes' => ['2023/12/30', $day];
        yield 'ISO with dots' => ['2023.12.30', $day];
        yield 'ISO without leading zeros' => ['2024-3-3', $march];
        yield 'ISO with a time' => ['2023-12-30 10:00', $day];
        yield 'ISO with a time and a zone' => ['2023-12-30T10:00:00Z', $day];
        yield 'ISO with a time, seconds and an offset' => ['2023-12-30T23:59:59+02:00', $day];
        yield 'a date and a time of the afternoon' => ['30/12/2023 2:05 pm', $day];
        yield 'a date and a 24-hour time' => ['30.12.2023 14:05', $day];
        yield 'dashes' => ['30-12-2023', $day];
        yield 'a trailing dot' => ['30.12.2023.', $day];
        yield 'the same day and month in any order' => ['3/3/2024', $march];
        yield 'eight digits' => ['20231230', $day];
        yield 'full-width digits' => ['２０２３－１２－３０', $day];
        yield 'spaces around' => ['  2023-12-30  ', $day];
        yield 'a non-breaking space and a direction mark' => ["\u{200E}2023-12-30\u{00A0}", $day];
        yield 'a year and a month' => ['2023-12', '2023-12-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a year and a month with a slash' => ['2023/12', '2023-12-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a month and a year in numbers' => ['12/2023', '2023-12-01 00:00:00 UTC', DatePrecision::Month];
        yield 'a month and a year with a dot' => ['3.2024', '2024-03-01 00:00:00 UTC', DatePrecision::Month];
        yield 'the earliest year alone' => ['1950', '1950-01-01 00:00:00 UTC', DatePrecision::Year];
        yield 'this year' => ['2026', '2026-01-01 00:00:00 UTC', DatePrecision::Year];
        yield 'a leap day' => ['29.2.2024', '2024-02-29 00:00:00 UTC'];
        yield 'today' => ['2026-10-14', '2026-10-14 00:00:00 UTC'];
        yield 'tomorrow - somewhere it already is' => ['2026-10-15', '2026-10-15 00:00:00 UTC'];
        yield 'this month' => ['October 2026', '2026-10-01 00:00:00 UTC', DatePrecision::Month];

        // English
        yield 'en: short month' => ['3 Mar 2024', $march];
        yield 'en: short month with a dot' => ['Mar. 3, 2024', $march];
        yield 'en: ordinal' => ['3rd March 2024', $march];
        yield 'en: ordinal after the month' => ['March 3rd, 2024', $march];
        yield 'en: the 3rd of March' => ['the 3rd of March 2024', $march];
        yield 'en: with a weekday' => ['Sunday, 3 March 2024', $march];
        yield 'en: with a short weekday' => ['Sun 3 Mar 2024', $march];
        yield 'en: dashes' => ['03-Mar-2024', $march];
        yield 'en: dashes and a two-digit year' => ['3-Mar-24', $march];
        yield 'en: month, day and a two-digit year' => ['Mar 3 24', $march];
        yield 'en: upper case' => ['3 MARCH 2024', $march];
        yield 'en: year first' => ['2024 March 3', $march];
        yield 'en: month and year with a comma' => ['March, 2024', '2024-03-01 00:00:00 UTC', DatePrecision::Month];
        yield 'en: an apostrophe year' => ['Sep \'20', '2020-09-01 00:00:00 UTC', DatePrecision::Month];
        yield 'en: year and month' => ['2020 Sept', '2020-09-01 00:00:00 UTC', DatePrecision::Month];
        yield 'en: January' => ['1 January 2024', '2024-01-01 00:00:00 UTC'];
        yield 'en: May' => ['May 5, 2024', '2024-05-05 00:00:00 UTC'];
        yield 'en: December' => ['31 Dec 2023', '2023-12-31 00:00:00 UTC'];

        // Czech
        yield 'cs: ledna' => ['1. ledna 2024', '2024-01-01 00:00:00 UTC'];
        yield 'cs: února' => ['14. února 2024', '2024-02-14 00:00:00 UTC'];
        yield 'cs: dubna' => ['2. dubna 2024', '2024-04-02 00:00:00 UTC'];
        yield 'cs: května' => ['8. května 2024', '2024-05-08 00:00:00 UTC'];
        yield 'cs: června' => ['21. června 2024', '2024-06-21 00:00:00 UTC'];
        yield 'cs: července' => ['5. července 2024', '2024-07-05 00:00:00 UTC'];
        yield 'cs: srpna' => ['15. srpna 2024', '2024-08-15 00:00:00 UTC'];
        yield 'cs: září' => ['28. září 2024', '2024-09-28 00:00:00 UTC'];
        yield 'cs: října' => ['28. října 2024', '2024-10-28 00:00:00 UTC'];
        yield 'cs: listopadu' => ['17. listopadu 2024', '2024-11-17 00:00:00 UTC'];
        yield 'cs: prosince' => ['24. prosince 2024', '2024-12-24 00:00:00 UTC'];
        yield 'cs: the month\'s own name and a year' => ['červenec 2024', '2024-07-01 00:00:00 UTC', DatePrecision::Month];
        yield 'cs: June is not July' => ['červen 2024', '2024-06-01 00:00:00 UTC', DatePrecision::Month];
        yield 'cs: with a weekday' => ['neděle 3. března 2024', $march];
        yield 'cs: dne' => ['dne 3. března 2024', $march];
        yield 'cs: without accents' => ['3. brezna 2024', $march];

        // German
        yield 'de: Januar' => ['1. Januar 2024', '2024-01-01 00:00:00 UTC'];
        yield 'de: Jänner' => ['1. Jänner 2024', '2024-01-01 00:00:00 UTC'];
        yield 'de: Mai' => ['5. Mai 2024', '2024-05-05 00:00:00 UTC'];
        yield 'de: Juni' => ['5. Juni 2024', '2024-06-05 00:00:00 UTC'];
        yield 'de: Oktober' => ['3. Oktober 2024', '2024-10-03 00:00:00 UTC'];
        yield 'de: Dezember' => ['24. Dezember 2024', '2024-12-24 00:00:00 UTC'];
        yield 'de: short' => ['3. Dez. 2024', '2024-12-03 00:00:00 UTC'];
        yield 'de: Mrz' => ['3. Mrz 2024', $march];
        yield 'de: with a weekday' => ['Sonntag, 3. März 2024', $march];
        yield 'de: month and year' => ['März 2024', '2024-03-01 00:00:00 UTC', DatePrecision::Month];

        // Spanish
        yield 'es: enero' => ['1 de enero de 2024', '2024-01-01 00:00:00 UTC'];
        yield 'es: agosto' => ['15 de agosto de 2024', '2024-08-15 00:00:00 UTC'];
        yield 'es: septiembre' => ['9 de septiembre de 2024', '2024-09-09 00:00:00 UTC'];
        yield 'es: setiembre' => ['9 de setiembre de 2024', '2024-09-09 00:00:00 UTC'];
        yield 'es: diciembre' => ['24 de diciembre de 2024', '2024-12-24 00:00:00 UTC'];
        yield 'es: short' => ['3 mar 2024', $march];
        yield 'es: month and year' => ['marzo de 2024', '2024-03-01 00:00:00 UTC', DatePrecision::Month];
        yield 'es: with a weekday' => ['domingo, 3 de marzo de 2024', $march];

        // French
        yield 'fr: janvier' => ['1er janvier 2024', '2024-01-01 00:00:00 UTC'];
        yield 'fr: février' => ['14 février 2024', '2024-02-14 00:00:00 UTC'];
        yield 'fr: août' => ['15 août 2024', '2024-08-15 00:00:00 UTC'];
        yield 'fr: décembre' => ['24 décembre 2024', '2024-12-24 00:00:00 UTC'];
        yield 'fr: short' => ['3 févr. 2024', '2024-02-03 00:00:00 UTC'];
        yield 'fr: juillet' => ['14 juillet 2024', '2024-07-14 00:00:00 UTC'];
        yield 'fr: le' => ['le 3 mars 2024', $march];
        yield 'fr: with a weekday' => ['dimanche 3 mars 2024', $march];
        yield 'fr: month and year' => ['mars 2024', '2024-03-01 00:00:00 UTC', DatePrecision::Month];

        // Japanese
        yield 'ja: with a weekday' => ['2024年3月3日(日)', $march];
        yield 'ja: with a full-width weekday' => ['2024年3月3日（日）', $march];
        yield 'ja: with a weekday word' => ['2024年3月3日 日曜日', $march];
        yield 'ja: full-width digits' => ['２０２４年３月３日', $march];
        yield 'ja: leading zeros' => ['2024年03月03日', $march];
        yield 'ja: a month' => ['2024年3月', '2024-03-01 00:00:00 UTC', DatePrecision::Month];
        yield 'ja: a year' => ['2024年', '2024-01-01 00:00:00 UTC', DatePrecision::Year];
        yield 'ja: slashes, year first' => ['2024/3/3', $march];
    }

    /**
     * @param array<string, string> $days The day by order (DateOrder value)
     */
    #[DataProvider('orderOpenTexts')]
    public function testNumbersThatAreADateInSeveralOrders(string $text, array $days, bool $yearKnown = true): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(DateCertainty::OrderOpen, $read->certainty, $text);
        self::assertNull($read->date, 'No date is taken until the order is settled');
        self::assertSame($yearKnown, $read->yearKnown);
        self::assertSame(array_keys($days), array_map(static fn (DateOrder $order): string => $order->value, $read->possibleOrders));

        foreach ($days as $order => $day) {
            self::assertSame($day, $read->dayIn(DateOrder::from($order)), $text . ' ' . $order);

            $settled = $read->inOrder(DateOrder::from($order));
            self::assertSame(DateCertainty::Certain, $settled->certainty);
            self::assertSame($day, $settled->date?->format('Y-m-d'));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>, 2?: bool}>
     */
    public static function orderOpenTexts(): iterable
    {
        yield 'the task\'s 3/4/2023' => ['3/4/2023', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'dots' => ['3.4.2023', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'dots and spaces' => ['3. 4. 2023', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'dashes' => ['03-04-2023', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'twelve and twelve are not open, eleven and twelve are' => ['11/12/2023', ['dmy' => '2023-12-11', 'mdy' => '2023-11-12']];
        yield 'full-width' => ['３／４／２０２３', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'the task\'s 30-12-23: the year may come first' => ['30-12-23', ['dmy' => '2023-12-30', 'ymd' => '2030-12-23']];
        yield 'two digits everywhere: three orders' => ['03/04/05', ['dmy' => '2005-04-03', 'mdy' => '2005-03-04', 'ymd' => '2003-04-05']];
        yield 'a year of the last century' => ['05/06/98', ['dmy' => '1998-06-05', 'mdy' => '1998-05-06']];
        yield 'year first as in Japan' => ['23/12/30', ['dmy' => '2030-12-23', 'ymd' => '2023-12-30']];
        yield 'a one-digit first part is no year' => ['3/4/23', ['dmy' => '2023-04-03', 'mdy' => '2023-03-04']];
        yield 'the task\'s 1/3: no year' => ['1/3', ['dmy' => '2000-03-01', 'mdy' => '2000-01-03', 'ymd' => '2000-01-03'], false];
        yield 'no year, dots' => ['1.3.', ['dmy' => '2000-03-01', 'mdy' => '2000-01-03', 'ymd' => '2000-01-03'], false];
        yield 'no year, dash' => ['12-5', ['dmy' => '2000-05-12', 'mdy' => '2000-12-05', 'ymd' => '2000-12-05'], false];
    }

    public function testADateThatOnlyOneOrderMakesIsCertainAndSaysWhichOrder(): void
    {
        $dayFirst = self::reader()->read(CellInput::text('30/12/2023'));
        self::assertSame([DateOrder::DayMonthYear], $dayFirst->possibleOrders);
        self::assertTrue($dayFirst->allows(DateOrder::DayMonthYear));
        self::assertFalse($dayFirst->allows(DateOrder::MonthDayYear));
        self::assertSame(DateCertainty::Unreadable, $dayFirst->inOrder(DateOrder::MonthDayYear)->certainty, 'No date month-first');

        $monthFirst = self::reader()->read(CellInput::text('12/30/2023'));
        self::assertSame([DateOrder::MonthDayYear], $monthFirst->possibleOrders);

        $same = self::reader()->read(CellInput::text('3/3/2024'));
        self::assertSame([DateOrder::DayMonthYear, DateOrder::MonthDayYear], $same->possibleOrders, 'The same day either way, but the column still counts it');

        $iso = self::reader()->read(CellInput::text('2023-12-30'));
        self::assertSame([], $iso->possibleOrders, 'A four-digit year in front is no question of order');
        self::assertSame($iso, $iso->inOrder(DateOrder::MonthDayYear));

        $named = self::reader()->read(CellInput::text('3 March 2024'));
        self::assertSame([], $named->possibleOrders);
    }

    #[DataProvider('textsWithoutAYear')]
    public function testATextWithoutAYearSaysSo(string $text, string $monthAndDay, DatePrecision $precision = DatePrecision::Day): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(DateCertainty::Certain, $read->certainty, $text);
        self::assertFalse($read->yearKnown, $text);
        self::assertSame(ReadDate::PLACEHOLDER_YEAR . '-' . $monthAndDay, $read->date?->format('Y-m-d'), $text);
        self::assertSame($precision, $read->precision);
        self::assertSame([], $read->problems, 'A date without a year is neither in the future nor too old');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: DatePrecision}>
     */
    public static function textsWithoutAYear(): iterable
    {
        yield 'a day and a month\'s name' => ['3 March', '03-03'];
        yield 'a month\'s name and a day' => ['March 3', '03-03'];
        yield 'a short month and a day' => ['Sep 20', '09-20'];
        yield 'a day and a short month, as a spreadsheet shows a day' => ['20-Sep', '09-20'];
        yield 'cs' => ['3. března', '03-03'];
        yield 'de' => ['3. März', '03-03'];
        yield 'es' => ['3 de marzo', '03-03'];
        yield 'fr' => ['3 mars', '03-03'];
        yield 'ja' => ['3月3日', '03-03'];
        yield 'a month alone' => ['March', '03-01', DatePrecision::Month];
        yield 'ja: a month alone' => ['3月', '03-01', DatePrecision::Month];
        yield 'numbers that only one order makes' => ['29/2', '02-29'];
        yield 'numbers that month-first and year-first read the same' => ['3/13', '03-13'];
        yield 'a leap day by name' => ['29 February', '02-29'];
    }

    public function testAYearIsPutInLater(): void
    {
        $reader = self::reader();

        $named = $reader->withYear($reader->read(CellInput::text('3 March')), 2019);
        self::assertSame(DateCertainty::Certain, $named->certainty);
        self::assertSame('2019-03-03', $named->date?->format('Y-m-d'));
        self::assertTrue($named->yearKnown);
        self::assertSame([], $named->problems);

        $month = $reader->withYear($reader->read(CellInput::text('March')), 2019);
        self::assertSame('2019-03-01', $month->date?->format('Y-m-d'));
        self::assertSame(DatePrecision::Month, $month->precision);

        $open = $reader->withYear($reader->read(CellInput::text('1/3')), 2019);
        self::assertSame(DateCertainty::OrderOpen, $open->certainty);
        self::assertTrue($open->yearKnown);
        self::assertSame('2019-03-01', $open->dayIn(DateOrder::DayMonthYear));
        self::assertSame('2019-01-03', $open->dayIn(DateOrder::MonthDayYear));

        $leap = $reader->read(CellInput::text('29 February'));
        self::assertSame('2024-02-29', $reader->withYear($leap, 2024)->date?->format('Y-m-d'));
        self::assertSame(DateCertainty::Unreadable, $reader->withYear($leap, 2023)->certainty, '2023 has no 29 February');
        self::assertSame(DateCertainty::Unreadable, $reader->withYear($reader->read(CellInput::text('29/2')), 2023)->certainty);

        $future = $reader->withYear($reader->read(CellInput::text('3 March')), 2031);
        self::assertSame([DateProblem::InTheFuture], $future->problems);

        $known = $reader->read(CellInput::text('3 March 2024'));
        self::assertSame($known, $reader->withYear($known, 2019), 'A date that knows its year keeps it');

        $cell = $reader->read(CellInput::date(self::day('2020-01-17'), DatePrecision::Day, false));
        self::assertSame('2019-01-17', $reader->withYear($cell, 2019)->date?->format('Y-m-d'), 'A sheet named 2019 that holds 2020 dates shown as m/d');

        $nothing = $reader->read(CellInput::blank());
        self::assertSame($nothing, $reader->withYear($nothing, 2019));
    }

    #[DataProvider('twoDigitYears')]
    public function testATwoDigitYearUpToSixtyNineIsThisCentury(string $text, string $day): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame($day, $read->date?->format('Y-m-d'), $text);
        self::assertTrue($read->centuryAssumed);
        self::assertTrue($read->yearKnown);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function twoDigitYears(): iterable
    {
        yield '00' => ['12/31/00', '2000-12-31'];
        yield '24' => ['12/31/24', '2024-12-31'];
        yield '69 - in the future, and flagged' => ['12/31/69', '2069-12-31'];
        yield '70' => ['12/31/70', '1970-12-31'];
        yield '99' => ['12/31/99', '1999-12-31'];
        yield 'with a month\'s name' => ['3-Mar-98', '1998-03-03'];
        yield 'a month and a year' => ['Sep-95', '1995-09-01'];
    }

    public function testAFourDigitYearAssumesNoCentury(): void
    {
        self::assertFalse(self::reader()->read(CellInput::text('30/12/2023'))->centuryAssumed);
        self::assertFalse(self::reader()->read(CellInput::text('1/3'))->centuryAssumed);
    }

    /**
     * @param list<DateProblem> $problems
     */
    #[DataProvider('datesWithProblems')]
    public function testADateInTheFutureOrBefore1950IsReadAndFlagged(string $text, string $day, array $problems): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(DateCertainty::Certain, $read->certainty, $text);
        self::assertSame($day, $read->date?->format('Y-m-d'), 'A problem never changes the date');
        self::assertSame($problems, $read->problems, $text);
    }

    /**
     * @return iterable<string, array{string, string, list<DateProblem>}>
     */
    public static function datesWithProblems(): iterable
    {
        yield 'the README\'s 2028 among 2024' => ['2028-01-05', '2028-01-05', [DateProblem::InTheFuture]];
        yield 'the day after tomorrow' => ['2026-10-16', '2026-10-16', [DateProblem::InTheFuture]];
        yield 'next month' => ['November 2026', '2026-11-01', [DateProblem::InTheFuture]];
        yield 'next year' => ['2027', '2027-01-01', [DateProblem::InTheFuture]];
        yield 'a two-digit year read into the future' => ['31/12/69', '2069-12-31', [DateProblem::InTheFuture]];
        yield 'before 1950' => ['1949-12-31', '1949-12-31', [DateProblem::Before1950]];
        yield 'a typo of the century' => ['30/12/1023', '1023-12-30', [DateProblem::Before1950]];
        yield 'a year before 1950' => ['1920', '1920-01-01', [DateProblem::Before1950]];
        yield 'a month before 1950' => ['March 1949', '1949-03-01', [DateProblem::Before1950]];
    }

    public function testTheProblemsOfEachOrderAreKept(): void
    {
        // Day-first it is 5 December 2026 - after today; month-first 12 May 2026
        $read = self::reader()->read(CellInput::text('5/12/2026'));

        self::assertSame(DateCertainty::OrderOpen, $read->certainty);
        self::assertSame([], $read->problems);
        self::assertSame([DateProblem::InTheFuture], $read->problemsIn(DateOrder::DayMonthYear));
        self::assertSame([], $read->problemsIn(DateOrder::MonthDayYear));
        self::assertSame([DateProblem::InTheFuture], $read->inOrder(DateOrder::DayMonthYear)->problems);
    }

    #[DataProvider('unreadableTexts')]
    public function testWhatIsNoDateIsUnreadable(string $text): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(DateCertainty::Unreadable, $read->certainty, $text);
        self::assertNull($read->date);
        self::assertSame([], $read->possibleOrders);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTexts(): iterable
    {
        yield 'a word' => ['garbage'];
        yield 'a question mark' => ['?'];
        yield 'a dash' => ['-'];
        yield 'yes' => ['Y'];
        yield 'n/a' => ['n/a'];
        yield '31 February' => ['31/02/2023'];
        yield '31 April by name' => ['31 April 2023'];
        yield '29 February of a year without one' => ['29.2.2023'];
        yield 'a month of 13 either way' => ['13/13/2023'];
        yield 'a day of zero' => ['0/5/2023'];
        yield 'a month of zero' => ['2023-00-10'];
        yield 'a three-digit year' => ['30/12/202'];
        yield 'a one-digit year' => ['3/4/5'];
        yield 'two separators' => ['30/12-2023'];
        yield 'a number that is no year' => ['5000'];
        yield 'a piece count' => ['1000'];
        yield 'a spreadsheet\'s day number' => ['45000'];
        yield 'eight digits that are no day' => ['20231345'];
        yield 'eight digits of a far year' => ['30122023'];
        yield 'a decimal number with too many digits' => ['3.14159'];
        yield 'a time' => ['10:30'];
        yield 'a time of three parts' => ['1:09:17'];
        yield 'a range of days' => ['1-3/4/2023'];
        yield 'a range of months' => ['Jan-Feb 2023'];
        yield 'two dates' => ['3 March 2024, 5 March 2024'];
        yield 'a date and a word' => ['3 March 2024 (finished)'];
        yield 'a word and a date' => ['finished 3 March 2024'];
        yield 'an unknown month' => ['3 Smarch 2024'];
        yield 'a day too many of a named month' => ['45 March 2024'];
        yield 'a Japanese era year' => ['令和6年3月3日'];
        yield 'a Japanese two-digit year' => ['24年3月3日'];
        yield 'a Japanese day alone' => ['3日'];
        yield 'scientific notation' => ['1e5'];
        yield 'a negative number' => ['-2020'];
        yield 'an emoji' => ['📅'];
        yield 'a very long text' => [str_repeat('3 March 2024 ', 50)];
        yield 'a megabyte of digits' => [str_repeat('2', 1_000_000)];
    }

    #[DataProvider('noDate')]
    public function testAnEmptyCellIsNoDate(CellInput $cell): void
    {
        $read = self::reader()->read($cell);

        self::assertSame(DateCertainty::NoDate, $read->certainty);
        self::assertNull($read->date);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function noDate(): iterable
    {
        yield 'no cell' => [CellInput::blank()];
        yield 'an empty text' => [CellInput::text('')];
        yield 'spaces' => [CellInput::text('  ')];
        yield 'a non-breaking space' => [CellInput::text("\u{00A0}")];
        yield 'a direction mark' => [CellInput::text("\u{200F}")];
    }

    public function testATypedDatePassesThroughWithItsPrecision(): void
    {
        $reader = self::reader();

        $day = $reader->read(CellInput::date(new DateTimeImmutable('2025-01-03 18:30:00', new DateTimeZone('Europe/Prague'))));
        self::assertSame(DateCertainty::Certain, $day->certainty);
        self::assertSame('2025-01-03 00:00:00 UTC', $day->date?->format('Y-m-d H:i:s e'), 'The calendar day, at midnight UTC');
        self::assertSame(DatePrecision::Day, $day->precision);
        self::assertTrue($day->yearKnown);
        self::assertSame([], $day->possibleOrders);
        self::assertSame([], $day->problems);

        // A cell whose format shows "September 2020" holds some day of that month
        $month = $reader->read(CellInput::date(self::day('2020-09-14'), DatePrecision::Month));
        self::assertSame('2020-09-01', $month->date?->format('Y-m-d'), 'A month is its first day');
        self::assertSame(DatePrecision::Month, $month->precision);

        $year = $reader->read(CellInput::date(self::day('2020-09-14'), DatePrecision::Year));
        self::assertSame('2020-01-01', $year->date?->format('Y-m-d'));
        self::assertSame(DatePrecision::Year, $year->precision);
    }

    public function testATypedDateWhoseFormatHidesTheYear(): void
    {
        // The README: real dates shown as m/d - a sheet named 2019 holds 2020 dates
        $read = self::reader()->read(CellInput::date(self::day('2030-01-17'), DatePrecision::Day, false));

        self::assertSame(DateCertainty::Certain, $read->certainty);
        self::assertSame('2030-01-17', $read->date?->format('Y-m-d'), 'The cell\'s own year is kept, not trusted');
        self::assertFalse($read->yearKnown);
        self::assertSame([], $read->problems, 'A year nobody saw is not judged');
    }

    public function testATypedDateIsFlaggedLikeAText(): void
    {
        $reader = self::reader();

        self::assertSame([DateProblem::InTheFuture], $reader->read(CellInput::date(self::day('2028-06-01')))->problems);
        self::assertSame([DateProblem::Before1950], $reader->read(CellInput::date(self::day('1900-01-01')))->problems);
        self::assertSame([], $reader->read(CellInput::date(self::day('2026-10-15')))->problems);
        self::assertSame([DateProblem::InTheFuture], $reader->read(CellInput::date(self::day('2026-11-20'), DatePrecision::Month))->problems);
        self::assertSame([], $reader->read(CellInput::date(self::day('2026-10-31'), DatePrecision::Month))->problems, 'This month has begun');
        self::assertSame([], $reader->read(CellInput::date(self::day('2026-12-31'), DatePrecision::Year))->problems, 'This year has begun');
    }

    #[DataProvider('numbers')]
    public function testANumberInADateColumn(int|float $number, null|string $day, DatePrecision $precision = DatePrecision::Day): void
    {
        $read = self::reader()->read(CellInput::number($number));

        if ($day === null) {
            self::assertSame(DateCertainty::Unreadable, $read->certainty);

            return;
        }

        self::assertSame(DateCertainty::Certain, $read->certainty);
        self::assertSame($day, $read->date?->format('Y-m-d'));
        self::assertSame($precision, $read->precision);
    }

    /**
     * @return iterable<string, array{0: int|float, 1: null|string, 2?: DatePrecision}>
     */
    public static function numbers(): iterable
    {
        yield 'a year' => [2020, '2020-01-01', DatePrecision::Year];
        yield 'a year as a float' => [2020.0, '2020-01-01', DatePrecision::Year];
        yield 'eight digits' => [20231230, '2023-12-30'];
        yield 'a piece count' => [1000, null];
        yield 'a spreadsheet\'s day number - never guessed' => [45000, null];
        yield 'a decimal' => [2020.5, null];
        yield 'zero' => [0, null];
        yield 'a negative year' => [-2020, null];
        yield 'not a number' => [NAN, null];
        yield 'infinity' => [INF, null];
        yield 'a huge number' => [1e20, null];
    }

    #[DataProvider('otherCells')]
    public function testOtherTypedCellsAreNoDate(CellInput $cell): void
    {
        self::assertSame(DateCertainty::Unreadable, self::reader()->read($cell)->certainty);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function otherCells(): iterable
    {
        yield 'a duration' => [CellInput::duration(4157)];
        yield 'a time of day' => [CellInput::timeOfDay(4157)];
        yield 'yes' => [CellInput::boolean(true)];
    }

    public function testTodayIsTheClocksNotTheSystems(): void
    {
        $in2020 = new ImportDateReader(new MockClock(self::day('2020-06-01')));

        self::assertSame([DateProblem::InTheFuture], $in2020->read(CellInput::text('2023-12-30'))->problems);
        self::assertSame([], self::reader()->read(CellInput::text('2023-12-30'))->problems);
    }

    public function testTheSameTextIsTheSameDateInAnyYear(): void
    {
        // The two-digit window is fixed: a key built from the date must not change when the file is read again later
        $now = self::reader()->read(CellInput::text('Sep-27'));
        $later = (new ImportDateReader(new MockClock(self::day('2040-01-01'))))->read(CellInput::text('Sep-27'));

        self::assertSame('2027-09-01', $now->date?->format('Y-m-d'));
        self::assertSame('2027-09-01', $later->date?->format('Y-m-d'));
    }

    private static function reader(): ImportDateReader
    {
        return new ImportDateReader(new MockClock(TestClock::now()));
    }

    private static function day(string $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        self::assertNotFalse($date);

        return $date;
    }
}
