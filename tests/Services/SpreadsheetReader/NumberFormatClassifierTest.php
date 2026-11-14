<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SpreadsheetReader\NumberFormatClassifier;
use SpeedPuzzling\Web\Value\Spreadsheet\DatePrecision;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormatKind;

final class NumberFormatClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, NumberFormatKind, null|DatePrecision, bool, bool}> code, kind, precision,
     *                                                                                             year shown, time shown
     */
    public static function codes(): iterable
    {
        // Dates: how exactly, and whether the year is in sight
        yield 'm/d' => ['m/d', NumberFormatKind::Date, DatePrecision::Day, false, false];
        yield 'mm-dd-yy' => ['mm-dd-yy', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'm/d/yyyy' => ['m/d/yyyy', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'd.m.yyyy' => ['d.m.yyyy', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'd.m.' => ['d.m.', NumberFormatKind::Date, DatePrecision::Day, false, false];
        yield 'yyyy-mm-dd' => ['yyyy-mm-dd', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'd-mmm' => ['d-mmm', NumberFormatKind::Date, DatePrecision::Day, false, false];
        yield 'weekday only' => ['dddd', NumberFormatKind::Date, DatePrecision::Day, false, false];
        yield 'mmmm\ yyyy' => ['mmmm\ yyyy', NumberFormatKind::Date, DatePrecision::Month, true, false];
        yield 'mmm\ yyyy' => ['mmm\ yyyy', NumberFormatKind::Date, DatePrecision::Month, true, false];
        yield 'mmm-yy' => ['mmm-yy', NumberFormatKind::Date, DatePrecision::Month, true, false];
        yield 'mm/yyyy' => ['mm/yyyy', NumberFormatKind::Date, DatePrecision::Month, true, false];
        yield 'month only' => ['mmmm', NumberFormatKind::Date, DatePrecision::Month, false, false];
        yield 'yyyy' => ['yyyy', NumberFormatKind::Date, DatePrecision::Year, true, false];
        yield 'yy' => ['yy', NumberFormatKind::Date, DatePrecision::Year, true, false];
        yield 'locale tag and a text section' => ['[$-409]d-mmm-yy;@', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'long system date' => ['[$-F800]dddd\,\ mmmm\ dd\,\ yyyy', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'quoted text between the parts' => ['yyyy"年"m"月"d"日"', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'era year' => ['[$-404]e/m/d', NumberFormatKind::Date, DatePrecision::Day, true, false];
        yield 'colour before a date' => ['[Red]d/m/yyyy', NumberFormatKind::Date, DatePrecision::Day, true, false];

        // A date with a time: "m" after hours is minutes, before them a month
        yield 'm/d/yyyy h:mm' => ['m/d/yyyy h:mm', NumberFormatKind::Date, DatePrecision::Day, true, true];
        yield 'yyyy-mm-dd hh:mm:ss' => ['yyyy-mm-dd hh:mm:ss', NumberFormatKind::Date, DatePrecision::Day, true, true];
        yield 'd.m. h:mm' => ['d.m. h:mm', NumberFormatKind::Date, DatePrecision::Day, false, true];

        // Times of day
        yield 'h:mm:ss' => ['h:mm:ss', NumberFormatKind::Time, null, false, false];
        yield 'hh:mm:ss' => ['hh:mm:ss', NumberFormatKind::Time, null, false, false];
        yield 'h:mm' => ['h:mm', NumberFormatKind::Time, null, false, false];
        yield 'mm:ss' => ['mm:ss', NumberFormatKind::Time, null, false, false];
        yield 'mm:ss.0' => ['mm:ss.0', NumberFormatKind::Time, null, false, false];
        yield 'h:mm AM/PM' => ['h:mm AM/PM', NumberFormatKind::Time, null, false, false];
        yield 'h:mm:ss AM/PM with a locale tag' => ['[$-F400]h:mm:ss\ AM/PM', NumberFormatKind::Time, null, false, false];
        yield 'seconds with hundredths' => ['ss.00', NumberFormatKind::Time, null, false, false];

        // Elapsed time
        yield '[h]:mm:ss' => ['[h]:mm:ss', NumberFormatKind::Duration, null, false, false];
        yield '[hh]:mm:ss' => ['[hh]:mm:ss', NumberFormatKind::Duration, null, false, false];
        yield '[h]:mm' => ['[h]:mm', NumberFormatKind::Duration, null, false, false];
        yield '[mm]:ss' => ['[mm]:ss', NumberFormatKind::Duration, null, false, false];
        yield '[m]:ss' => ['[m]:ss', NumberFormatKind::Duration, null, false, false];
        yield '[s]' => ['[s]', NumberFormatKind::Duration, null, false, false];
        yield '[H]:MM:SS upper case' => ['[H]:MM:SS', NumberFormatKind::Duration, null, false, false];

        // Plain numbers
        yield '0.00%' => ['0.00%', NumberFormatKind::Number, null, false, false];
        yield '#,##0.00' => ['#,##0.00', NumberFormatKind::Number, null, false, false];
        yield '0' => ['0', NumberFormatKind::Number, null, false, false];
        yield 'scientific' => ['0.00E+00', NumberFormatKind::Number, null, false, false];
        yield 'fraction' => ['# ?/?', NumberFormatKind::Number, null, false, false];
        yield 'negative in red' => ['#,##0.00;[Red]-#,##0.00', NumberFormatKind::Number, null, false, false];
        yield 'a condition' => ['[>100]0.0;0.00', NumberFormatKind::Number, null, false, false];
        yield 'quoted letter that looks like a month' => ['"m"0', NumberFormatKind::Number, null, false, false];
        yield 'quoted unit' => ['0.0" min"', NumberFormatKind::Number, null, false, false];
        yield 'escaped letters' => ['0\ \m\i\n', NumberFormatKind::Number, null, false, false];
        yield 'currency with a locale tag' => ['#,##0.00\ [$Kč-405]', NumberFormatKind::Number, null, false, false];
        yield 'accounting with padding' => ['_-* #,##0.00\ _€_-;\-* #,##0.00\ _€_-', NumberFormatKind::Number, null, false, false];

        // Text and General
        yield '@' => ['@', NumberFormatKind::Text, null, false, false];
        yield 'General' => ['General', NumberFormatKind::General, null, false, false];
        yield 'general lower case' => ['general', NumberFormatKind::General, null, false, false];
        yield 'General with a unit' => ['General" pcs"', NumberFormatKind::General, null, false, false];
        yield 'empty code' => ['', NumberFormatKind::General, null, false, false];
        yield 'everything hidden' => [';;;', NumberFormatKind::General, null, false, false];
        yield 'unclosed bracket' => ['[Red', NumberFormatKind::General, null, false, false];
    }

    #[DataProvider('codes')]
    public function testCodeIsClassified(string $code, NumberFormatKind $kind, null|DatePrecision $precision, bool $yearShown, bool $timeShown): void
    {
        $format = NumberFormatClassifier::classify($code);

        self::assertSame($kind, $format->kind);
        self::assertSame($precision, $format->precision);
        self::assertSame($yearShown, $format->yearShown);
        self::assertSame($timeShown, $format->timeShown);
    }

    public function testPercentIsNoticed(): void
    {
        self::assertTrue(NumberFormatClassifier::classify('0.00%')->percent);
        self::assertTrue(NumberFormatClassifier::classify('0%')->percent);
        self::assertFalse(NumberFormatClassifier::classify('0.00')->percent);
        self::assertFalse(NumberFormatClassifier::classify('0.00"%"')->percent);
    }

    /**
     * @return iterable<string, array{int, NumberFormatKind, null|DatePrecision, bool}>
     */
    public static function builtInFormats(): iterable
    {
        yield '0 General' => [0, NumberFormatKind::General, null, false];
        yield '2 two decimals' => [2, NumberFormatKind::Number, null, false];
        yield '9 percent' => [9, NumberFormatKind::Number, null, false];
        yield '14 short date' => [14, NumberFormatKind::Date, DatePrecision::Day, true];
        yield '15 d-mmm-yy' => [15, NumberFormatKind::Date, DatePrecision::Day, true];
        yield '16 d-mmm' => [16, NumberFormatKind::Date, DatePrecision::Day, false];
        yield '17 mmm-yy' => [17, NumberFormatKind::Date, DatePrecision::Month, true];
        yield '18 h:mm AM/PM' => [18, NumberFormatKind::Time, null, false];
        yield '20 h:mm' => [20, NumberFormatKind::Time, null, false];
        yield '21 h:mm:ss' => [21, NumberFormatKind::Time, null, false];
        yield '22 date and time' => [22, NumberFormatKind::Date, DatePrecision::Day, true];
        yield '45 mm:ss' => [45, NumberFormatKind::Time, null, false];
        yield '46 [h]:mm:ss' => [46, NumberFormatKind::Duration, null, false];
        yield '47 mm:ss.0' => [47, NumberFormatKind::Time, null, false];
        yield '49 text' => [49, NumberFormatKind::Text, null, false];
        yield '31 East Asian date' => [31, NumberFormatKind::Date, DatePrecision::Day, true];
    }

    #[DataProvider('builtInFormats')]
    public function testBuiltInFormatIsKnown(int $formatId, NumberFormatKind $kind, null|DatePrecision $precision, bool $yearShown): void
    {
        $code = NumberFormatClassifier::builtInCode($formatId);

        self::assertNotNull($code);

        $format = NumberFormatClassifier::classify($code);

        self::assertSame($kind, $format->kind);
        self::assertSame($precision, $format->precision);
        self::assertSame($yearShown, $format->yearShown);
    }

    public function testAFormatAWorkbookMustDefineItselfIsNotBuiltIn(): void
    {
        self::assertNull(NumberFormatClassifier::builtInCode(164));
        self::assertNull(NumberFormatClassifier::builtInCode(23));
    }
}
