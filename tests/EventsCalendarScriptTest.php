<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The events page calendar builds its months in the browser from the page's index (assets/events_calendar.js,
 * docs/features/events-page/implementation-plan.md 2.B). Runs the real module under node.
 */
final class EventsCalendarScriptTest extends TestCase
{
    public function testMonthsStartOnMondayWithTheRightNumberOfDays(): void
    {
        $months = $this->runInNode(['months' => [
            ['index' => [], 'month' => '2026-11', 'today' => ''],
            ['index' => [], 'month' => '2026-10', 'today' => ''],
            ['index' => [], 'month' => '2027-02', 'today' => ''],
            ['index' => [], 'month' => '2028-02', 'today' => ''],
            ['index' => [], 'month' => '2026-06', 'today' => ''],
        ]])['months'];

        // 1 Nov 2026 is a Sunday, 1 Oct 2026 a Thursday, 1 Feb 2027 a Monday, 1 Feb 2028 a Tuesday, 1 Jun 2026 a Monday
        self::assertSame([6, 3, 0, 1, 0], array_column($months, 'lead'));
        self::assertSame([30, 31, 28, 29, 30], array_column($months, 'dayCount'));
    }

    public function testDaysCarryTheirEntriesAndDotKinds(): void
    {
        $index = [
            self::entry(0, '2026-11-05', null, 'cz', 'upcoming'),
            self::entry(1, '2026-11-05', null, 'online', 'upcoming'),
            self::entry(2, '2026-11-03', null, 'de', 'past'),
            self::entry(3, '2026-11-03', null, 'online', 'past'),
            // A weekend across two months marks both days of it
            self::entry(4, '2026-10-31', '2026-11-01', 'de', 'upcoming'),
            // A series and an undated event are never on the calendar
            ['id' => 5, 'k' => 's', 'n' => 'A series', 'f' => null, 't' => null, 'lr' => false, 'sc' => 'cz', 'st' => null, 'x' => ''],
            self::entry(6, null, null, 'cz', 'tba'),
            self::entry(7, '2026-11-20', null, 'cz', 'live'),
        ];

        $month = $this->runInNode(['months' => [['index' => $index, 'month' => '2026-11', 'today' => '2026-11-20']]])['months'][0];

        self::assertSame([
            '2026-11-01' => ['ids' => [4], 'kinds' => ['in_person'], 'today' => false],
            '2026-11-03' => ['ids' => [2, 3], 'kinds' => ['past'], 'today' => false],
            '2026-11-05' => ['ids' => [0, 1], 'kinds' => ['in_person', 'online'], 'today' => false],
            '2026-11-20' => ['ids' => [7], 'kinds' => ['in_person'], 'today' => true],
        ], $month['days']);

        // The month's rows by first day
        self::assertSame([4, 2, 3, 0, 1, 7], $month['items']);
        self::assertSame([], $month['runs']);
    }

    public function testLongRunningEntriesAreBarsNotDots(): void
    {
        $index = [
            self::entry(0, '2026-09-01', '2027-02-01', 'us', 'live', longRunning: true),
            self::entry(1, '2026-11-10', '2027-01-10', 'online', 'upcoming', longRunning: true),
            self::entry(2, '2026-10-01', '2026-11-20', 'cz', 'live', longRunning: true),
            self::entry(3, '2026-11-12', null, 'cz', 'upcoming'),
        ];

        $month = $this->runInNode(['months' => [['index' => $index, 'month' => '2026-11', 'today' => '']]])['months'][0];

        self::assertSame(['2026-11-12'], array_keys($month['days']));
        self::assertSame([
            ['id' => 0, 'kind' => 'in_person', 'text' => 'all_month'],
            ['id' => 2, 'kind' => 'in_person', 'text' => 'until'],
            ['id' => 1, 'kind' => 'online', 'text' => 'from'],
        ], $month['runs']);
        // Listed below the grid all the same, the ones carried over from earlier months last
        self::assertSame([1, 3, 0, 2], $month['items']);
    }

    public function testScopeAndSearchNarrowTheCalendar(): void
    {
        $index = [
            self::entry(0, '2026-11-05', null, 'cz', 'upcoming', 'Prague Puzzle Cup praha czechia 2026'),
            self::entry(1, '2026-11-05', null, 'online', 'upcoming', 'Harbor Jigsaw Nights online canada 2026'),
            self::entry(2, '2026-11-05', null, 'de', 'upcoming', 'Riverside Puzzle Open hamburg germany 2026'),
            self::entry(3, '2026-11-01', '2027-03-01', 'cz', 'upcoming', 'Long Prague Relay', longRunning: true),
        ];

        $results = $this->runInNode([
            'months' => [
                ['index' => $index, 'scope' => 'cz', 'query' => '', 'month' => '2026-11', 'today' => ''],
                ['index' => $index, 'scope' => 'online', 'query' => '', 'month' => '2026-11', 'today' => ''],
                ['index' => $index, 'scope' => 'all', 'query' => 'hamburg', 'month' => '2026-11', 'today' => ''],
                ['index' => $index, 'scope' => 'cz', 'query' => 'hamburg', 'month' => '2026-11', 'today' => ''],
            ],
            'dayIds' => [
                ['index' => $index, 'scope' => 'all', 'query' => '', 'day' => '2026-11-05'],
                ['index' => $index, 'scope' => 'cz', 'query' => '', 'day' => '2026-11-05'],
                // Long-running occurrences are never picked through a day
                ['index' => $index, 'scope' => 'cz', 'query' => '', 'day' => '2026-11-20'],
            ],
        ]);

        self::assertSame([3, 0], $results['months'][0]['items']);
        self::assertSame([1], $results['months'][1]['items']);
        self::assertSame([2], $results['months'][2]['items']);
        self::assertSame([], $results['months'][3]['items']);
        self::assertSame([[0, 1, 2], [0], []], $results['dayIds']);
    }

    public function testMonthArithmeticAndParsing(): void
    {
        $results = $this->runInNode([
            'shifted' => [
                ['month' => '2026-12', 'delta' => 1],
                ['month' => '2026-01', 'delta' => -1],
                ['month' => '2026-10', 'delta' => -22],
                ['month' => '2026-10', 'delta' => 0],
            ],
            'parsed' => ['2026-11', '2026-13', '2026-00', 'november', '', '2026-1'],
            'strongest' => [['past', 'online', 'in_person'], ['past', 'online'], ['past'], []],
        ]);

        self::assertSame(['2027-01', '2025-12', '2024-12', '2026-10'], $results['shifted']);
        self::assertSame([['year' => 2026, 'month0' => 10], null, null, null, null, null], $results['parsed']);
        self::assertSame(['in_person', 'online', 'past', null], $results['strongest']);
    }

    public function testWeekdaysAreLocalisedAndStartOnMonday(): void
    {
        $weekdays = $this->runInNode(['weekdays' => [['locale' => 'en-GB'], ['locale' => 'cs']]])['weekdays'];

        self::assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $weekdays[0]);
        self::assertSame('po', $weekdays[1][0]);
        self::assertSame('ne', $weekdays[1][6]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(int $id, null|string $from, null|string $to, string $scopeKey, string $status, string $text = '', bool $longRunning = false): array
    {
        return [
            'id' => $id,
            'k' => 'e',
            'n' => 'Event ' . $id,
            'en' => null,
            'f' => $from,
            't' => $to,
            'lr' => $longRunning,
            'sc' => $scopeKey,
            'st' => $status,
            'x' => SearchText::fold($text !== '' ? $text : 'event ' . $id),
        ];
    }

    /**
     * @param array<string, list<mixed>> $input
     *
     * @return array{months: list<array{lead: int, dayCount: int, days: array<string, array{ids: list<int>, kinds: list<string>, today: bool}>, runs: list<array{id: int, kind: string, text: string}>, items: list<int>}>, dayIds: list<list<int>>, shifted: list<string>, parsed: list<null|array{year: int, month0: int}>, strongest: list<null|string>, weekdays: list<list<string>>}
     */
    private function runInNode(array $input): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/events-calendar-harness.mjs']);
        $process->setInput(json_encode($input + ['months' => [], 'dayIds' => [], 'shifted' => [], 'parsed' => [], 'strongest' => [], 'weekdays' => []], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{months: list<array{lead: int, dayCount: int, days: array<string, array{ids: list<int>, kinds: list<string>, today: bool}>, runs: list<array{id: int, kind: string, text: string}>, items: list<int>}>, dayIds: list<list<int>>, shifted: list<string>, parsed: list<null|array{year: int, month0: int}>, strongest: list<null|string>, weekdays: list<list<string>>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
