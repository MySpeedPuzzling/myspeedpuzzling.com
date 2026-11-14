<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\FlagWithDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;
use SpeedPuzzling\Web\Value\DataImport\YesNoCertainty;
use Symfony\Component\Clock\MockClock;

/**
 * One cell holding two facts: `Y - 30/12/2023` (docs/features/data-import/README.md, file 2's "Completed?" column).
 */
final class FlagWithDateReaderTest extends TestCase
{
    #[DataProvider('flagsWithDates')]
    public function testAFlagAndADate(string $text, bool $flag, string $day, DatePrecision $precision = DatePrecision::Day): void
    {
        $read = self::reader()->read(CellInput::text($text));

        self::assertSame(YesNoCertainty::Known, $read->flag->certainty, $text);
        self::assertSame($flag, $read->flag->value, $text);
        self::assertSame(DateCertainty::Certain, $read->date->certainty, $text);
        self::assertSame($day, $read->date->date?->format('Y-m-d'), $text);
        self::assertSame($precision, $read->date->precision, $text);
        self::assertNull($read->leftover);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string, 3?: DatePrecision}>
     */
    public static function flagsWithDates(): iterable
    {
        // The task's examples
        yield 'Y - dd/mm/yyyy' => ['Y - 30/12/2023', true, '2023-12-30'];
        yield 'Yes (Mon yyyy)' => ['Yes (Jan 2023)', true, '2023-01-01', DatePrecision::Month];
        yield 'y yyyy' => ['y 2022', true, '2022-01-01', DatePrecision::Year];

        yield 'no space around the dash' => ['Y-30/12/2023', true, '2023-12-30'];
        yield 'an en dash' => ['Y – 30/12/2023', true, '2023-12-30'];
        yield 'an em dash' => ['Y — 30/12/2023', true, '2023-12-30'];
        yield 'a colon' => ['yes: 2023-12-30', true, '2023-12-30'];
        yield 'a comma and a written date' => ['Yes, 30 December 2023', true, '2023-12-30'];
        yield 'a comma inside the date' => ['Yes, December 30, 2023', true, '2023-12-30'];
        yield 'square brackets' => ['x [12/2023]', true, '2023-12-01', DatePrecision::Month];
        yield 'a tick' => ['✓ 30.12.2023', true, '2023-12-30'];
        yield 'lower case' => ['y - 30/12/2023', true, '2023-12-30'];
        yield 'cs' => ['ano - 30. 12. 2023', true, '2023-12-30'];
        yield 'cs with a written month' => ['Ano (30. prosince 2023)', true, '2023-12-30'];
        yield 'de' => ['Ja, 30. Dezember 2023', true, '2023-12-30'];
        yield 'es' => ['Sí - 30 de diciembre de 2023', true, '2023-12-30'];
        yield 'fr' => ['Oui (30 décembre 2023)', true, '2023-12-30'];
        yield 'ja' => ['はい 2023年12月30日', true, '2023-12-30'];
        yield 'a no with a date' => ['N - 30/12/2023', false, '2023-12-30'];
        yield 'the flag last' => ['30/12/2023 - Y', true, '2023-12-30'];
        yield 'the flag last, in brackets' => ['30.12.2023 (yes)', true, '2023-12-30'];
        yield 'dashes in the date, the flag last' => ['30-12-2023 Y', true, '2023-12-30'];
        yield 'dashes in the date, the flag first' => ['Y 30-12-2023', true, '2023-12-30'];
        yield 'spaces around' => ['  Y - 30/12/2023  ', true, '2023-12-30'];
        yield 'full-width' => ['Ｙ－３０／１２／２０２３', true, '2023-12-30'];
    }

    public function testTheDateKeepsItsOpenOrder(): void
    {
        // File 2 writes dd/mm/yyyy - but one cell cannot know: the column's order is settled over all its dates
        $read = self::reader()->read(CellInput::text('Y - 3/4/2023'));

        self::assertTrue($read->flag->isYes());
        self::assertSame(DateCertainty::OrderOpen, $read->date->certainty);
        self::assertSame([DateOrder::DayMonthYear, DateOrder::MonthDayYear], $read->date->possibleOrders);
    }

    public function testTheDateKeepsItsMissingYear(): void
    {
        $read = self::reader()->read(CellInput::text('Y - 20 Sep'));

        self::assertTrue($read->flag->isYes());
        self::assertSame(DateCertainty::Certain, $read->date->certainty);
        self::assertFalse($read->date->yearKnown);
    }

    #[DataProvider('flagsAlone')]
    public function testAFlagAloneHasNoDate(CellInput $cell, bool $flag): void
    {
        $read = self::reader()->read($cell);

        self::assertSame(YesNoCertainty::Known, $read->flag->certainty);
        self::assertSame($flag, $read->flag->value);
        self::assertSame(DateCertainty::NoDate, $read->date->certainty);
        self::assertNull($read->leftover);
    }

    /**
     * @return iterable<string, array{CellInput, bool}>
     */
    public static function flagsAlone(): iterable
    {
        yield 'Y' => [CellInput::text('Y'), true];
        yield 'yes' => [CellInput::text('yes'), true];
        yield 'x' => [CellInput::text('x'), true];
        yield 'N' => [CellInput::text('N'), false];
        yield 'no' => [CellInput::text('no'), false];
        yield 'a ticked checkbox' => [CellInput::boolean(true), true];
        yield 'an unticked checkbox' => [CellInput::boolean(false), false];
        yield 'the number 1' => [CellInput::number(1), true];
        yield 'the number 0' => [CellInput::number(0), false];
    }

    #[DataProvider('datesAlone')]
    public function testADateAloneHasNoFlag(CellInput $cell, string $day): void
    {
        $read = self::reader()->read($cell);

        self::assertSame(YesNoCertainty::Blank, $read->flag->certainty, 'What a date without a flag means is the column\'s business');
        self::assertSame($day, $read->date->date?->format('Y-m-d'));
        self::assertNull($read->leftover);
    }

    /**
     * @return iterable<string, array{CellInput, string}>
     */
    public static function datesAlone(): iterable
    {
        yield 'a text date' => [CellInput::text('30/12/2023'), '2023-12-30'];
        yield 'an ISO date' => [CellInput::text('2023-12-30'), '2023-12-30'];
        yield 'a written date' => [CellInput::text('30 December 2023'), '2023-12-30'];
        yield 'a year as text' => [CellInput::text('2022'), '2022-01-01'];
        yield 'a year as a number' => [CellInput::number(2022), '2022-01-01'];
        yield 'a typed date' => [CellInput::date(TestClock::now()), '2026-10-14'];
    }

    public function testAKnownFlagWithSomethingThatIsNoDateKeepsTheRest(): void
    {
        $read = self::reader()->read(CellInput::text('Yes, twice'));

        self::assertTrue($read->flag->isYes());
        self::assertSame(DateCertainty::Unreadable, $read->date->certainty);
        self::assertSame('twice', $read->leftover);
    }

    public function testAnUnknownWordWithADateIsAQuestionAboutTheWord(): void
    {
        $read = self::reader()->read(CellInput::text('maybe 2022'));

        self::assertSame(YesNoCertainty::Unknown, $read->flag->certainty);
        self::assertSame('maybe', $read->flag->word);
        self::assertSame('2022-01-01', $read->date->date?->format('Y-m-d'));
    }

    #[DataProvider('neither')]
    public function testNeitherAFlagNorADate(CellInput $cell, string $word): void
    {
        $read = self::reader()->read($cell);

        self::assertSame(YesNoCertainty::Unknown, $read->flag->certainty);
        self::assertSame($word, $read->flag->word);
        self::assertSame(DateCertainty::Unreadable, $read->date->certainty);
    }

    /**
     * @return iterable<string, array{CellInput, string}>
     */
    public static function neither(): iterable
    {
        yield 'a status' => [CellInput::text('Back to the library'), 'Back to the library'];
        yield 'a word' => [CellInput::text('maybe'), 'maybe'];
        yield 'n/a' => [CellInput::text('n/a'), 'n/a'];
        yield 'a question mark' => [CellInput::text('?'), '?'];
        yield 'a number that is neither' => [CellInput::number(7), '7'];
        yield 'a duration' => [CellInput::duration(60), '60'];
        yield 'a word, a dash and a word' => [CellInput::text('sold - to a friend'), 'sold - to a friend'];
    }

    public function testAnEmptyCellSaysNothing(): void
    {
        foreach ([CellInput::blank(), CellInput::text(''), CellInput::text("  \u{00A0}")] as $cell) {
            $read = self::reader()->read($cell);

            self::assertSame(YesNoCertainty::Blank, $read->flag->certainty);
            self::assertSame(DateCertainty::NoDate, $read->date->certainty);
            self::assertNull($read->leftover);
        }
    }

    public function testAVeryLongTextIsNeither(): void
    {
        $read = self::reader()->read(CellInput::text(str_repeat('Y - 30/12/2023 ', 200)));

        self::assertSame(YesNoCertainty::Unknown, $read->flag->certainty);
        self::assertSame(DateCertainty::Unreadable, $read->date->certainty);
    }

    private static function reader(): FlagWithDateReader
    {
        return new FlagWithDateReader(new YesNoReader(), new ImportDateReader(new MockClock(TestClock::now())));
    }
}
