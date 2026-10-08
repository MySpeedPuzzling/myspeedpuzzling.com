<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The date picker (assets/datepicker_locale.js + datepicker_controller.js): an American organiser picked Monday and
 * Tuesday bar nights and both were saved a day late - the calendar started every week on Monday for every visitor,
 * so the column they read as Monday held Tuesday. The week now starts on the visitor's own first day, and the field
 * shows the weekday of what was picked. Runs the module and the real flatpickr (jsdom) under node, in several zones:
 * flatpickr works on the browser's wall time.
 */
final class DatePickerScriptTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function visitorZones(): iterable
    {
        yield 'Los Angeles' => ['America/Los_Angeles'];
        yield 'New York' => ['America/New_York'];
        yield 'UTC' => ['UTC'];
        yield 'Prague' => ['Europe/Prague'];
        yield 'Auckland' => ['Pacific/Auckland'];
    }

    public function testTheWeekStartsOnTheVisitorsFirstDay(): void
    {
        $cases = [
            // visitor, page, without week info (Firefox) => flatpickr's day (0 = Sunday)
            ['en-US', 'en', false, 0],
            ['en', 'en', false, 0],
            ['en-GB', 'en', false, 1],
            ['en-CA', 'en', false, 0],
            ['es-MX', 'es', false, 0],
            ['pt-BR', 'en', false, 0],
            ['ja-JP', 'ja', false, 0],
            ['ja', 'en', false, 0],
            ['de-DE', 'de', false, 1],
            ['fr-FR', 'fr', false, 1],
            ['cs-CZ', 'cs', false, 1],
            ['ar-EG', 'en', false, 6],
            ['dv-MV', 'en', false, 5],
            // The visitor's own choice inside the locale wins
            ['en-US-u-fw-mon', 'en', false, 1],
            // Czech pages keep Monday whoever comes
            ['en-US', 'cs', false, 1],
            // Firefox: the region, written or likely
            ['en-US', 'en', true, 0],
            ['en', 'en', true, 0],
            ['en-GB', 'en', true, 1],
            ['es', 'es', true, 1],
            ['es-MX', 'es', true, 0],
            ['ja', 'ja', true, 0],
            ['ar-EG', 'en', true, 6],
            ['de', 'de', true, 1],
            ['en-US', 'cs', true, 1],
            // No language at all reads as English (US), a broken one as Monday - what everybody had before
            ['', 'en', true, 0],
            ['not a locale!', 'en', false, 1],
        ];

        $results = $this->runInNode(['firstDays' => array_map(
            static fn (array $case): array => ['visitor' => $case[0], 'page' => $case[1], 'noWeekInfo' => $case[2]],
            $cases,
        )])['firstDays'];

        self::assertSame(array_column($cases, 3), $results);
    }

    /**
     * The table used without week info is CLDR's: for every region the engine knows, the same first day as the
     * engine's own week info. A failure lists the regions CLDR moved - update the table in datepicker_locale.js.
     */
    public function testTheRegionTableIsCldrs(): void
    {
        $results = $this->runInNode(['parity' => true, 'regions' => ['us', 'GB', 'IR', 'MV', '']]);

        self::assertSame([], $results['mismatches']);
        self::assertSame([0, 1, 6, 5, 1], $results['regions']);
    }

    #[DataProvider('visitorZones')]
    public function testThePickedDayIsShownWithItsWeekdayInThePageLanguage(string $zone): void
    {
        $shown = $this->runInNode(['shown' => [
            ['day' => '2026-10-05', 'lang' => 'en'],
            ['day' => '2026-10-05', 'time' => '18:45', 'lang' => 'en'],
            ['day' => '2026-10-05', 'time' => '09:05', 'lang' => 'en'],
            ['day' => '2026-10-05', 'lang' => 'cs'],
            ['day' => '2026-10-05', 'lang' => 'de'],
            ['day' => '2026-10-05', 'lang' => 'es'],
            ['day' => '2026-10-05', 'lang' => 'fr'],
            ['day' => '2026-10-05', 'time' => '18:45', 'lang' => 'ja'],
            // Around a DST change and the year's end
            ['day' => '2026-03-29', 'time' => '23:30', 'lang' => 'en'],
            ['day' => '2026-12-31', 'lang' => 'en'],
        ]], $zone)['shown'];

        // ICU versions differ on the commas, never on the day
        $expected = [
            '/^Mon,? 5 Oct 2026$/',
            '/^Mon,? 5 Oct 2026,? 18:45$/',
            '/^Mon,? 5 Oct 2026,? 09:05$/',
            '/^po,? 5\. 10\. 2026$/u',
            '/^Mo\.,? 5\. Okt\. 2026$/',
            '/^lun,? 5 oct 2026$/',
            '/^lun\.,? 5 oct\. 2026$/',
            '/^2026年10月5日\(月\),? 18:45$/u',
            '/^Sun,? 29 Mar 2026,? 23:30$/',
            '/^Thu,? 31 Dec 2026$/',
        ];

        self::assertCount(count($expected), $shown);

        foreach ($expected as $i => $pattern) {
            self::assertMatchesRegularExpression($pattern, $shown[$i], sprintf('%s, case %d', $zone, $i));
        }
    }

    /**
     * The real flatpickr with the controller's options, for the fields as the pages render them (value = what the
     * server put in: an edit form or a 422 re-render) - the submitted value stays the form's own format.
     */
    #[DataProvider('visitorZones')]
    public function testTheRealPickerReadsShowsAndSubmitsTheSameDay(string $zone): void
    {
        [$american, $british, $czechPage, $dateTime, $timeOnly, $comparison, $profileFilter] = $this->runInNode(['pickers' => [
            // Event dates, the add-time form: an American visitor on an English page picks the next day
            ['lang' => 'en', 'visitor' => 'en-US', 'options' => ['altInput' => true, 'dateFormat' => 'd.m.Y'], 'value' => '05.10.2026', 'click' => 'October 6, 2026'],
            ['lang' => 'en', 'visitor' => 'en-GB', 'options' => ['altInput' => true, 'dateFormat' => 'd.m.Y', 'maxDate' => 'today'], 'value' => '05.10.2026'],
            ['lang' => 'cs', 'visitor' => 'en-US', 'options' => ['altInput' => true, 'dateFormat' => 'd.m.Y'], 'value' => '05.10.2026'],
            // Round start, registration window
            ['lang' => 'en', 'visitor' => 'en-US', 'options' => ['altInput' => true, 'dateFormat' => 'd.m.Y H:i', 'enableTime' => true, 'time_24hr' => true], 'value' => '05.10.2026 18:45'],
            // A round's start time on a day the form already knows
            ['lang' => 'en', 'visitor' => 'en-US', 'options' => ['noCalendar' => true, 'enableTime' => true, 'time_24hr' => true, 'dateFormat' => 'H:i'], 'value' => '18:45'],
            // The comparison's custom range: its own shown-input classes, inside the modal
            ['lang' => 'de', 'visitor' => 'de-DE', 'options' => ['dateFormat' => 'Y-m-d', 'altInput' => true, 'altInputClass' => 'form-control form-control-sm', 'maxDate' => 'today', 'static' => true], 'value' => '2026-10-05'],
            // The profile's results filter (Live model on the input itself): no second input, shown as typed
            ['lang' => 'en', 'visitor' => 'en-US', 'options' => ['dateFormat' => 'd.m.Y', 'maxDate' => 'today'], 'value' => '05.10.2026'],
        ]], $zone)['pickers'];

        // Sunday first for the American: Monday 5 October sits under "Mon", in the second column...
        self::assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], $american['weekdays']);
        self::assertSame(1, $american['columns']['October 5, 2026']);
        self::assertSame('05.10.2026', $american['value']);
        self::assertMatchesRegularExpression('/^Mon,? 5 Oct 2026$/', (string) $american['shown']);
        // ... and a click on the next day submits that day and shows its weekday
        self::assertSame('06.10.2026', $american['clickedValue'] ?? null);
        self::assertMatchesRegularExpression('/^Tue,? 6 Oct 2026$/', (string) ($american['clickedShown'] ?? ''));

        // Monday first for the British visitor (the grid everybody had before): there the second column - Monday for
        // an American - held Tuesday 6 October
        self::assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $british['weekdays']);
        self::assertSame(0, $british['columns']['October 5, 2026']);
        self::assertSame(1, $british['columns']['October 6, 2026']);
        self::assertSame('05.10.2026', $british['value']);
        self::assertMatchesRegularExpression('/^Mon,? 5 Oct 2026$/', (string) $british['shown']);

        // Czech pages: Monday first and Czech names, whoever comes
        self::assertSame(['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'], $czechPage['weekdays']);
        self::assertSame('05.10.2026', $czechPage['value']);
        self::assertMatchesRegularExpression('/^po,? 5\. 10\. 2026$/u', (string) $czechPage['shown']);

        self::assertSame('05.10.2026 18:45', $dateTime['value']);
        self::assertMatchesRegularExpression('/^Mon,? 5 Oct 2026,? 18:45$/', (string) $dateTime['shown']);

        self::assertSame('18:45', $timeOnly['value']);
        self::assertNull($timeOnly['shown']);

        self::assertSame('2026-10-05', $comparison['value']);
        self::assertMatchesRegularExpression('/^Mo\.,? 5\. Okt\. 2026$/', (string) $comparison['shown']);
        self::assertSame('form-control form-control-sm', $comparison['shownClass']);
        self::assertSame(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'], $comparison['weekdays']);

        self::assertSame('05.10.2026', $profileFilter['value']);
        self::assertNull($profileFilter['shown']);
        self::assertSame('Sun', $profileFilter['weekdays'][0]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     firstDays: list<int>,
     *     regions: list<int>,
     *     mismatches: list<string>,
     *     shown: list<string>,
     *     pickers: list<array{weekdays: list<string>, value: string, shown: null|string, shownClass: null|string, columns: array<string, int>, clickedValue?: string, clickedShown?: null|string}>,
     * }
     */
    private function runInNode(array $input, string $zone = 'UTC'): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/datepicker-harness.mjs'], env: ['TZ' => $zone]);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{firstDays: list<int>, regions: list<int>, mismatches: list<string>, shown: list<string>, pickers: list<array{weekdays: list<string>, value: string, shown: null|string, shownClass: null|string, columns: array<string, int>, clickedValue?: string, clickedShown?: null|string}>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
