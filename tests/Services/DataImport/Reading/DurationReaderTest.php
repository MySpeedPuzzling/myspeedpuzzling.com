<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;

/**
 * Every shape of a time value the real spreadsheets hold (docs/features/data-import/README.md "Durations",
 * docs/features/data-import/value-readers.md).
 */
final class DurationReaderTest extends TestCase
{
    private const int H = 3600;
    private const int M = 60;

    #[DataProvider('certainTexts')]
    public function testAClockTimeOfThreePartsIsCertain(string $text, int $seconds): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame(DurationCertainty::Certain, $read->certainty, $text);
        self::assertSame($seconds, $read->seconds);
        self::assertSame([$seconds], $read->readings);
        self::assertNull($read->shape);
        self::assertNull($read->leftover);
        self::assertSame([], $read->alternatives);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function certainTexts(): iterable
    {
        yield '1:09:17' => ['1:09:17', self::H + 9 * self::M + 17];
        yield '0:55:41' => ['0:55:41', 55 * self::M + 41];
        yield 'a part without its leading zero, as another app exports' => ['0:56:4', 56 * self::M + 4];
        yield 'two parts without leading zeros' => ['1:5:9', self::H + 5 * self::M + 9];
        yield 'leading zeros everywhere' => ['01:02:03', self::H + 2 * self::M + 3];
        yield 'two-digit hours' => ['12:00:01', 12 * self::H + 1];
        yield 'zero hours, zero minutes' => ['0:00:59', 59];
        yield 'the last second of a day' => ['23:59:59', 86399];
        yield 'spaces around' => ['  1:09:17  ', 4157];
        yield 'a tab and a line break around' => ["\t1:09:17\n", 4157];
        yield 'a non-breaking space after' => ["1:09:17\u{00A0}", 4157];
        yield 'a right-to-left mark after' => ["1:09:17\u{200F}", 4157];
        yield 'a zero-width space inside' => ["1:09\u{200B}:17", 4157];
        yield 'full-width digits and colons' => ['１：０９：１７', 4157];
    }

    #[DataProvider('textsWithUnits')]
    public function testATimeWithUnitsIsReadFromText(string $text, int $seconds): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame(DurationCertainty::ReadFromText, $read->certainty, $text);
        self::assertSame($seconds, $read->seconds, $text);
        self::assertSame([$seconds], $read->readings);
        self::assertNull($read->shape);
        self::assertNull($read->leftover);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function textsWithUnits(): iterable
    {
        // The README's and the task's examples
        yield '8min' => ['8min', 8 * self::M];
        yield '8 min' => ['8 min', 8 * self::M];
        yield '25min' => ['25min', 25 * self::M];
        yield '25 min' => ['25 min', 25 * self::M];
        yield '1h 23m' => ['1h 23m', self::H + 23 * self::M];
        yield '1 h 23 min 5 s' => ['1 h 23 min 5 s', self::H + 23 * self::M + 5];
        yield '1h23' => ['1h23', self::H + 23 * self::M];
        yield '18hr36' => ['18hr36', 18 * self::H + 36 * self::M];
        yield '1.5 h' => ['1.5 h', 5400];
        yield '1,5 h' => ['1,5 h', 5400];
        yield '90 minutes' => ['90 minutes', 5400];
        yield '45m30s' => ['45m30s', 45 * self::M + 30];

        // More shapes of the same
        yield 'upper case' => ['8 MIN', 8 * self::M];
        yield 'mixed case' => ['1H 23M', self::H + 23 * self::M];
        yield 'a full stop after the unit' => ['8 min.', 8 * self::M];
        yield 'hours only' => ['2h', 2 * self::H];
        yield 'hours only, a word' => ['2 hours', 2 * self::H];
        yield 'one hour' => ['1 hour', self::H];
        yield 'hrs' => ['3 hrs', 3 * self::H];
        yield 'seconds only' => ['45s', 45];
        yield 'seconds only, a word' => ['150 seconds', 150];
        yield 'secs' => ['90 secs', 90];
        yield 'sec' => ['30 sec', 30];
        yield 'minutes and seconds' => ['12 min 30 sec', 12 * self::M + 30];
        yield 'minutes and seconds, short' => ['12m 30s', 12 * self::M + 30];
        yield 'minutes then a bare number' => ['45m30', 45 * self::M + 30];
        yield 'hours, minutes then a bare number' => ['1h23m45', self::H + 23 * self::M + 45];
        yield 'hours then a bare number after a space' => ['1 h 23', self::H + 23 * self::M];
        yield 'a comma between the parts' => ['1 h, 23 min', self::H + 23 * self::M];
        yield 'and between the parts' => ['1 hour and 23 minutes', self::H + 23 * self::M];
        yield 'one decimal of minutes' => ['90.5 min', 5430];
        yield 'one decimal of hours with a comma' => ['2,5 hours', 9000];
        yield 'two decimals that cannot be minutes' => ['2.75 h', 9900];
        yield 'decimal seconds are rounded' => ['45.5 s', 46];
        yield 'mins' => ['25 mins', 25 * self::M];
        yield 'minute' => ['1 minute', self::M];
        yield 'more minutes than an hour, alone' => ['135 min', 135 * self::M];
        yield 'minute and second marks' => ['8\'30"', 8 * self::M + 30];
        yield 'typographic minute and second marks' => ['8′30″', 8 * self::M + 30];
        yield 'a minute mark alone' => ['45\'', 45 * self::M];

        // A clock time that says what its two parts are
        yield 'two parts named hours' => ['1:02 h', self::H + 2 * self::M];
        yield 'two parts named minutes' => ['17:36 min', 17 * self::M + 36];
        yield 'three parts named hours' => ['1:02:03 h', self::H + 2 * self::M + 3];

        // A fraction can only be one of seconds
        // ... and is dropped, as the add-result form does with a typed time: results are whole seconds
        yield 'minutes, seconds and tenths' => ['17:36.5', 17 * self::M + 36];
        yield 'minutes, seconds and tenths below a half' => ['17:36.4', 17 * self::M + 36];
        yield 'hours, minutes, seconds and hundredths' => ['1:02:03.51', self::H + 2 * self::M + 3];
        yield 'hours, minutes, seconds and hundredths: the same time as without them' => ['0:30:20.23', 30 * self::M + 20];
        yield 'hours, minutes, seconds and a tenth' => ['1:02:03.5', self::H + 2 * self::M + 3];
        yield 'a decimal comma' => ['0:30:20,23', 30 * self::M + 20];
        yield 'thousandths' => ['0:30:20.999', 30 * self::M + 20];
        yield 'a decimal comma of seconds' => ['1:02:03,2', self::H + 2 * self::M + 3];

        // Only brackets, quotes or a full stop around a time
        yield 'in brackets' => ['(1:02:03)', 3723];
        yield 'in quotes' => ['"1:02:03"', 3723];
        yield 'a full stop after' => ['1:02:03.', 3723];

        // Czech
        yield 'cs: minut' => ['25 minut', 25 * self::M];
        yield 'cs: minuty' => ['3 minuty', 3 * self::M];
        yield 'cs: minuta' => ['1 minuta', self::M];
        yield 'cs: hod' => ['2 hod', 2 * self::H];
        yield 'cs: hod.' => ['2 hod. 15 min.', 2 * self::H + 15 * self::M];
        yield 'cs: hodin' => ['5 hodin', 5 * self::H];
        yield 'cs: hodiny' => ['2 hodiny', 2 * self::H];
        yield 'cs: hodina a minut' => ['1 hodina a 23 minut', self::H + 23 * self::M];
        yield 'cs: sekund' => ['45 sekund', 45];
        yield 'cs: sek' => ['12 min 5 sek', 12 * self::M + 5];
        yield 'cs: a decimal comma' => ['1,5 hod', 5400];

        // German
        yield 'de: Minuten' => ['25 Minuten', 25 * self::M];
        yield 'de: Min.' => ['25 Min.', 25 * self::M];
        yield 'de: Std' => ['2 Std', 2 * self::H];
        yield 'de: Std. Min.' => ['1 Std. 5 Min.', self::H + 5 * self::M];
        yield 'de: Stunden' => ['3 Stunden', 3 * self::H];
        yield 'de: Stunde und Minuten' => ['1 Stunde und 20 Minuten', self::H + 20 * self::M];
        yield 'de: Sekunden' => ['30 Sekunden', 30];
        yield 'de: Sek.' => ['5 Min. 30 Sek.', 5 * self::M + 30];

        // Spanish
        yield 'es: minutos' => ['25 minutos', 25 * self::M];
        yield 'es: minuto' => ['1 minuto', self::M];
        yield 'es: horas' => ['2 horas', 2 * self::H];
        yield 'es: hora y minutos' => ['1 hora y 23 minutos', self::H + 23 * self::M];
        yield 'es: segundos' => ['40 segundos', 40];
        yield 'es: seg' => ['3 min 40 seg', 3 * self::M + 40];

        // French
        yield 'fr: minutes' => ['25 minutes', 25 * self::M];
        yield 'fr: heures' => ['2 heures', 2 * self::H];
        yield 'fr: heure et minutes' => ['1 heure et 23 minutes', self::H + 23 * self::M];
        yield 'fr: heure then a bare number' => ['1 heure 5', self::H + 5 * self::M];
        yield 'fr: 1h05' => ['1h05', self::H + 5 * self::M];
        yield 'fr: secondes' => ['40 secondes', 40];
        yield 'fr: mn' => ['12 mn', 12 * self::M];

        // Japanese
        yield 'ja: 分' => ['25分', 25 * self::M];
        yield 'ja: 時間' => ['2時間', 2 * self::H];
        yield 'ja: 時間 分' => ['1時間23分', self::H + 23 * self::M];
        yield 'ja: 時間 分 秒' => ['1時間23分45秒', self::H + 23 * self::M + 45];
        yield 'ja: 分 秒' => ['12分30秒', 12 * self::M + 30];
        yield 'ja: 秒' => ['45秒', 45];
        yield 'ja: full-width digits' => ['２５分', 25 * self::M];
        yield 'ja: 分間' => ['25分間', 25 * self::M];
    }

    #[DataProvider('textsWithLeftovers')]
    public function testATimeInsideOtherTextIsOnlyProposed(string $text, int $seconds, string $leftover): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame(DurationCertainty::ReadWithLeftovers, $read->certainty, $text);
        self::assertSame($seconds, $read->seconds);
        self::assertSame($leftover, $read->leftover);
        self::assertNull($read->shape);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function textsWithLeftovers(): iterable
    {
        yield 'a remark in brackets' => ['0:55:41 (slower than last time)', 3341, 'slower than last time'];
        yield 'a remark before' => ['pb 1:02:03', 3723, 'pb'];
        yield 'a remark after a dash' => ['1:02:03 - with a break', 3723, 'with a break'];
        yield 'a label with a colon' => ['Time: 1:09:17', 4157, 'Time'];
        yield 'not sure' => ['1:02:03?', 3723, '?'];
        yield 'about, with a unit' => ['about 25 min', 25 * self::M, 'about'];
        yield 'a tilde' => ['~25 min', 25 * self::M, '~'];
        yield 'circa' => ['ca. 1h 20m', self::H + 20 * self::M, 'ca.'];
        yield 'a unit and a remark' => ['8min (With a friend, Über-fast)', 8 * self::M, 'With a friend, Über-fast'];
        yield 'a number that is no time stays in the rest' => ['8 min 2 attempts', 8 * self::M, '2 attempts'];
        yield 'a count before' => ['2 x 30 min', 30 * self::M, '2 x'];
        yield 'pieces before' => ['500 pieces in 45 min', 45 * self::M, '500 pieces in'];
        yield 'a bare number attached after the unit' => ['1h23 with a friend', self::H + 23 * self::M, 'with a friend'];
        yield 'Japanese about' => ['23分くらい', 23 * self::M, 'くらい'];
        yield 'a fraction of a second and a remark' => ['1:02:03.5 (pb)', 3723, 'pb'];
    }

    #[DataProvider('twoPartTexts')]
    public function testTwoPartsAreMinutesAndSecondsOrHoursAndMinutes(string $text, int $first, int $second, null|string $leftover): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame($leftover === null ? DurationCertainty::TwoReadings : DurationCertainty::ReadWithLeftovers, $read->certainty, $text);
        self::assertNull($read->seconds, 'Nothing is taken until the column is settled');
        self::assertSame(DurationShape::TwoPartText, $read->shape);
        self::assertSame($leftover, $read->leftover);
        self::assertSame($first * self::M + $second, $read->secondsBy(DurationReadingRule::MinutesSeconds));
        self::assertSame($first * self::H + $second * self::M, $read->secondsBy(DurationReadingRule::HoursMinutes));
        self::assertSame([$first * self::M + $second, $first * self::H + $second * self::M], $read->readings);
    }

    /**
     * @return iterable<string, array{string, int, int, null|string}>
     */
    public static function twoPartTexts(): iterable
    {
        yield '17:36' => ['17:36', 17, 36, null];
        yield 'a first part that cannot be an hour of a day can still be hours of a puzzle' => ['83:45', 83, 45, null];
        yield '1:02' => ['1:02', 1, 2, null];
        yield 'no leading zero' => ['1:5', 1, 5, null];
        yield 'leading zeros' => ['05:07', 5, 7, null];
        yield 'zero first' => ['0:45', 0, 45, null];
        yield 'three digits first' => ['125:30', 125, 30, null];
        yield 'full-width' => ['１２：３４', 12, 34, null];
        yield 'in brackets' => ['(17:36)', 17, 36, null];
        yield 'with -ish' => ['1:40ish', 1, 40, 'ish'];
        yield 'words before' => ['casual but 1:11', 1, 11, 'casual but'];
        yield 'words after' => ['17:36 for both', 17, 36, 'for both'];
        yield 'a question mark' => ['17:36?', 17, 36, '?'];
    }

    public function testATwoPartTimeTooLongInHoursKeepsItsOtherReading(): void
    {
        $read = (new DurationReader())->read(CellInput::text('2000:30'));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertSame(DurationShape::TwoPartText, $read->shape);
        self::assertSame([2000 * self::M + 30], $read->readings);
        self::assertNull($read->secondsBy(DurationReadingRule::HoursMinutes));
    }

    /**
     * @param array<string, int> $expected Seconds by rule
     */
    #[DataProvider('bareNumbers')]
    public function testANumberWithoutAUnitHasSeveralMeanings(CellInput $cell, array $expected): void
    {
        $read = (new DurationReader())->read($cell);

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertSame(DurationShape::BareNumber, $read->shape);
        self::assertNull($read->seconds);

        $byRule = [];

        foreach ($read->alternatives as $alternative) {
            $byRule[$alternative->rule->value] = $alternative->seconds;
        }

        self::assertSame($expected, $byRule);
    }

    /**
     * @return iterable<string, array{CellInput, array<string, int>}>
     */
    public static function bareNumbers(): iterable
    {
        $whole237 = ['minutes' => 14220, 'seconds' => 237, 'hours' => 853200, 'hours_dot_minutes' => 853200, 'minutes_dot_seconds' => 14220];

        yield 'a whole number as text' => [CellInput::text('237'), $whole237];
        yield 'full-width digits' => [CellInput::text('２３７'), $whole237];
        yield 'a whole number cell' => [CellInput::number(237), $whole237];
        yield 'a whole number cell stored as a float' => [CellInput::number(237.0), $whole237];
        yield 'a small number' => [CellInput::text('2'), ['minutes' => 120, 'seconds' => 2, 'hours' => 7200, 'hours_dot_minutes' => 7200, 'minutes_dot_seconds' => 120]];
        yield 'a number too large for hours' => [CellInput::text('5000'), ['minutes' => 300000, 'seconds' => 5000, 'minutes_dot_seconds' => 300000]];

        $hoursDotMinutes = ['minutes' => 486, 'seconds' => 8, 'hours' => 29160, 'hours_dot_minutes' => 29400, 'minutes_dot_seconds' => 490];

        yield 'the "hours.minutes" habit as text' => [CellInput::text('8.10'), $hoursDotMinutes];
        yield 'the same number in a cell, its last zero gone' => [CellInput::number(8.1), $hoursDotMinutes];
        yield 'the same as text without the zero' => [CellInput::text('8.1'), $hoursDotMinutes];
        yield 'a decimal comma' => [CellInput::text('8,10'), $hoursDotMinutes];
        yield 'minutes written as 05' => [CellInput::text('8.05'), ['minutes' => 483, 'seconds' => 8, 'hours' => 28980, 'hours_dot_minutes' => 29100, 'minutes_dot_seconds' => 485]];
        yield 'decimals that cannot be minutes' => [CellInput::text('8.75'), ['minutes' => 525, 'seconds' => 9, 'hours' => 31500]];
        yield 'one and a half' => [CellInput::number(1.5), ['minutes' => 90, 'seconds' => 2, 'hours' => 5400, 'hours_dot_minutes' => 6600, 'minutes_dot_seconds' => 110]];
        yield 'the part of a day a spreadsheet stores a time as' => [CellInput::number(0.048113425925926), ['minutes' => 3, 'hours' => 173, 'day_fraction' => 4157]];
        yield 'many decimals above one are no part of a day' => [CellInput::number(1.2345), ['minutes' => 74, 'seconds' => 1, 'hours' => 4444]];
        yield 'text decimals without a fraction' => [CellInput::text('8.00'), ['minutes' => 480, 'seconds' => 8, 'hours' => 28800, 'hours_dot_minutes' => 28800, 'minutes_dot_seconds' => 480]];
        yield 'a part of a day with two decimals' => [CellInput::number(0.05), ['minutes' => 3, 'hours' => 180, 'hours_dot_minutes' => 300, 'minutes_dot_seconds' => 5, 'day_fraction' => 4320]];
        yield 'the same as text' => [CellInput::text('0.05'), ['minutes' => 3, 'hours' => 180, 'hours_dot_minutes' => 300, 'minutes_dot_seconds' => 5, 'day_fraction' => 4320]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('decimalsWithUnits')]
    public function testTwoDecimalsBelowSixtyWithAUnitMayBeTheHoursDotMinutesHabit(string $text, array $expected): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertSame(DurationShape::DecimalWithUnit, $read->shape);
        self::assertSame($expected, $read->readings);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function decimalsWithUnits(): iterable
    {
        yield '1.30 h: 1.3 hours or 1 h 30 min' => ['1.30 h', [4680, 5400]];
        yield '8.10 hours' => ['8.10 hours', [29160, 29400]];
        yield '12.30 min: 12.3 minutes or 12 min 30 s' => ['12.30 min', [738, 750]];
        yield 'a decimal comma' => ['1,45 hod', [5220, 6300]];
    }

    #[DataProvider('unreadableTexts')]
    public function testWhatIsNoTimeIsUnreadable(string $text): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame(DurationCertainty::Unreadable, $read->certainty, $text);
        self::assertNull($read->seconds);
        self::assertSame([], $read->readings);
        self::assertNull($read->shape);
        self::assertNull($read->leftover);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTexts(): iterable
    {
        // The README's and the task's examples
        yield 'a piece of a time' => ['1:13:??'];
        yield 'a list of several times' => ['6:07, 6:23, 5:05'];
        yield 'a word: tube' => ['tube'];
        yield 'a word: timed' => ['timed'];
        yield 'words: gold foil' => ['gold foil'];
        yield 'a question mark' => ['?'];
        yield 'a dash' => ['-'];
        yield 'dollar signs' => ['$$$'];

        // A sign touching the time: a difference, not a time (how a spreadsheet exports a negative one)
        yield 'a minus before three parts' => ['-1:02:03'];
        yield 'a typographic minus' => ['−1:02:03'];
        yield 'an en dash used as a minus' => ['–1:02'];
        yield 'a plus' => ['+1:02:03'];
        yield 'a plus before two parts' => ['+0:45'];
        yield 'a minus before a unit time' => ['-45 s'];
        yield 'a minus before hours and a number' => ['-1h23'];
        yield 'a minus in brackets' => ['(-1:02:03)'];
        yield 'a minus after a label' => ['diff: -0:02:10'];
        yield 'a sign right after the time' => ['1:02:03-'];

        // Days next to a time: the time is only a part of what is meant
        yield 'days and hours' => ['2 days 3 h'];
        yield 'a day and a clock time' => ['1d 2:30:00'];
        yield 'days after the time' => ['3 h 2 days'];
        yield 'cs: days' => ['2 dny 3 hod'];
        yield 'de: days' => ['2 Tage 3 Std'];
        yield 'es: days' => ['2 días 3 h'];
        yield 'fr: days' => ['2 jours 3 h'];
        yield 'ja: days' => ['2日 3時間'];

        // Digits of another script are no number here
        yield 'Arabic-Indic digits with a unit' => ['٣ h'];
        yield 'Arabic-Indic digits in a clock time' => ['١:٠٩:١٧'];
        yield 'Devanagari digits' => ['१२:३४'];

        yield 'two times with a slash' => ['1:02:03 / 58:10'];
        yield 'two times of three parts' => ['1:02:03, 0:58:10'];
        yield 'a time and a unit time' => ['17:36 5 min'];
        yield 'two unit times' => ['1 h 30 or 45 min'];
        yield 'four parts' => ['1:02:03:04'];
        yield 'minutes of 60 and more' => ['1:75:00'];
        yield 'seconds of 60 and more' => ['1:02:75'];
        yield 'seconds of 60 and more in two parts' => ['17:75'];
        yield 'a missing part' => ['1::17'];
        yield 'a colon at the end' => ['1:09:'];
        yield 'a colon at the start' => [':45'];
        yield 'a question mark for the minutes' => ['1:??:17'];
        yield 'a question mark before a time' => ['?:45'];
        yield 'letters for digits' => ['1:xx:17'];
        yield 'a negative number' => ['-5'];
        yield 'a negative time' => ['-1:xx'];
        yield 'scientific notation' => ['1e5'];
        yield 'not a number' => ['NaN'];
        yield 'infinity' => ['INF'];
        yield 'a number with a thousands mark' => ['1,000'];
        yield 'three decimals' => ['8.125'];
        yield 'a number too long' => ['12345678'];
        yield 'a number too large for any reading' => ['5000000'];
        yield 'a time above the longest offered' => ['1000:00:01'];
        yield 'digits glued to a unit and more digits' => ['1h234'];
        yield 'a unit that is the start of a word' => ['5 stars'];
        yield 'minutes of 60 and more under hours' => ['1h 75m'];
        yield 'seconds of 60 and more under minutes' => ['5 min 75 s'];
        yield 'decimals in two parts' => ['1.5 h 20.5 min'];
        yield 'days' => ['3 days'];
        yield 'a date' => ['30/12/2023'];
        yield 'an emoji' => ['⏱️'];
        yield 'a plus sign' => ['+'];
        yield 'n/a' => ['n/a'];
        yield 'DNF' => ['DNF'];
        yield 'an empty pair of brackets' => ['()'];
        yield 'a zero time with a remark' => ['0:00 (dnf)'];
        yield 'a very long text' => [str_repeat('1:09:17 and so on ', 40)];
        yield 'a megabyte of digits' => [str_repeat('1', 1_000_000)];
        yield 'control characters only between letters' => ["a\u{0000}b\u{0007}c"];
    }

    #[DataProvider('noTime')]
    public function testAnEmptyCellAndAZeroAreNoTime(CellInput $cell): void
    {
        $read = (new DurationReader())->read($cell);

        self::assertSame(DurationCertainty::NoTime, $read->certainty);
        self::assertNull($read->seconds);
        self::assertSame([], $read->readings);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function noTime(): iterable
    {
        yield 'no cell' => [CellInput::blank()];
        yield 'an empty text' => [CellInput::text('')];
        yield 'spaces' => [CellInput::text('   ')];
        yield 'a tab and a line break' => [CellInput::text("\t\n")];
        yield 'a non-breaking space' => [CellInput::text("\u{00A0}")];
        yield 'a zero-width space' => [CellInput::text("\u{200B}")];
        yield '0:00:00' => [CellInput::text('0:00:00')];
        yield '0:00' => [CellInput::text('0:00')];
        yield 'zero' => [CellInput::text('0')];
        yield '0.0' => [CellInput::text('0.0')];
        yield '0 min' => [CellInput::text('0 min')];
        yield 'a zero number' => [CellInput::number(0)];
        yield 'a zero float' => [CellInput::number(0.0)];
        yield 'a zero duration' => [CellInput::duration(0)];
        yield 'midnight' => [CellInput::timeOfDay(0)];
    }

    public function testADurationCellUnderADayWithSecondsIsCertain(): void
    {
        $read = (new DurationReader())->read(CellInput::duration(4157));

        self::assertSame(DurationCertainty::Certain, $read->certainty);
        self::assertSame(4157, $read->seconds);
        self::assertNull($read->shape);
        self::assertSame([4157], $read->readings);
    }

    public function testADurationCellUnderADayWithoutSecondsIsTheColumnsToSettle(): void
    {
        // 17:36 typed into a cell formatted [h]:mm:ss is stored as 17 h 36 min
        $read = (new DurationReader())->read(CellInput::duration(17 * self::H + 36 * self::M));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertNull($read->seconds, 'Never taken by itself - a column of real h:mm:ss times lets it stand, any other asks');
        self::assertSame(DurationShape::ZeroSecondsDurationCell, $read->shape);
        self::assertSame(17 * self::H + 36 * self::M, $read->secondsBy(DurationReadingRule::AsStored));
        self::assertSame(17 * self::M + 36, $read->secondsBy(DurationReadingRule::StoredHoursAsMinutes));
        self::assertSame([17 * self::H + 36 * self::M, 17 * self::M + 36], $read->readings);
    }

    public function testADurationCellOfADayOrMoreHasTwoReadings(): void
    {
        // The README's example: "45:30" typed, stored as 45 h 30 min
        $read = (new DurationReader())->read(CellInput::duration(45 * self::H + 30 * self::M));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertNull($read->seconds);
        self::assertSame(DurationShape::LongDurationCell, $read->shape);
        self::assertSame([45 * self::H + 30 * self::M, 45 * self::M + 30], $read->readings);
    }

    public function testADurationCellOfADayOrMoreWithSecondsHasOneReadingButStillAsksTheColumn(): void
    {
        $read = (new DurationReader())->read(CellInput::duration(30 * self::H + 15 * self::M + 20));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertSame(DurationShape::LongDurationCell, $read->shape);
        self::assertSame([30 * self::H + 15 * self::M + 20], $read->readings);
        self::assertNull($read->secondsBy(DurationReadingRule::StoredHoursAsMinutes));
    }

    public function testExactlyADayIsALongDurationCell(): void
    {
        $read = (new DurationReader())->read(CellInput::duration(86400));

        self::assertSame(DurationShape::LongDurationCell, $read->shape);
        self::assertSame([86400, 1440], $read->readings);
    }

    public function testATimeOfDayCellWithSecondsIsCertain(): void
    {
        // "1:09:17" typed into a plain cell is stored as a time of day
        $read = (new DurationReader())->read(CellInput::timeOfDay(4157));

        self::assertSame(DurationCertainty::Certain, $read->certainty);
        self::assertSame(4157, $read->seconds);
        self::assertNull($read->shape);
    }

    public function testATimeOfDayCellWithoutSecondsHasTwoReadings(): void
    {
        // The README's example: a time-of-day cell "1:02"
        $read = (new DurationReader())->read(CellInput::timeOfDay(self::H + 2 * self::M));

        self::assertSame(DurationCertainty::TwoReadings, $read->certainty);
        self::assertNull($read->seconds);
        self::assertSame(DurationShape::TimeOfDayCell, $read->shape);
        self::assertSame([self::H + 2 * self::M, self::M + 2], $read->readings);
    }

    public function testAClockTimeIsNoDuration(): void
    {
        // A cell formatted h:mm AM/PM, or a date with a time: "Start time", NOW() - a moment, never how long
        $reader = new DurationReader();

        self::assertSame(DurationCertainty::Unreadable, $reader->read(CellInput::timeOfDay(self::H + 2 * self::M, true))->certainty);
        self::assertSame(DurationCertainty::Unreadable, $reader->read(CellInput::timeOfDay(4157, true))->certainty);
        self::assertSame([], $reader->read(CellInput::timeOfDay(4157, true))->readings);
        self::assertSame(4157, $reader->read(CellInput::timeOfDay(4157))->seconds, 'Without the flag a time cell with seconds is a duration typed into a plain cell');
    }

    /**
     * @param list<int> $readings
     */
    #[DataProvider('textsLikeCells')]
    public function testATextOfThreePartsReadsLikeTheCellOfTheSameValue(string $text, int $stored, DurationShape $shape, array $readings): void
    {
        // A sheet saved as CSV must read like its workbook
        $reader = new DurationReader();
        $fromText = $reader->read(CellInput::text($text));
        $fromCell = $reader->read(CellInput::duration($stored));

        self::assertSame(DurationCertainty::TwoReadings, $fromText->certainty, $text);
        self::assertNull($fromText->seconds);
        self::assertSame($shape, $fromText->shape);
        self::assertSame($readings, $fromText->readings);

        self::assertSame($fromCell->certainty, $fromText->certainty);
        self::assertSame($fromCell->shape, $fromText->shape);
        self::assertSame($fromCell->readings, $fromText->readings);
    }

    /**
     * @return iterable<string, array{string, int, DurationShape, list<int>}>
     */
    public static function textsLikeCells(): iterable
    {
        yield 'the README\'s 45:30 typed, stored as 45 h 30 min' => ['45:30:00', 163800, DurationShape::LongDurationCell, [163800, 2730]];
        yield 'exactly a day' => ['24:00:00', 86400, DurationShape::LongDurationCell, [86400, 1440]];
        yield 'more than a day, with seconds: one reading, still the column\'s' => ['25:10:05', 90605, DurationShape::LongDurationCell, [90605]];
        yield 'three-digit hours' => ['100:00:00', 360000, DurationShape::LongDurationCell, [360000, 6000]];
        yield 'the longest time offered' => ['1000:00:00', DurationReader::MAX_SECONDS, DurationShape::LongDurationCell, [DurationReader::MAX_SECONDS, 60000]];
        yield 'under a day, seconds :00' => ['1:30:00', 5400, DurationShape::ZeroSecondsDurationCell, [5400, 90]];
        yield 'under a day, seconds :00, leading zeros' => ['01:30:00', 5400, DurationShape::ZeroSecondsDurationCell, [5400, 90]];
        yield 'a whole number of hours' => ['2:00:00', 7200, DurationShape::ZeroSecondsDurationCell, [7200, 120]];
        yield 'in brackets' => ['(17:36:00)', 63360, DurationShape::ZeroSecondsDurationCell, [63360, 1056]];
    }

    public function testATextWithSecondsUnderADayIsCertainLikeItsCell(): void
    {
        $reader = new DurationReader();

        self::assertSame(DurationCertainty::Certain, $reader->read(CellInput::text('12:00:01'))->certainty);
        self::assertSame($reader->read(CellInput::duration(43201))->seconds, $reader->read(CellInput::text('12:00:01'))->seconds);
    }

    #[DataProvider('twoPartsWithHundredths')]
    public function testTwoPartsAndTwoDecimalsBelowSixtyMayBeHoursMinutesAndSeconds(string $text, int $minutesSeconds, int $hoursMinutesSeconds, null|string $leftover = null): void
    {
        $read = (new DurationReader())->read(CellInput::text($text));

        self::assertSame($leftover === null ? DurationCertainty::TwoReadings : DurationCertainty::ReadWithLeftovers, $read->certainty, $text);
        self::assertNull($read->seconds);
        self::assertSame(DurationShape::TwoPartTextWithHundredths, $read->shape);
        self::assertSame($minutesSeconds, $read->secondsBy(DurationReadingRule::MinutesSeconds));
        self::assertSame($hoursMinutesSeconds, $read->secondsBy(DurationReadingRule::HoursMinutesDotSeconds));
        self::assertSame($leftover, $read->leftover);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int, 3?: string}>
     */
    public static function twoPartsWithHundredths(): iterable
    {
        yield '1:02.03 - 62.03 s, or 1 h 2 min 3 s' => ['1:02.03', 62, 3723];
        yield 'hundredths of a half and more are dropped too' => ['17:36.50', 1056, 63410];
        yield '30:20.23 - 30 min 20 s (the reading taken unasked), never unreadable' => ['30:20.23', 1820, 109223];
        yield '30:20,23' => ['30:20,23', 1820, 109223];
        yield 'a decimal comma' => ['1:02,03', 62, 3723];
        yield 'with a remark' => ['1:02.03 (pb)', 62, 3723, 'pb'];
    }

    public function testAnyOtherFractionCanOnlyBeOneOfSeconds(): void
    {
        $reader = new DurationReader();

        foreach (['17:36.5' => 1056, '1:02.75' => 62, '1:02.035' => 62, '1:02.999' => 62, '30:20.5' => 1820] as $text => $seconds) {
            $read = $reader->read(CellInput::text($text));

            self::assertSame(DurationCertainty::ReadFromText, $read->certainty, $text);
            self::assertSame($seconds, $read->seconds, $text);
            self::assertNull($read->shape, $text);
        }
    }

    public function testAValueWithAShapeIsNeverTakenByItself(): void
    {
        $reader = new DurationReader();

        $cells = [
            CellInput::text('17:36'), CellInput::text('1:02.03'), CellInput::text('45:30:00'), CellInput::text('1:30:00'), CellInput::text('237'),
            CellInput::text('8.10'), CellInput::text('1.30 h'), CellInput::text('17:36 for both'), CellInput::number(237), CellInput::number(8.1),
            CellInput::duration(5400), CellInput::duration(163800), CellInput::duration(90605), CellInput::timeOfDay(3720),
        ];

        foreach ($cells as $cell) {
            $read = $reader->read($cell);

            self::assertNotNull($read->shape);
            self::assertNull($read->seconds);
            self::assertNotSame([], $read->readings);
        }
    }

    #[DataProvider('unreadableCells')]
    public function testCellsThatAreNoTime(CellInput $cell): void
    {
        $read = (new DurationReader())->read($cell);

        self::assertSame(DurationCertainty::Unreadable, $read->certainty);
        self::assertSame([], $read->readings);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function unreadableCells(): iterable
    {
        yield 'a negative duration' => [CellInput::duration(-60)];
        yield 'a negative time of day' => [CellInput::timeOfDay(-1)];
        yield 'a time of day of a day and more' => [CellInput::timeOfDay(90000)];
        yield 'a negative number' => [CellInput::number(-5)];
        yield 'a negative float' => [CellInput::number(-0.5)];
        yield 'not a number' => [CellInput::number(NAN)];
        yield 'infinity' => [CellInput::number(INF)];
        yield 'minus infinity' => [CellInput::number(-INF)];
        yield 'a huge number' => [CellInput::number(1e15)];
        yield 'the largest integer' => [CellInput::number(PHP_INT_MAX)];
        yield 'a date' => [CellInput::date(TestClock::now())];
        yield 'yes' => [CellInput::boolean(true)];
        yield 'no' => [CellInput::boolean(false)];
    }

    public function testAValueIsSettledByTheRuleOfItsColumn(): void
    {
        $reader = new DurationReader();

        $settled = $reader->read(CellInput::text('17:36'))->settledBy(DurationReadingRule::MinutesSeconds);
        self::assertSame(DurationCertainty::Certain, $settled->certainty);
        self::assertSame(1056, $settled->seconds);
        self::assertNull($settled->shape);

        $withLeftover = $reader->read(CellInput::text('17:36 for both'))->settledBy(DurationReadingRule::HoursMinutes);
        self::assertSame(DurationCertainty::ReadWithLeftovers, $withLeftover->certainty, 'The leftover still needs the player\'s yes');
        self::assertSame(63360, $withLeftover->seconds);
        self::assertSame('for both', $withLeftover->leftover);

        $notReadable = $reader->read(CellInput::text('8.75'))->settledBy(DurationReadingRule::HoursDotMinutes);
        self::assertSame(DurationCertainty::Unreadable, $notReadable->certainty, '8.75 is no "hours.minutes"');

        $untouched = $reader->read(CellInput::text('1:09:17'))->settledBy(DurationReadingRule::MinutesSeconds);
        self::assertSame(4157, $untouched->seconds, 'A value with one meaning is not the column\'s to settle');
    }

    public function testEveryReadingIsATimeWithinTheLimit(): void
    {
        $reader = new DurationReader();

        foreach (['999:59', '9999:59', '99999', '9999999', '99999.99', '99999 h', '99999 min', '99999 s', '999:59:59', '9999:59:59'] as $text) {
            $read = $reader->read(CellInput::text($text));

            foreach ($read->readings as $seconds) {
                self::assertGreaterThan(0, $seconds, $text);
                self::assertLessThanOrEqual(DurationReader::MAX_SECONDS, $seconds, $text);
            }
        }
    }
}
