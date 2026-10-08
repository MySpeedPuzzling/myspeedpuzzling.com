<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The events page filters and searches in the browser over the index the server renders
 * (assets/events_index.js, docs/features/events-page/implementation-plan.md 1.7). Runs the real module under node.
 */
final class EventsIndexScriptTest extends TestCase
{
    public function testScopeMatching(): void
    {
        $results = $this->runInNode(['scopes' => [
            ['scopeKey' => 'cz', 'scope' => 'all'],
            ['scopeKey' => 'online', 'scope' => 'all'],
            ['scopeKey' => 'cz', 'scope' => 'cz'],
            ['scopeKey' => 'online', 'scope' => 'online'],
            // An online series with a country is not in that country's view
            ['scopeKey' => 'online', 'scope' => 'ca'],
            ['scopeKey' => 'de', 'scope' => 'cz'],
            ['scopeKey' => '', 'scope' => 'cz'],
            ['scopeKey' => 'cz', 'scope' => 'online'],
        ]])['scopes'];

        self::assertSame([true, true, true, true, false, false, false, false], $results);
    }

    public function testEveryTypedWordMustMatchAndAbbreviationsFindTheirNames(): void
    {
        $entry = ['x' => SearchText::fold('World Jigsaw Puzzle Championship 2026 Valencia Spain España 2026')];
        $online = ['x' => SearchText::fold('Session 3 Harbor Jigsaw Nights online Kanada Canada 2026')];

        $queries = [
            ['query' => '', 'entry' => $entry],
            ['query' => 'valencia', 'entry' => $entry],
            ['query' => 'WJPC 2026', 'entry' => $entry],
            ['query' => 'wjpc spain', 'entry' => $entry],
            ['query' => 'españa', 'entry' => $entry],
            ['query' => 'valencia 2025', 'entry' => $entry],
            ['query' => 'ejpc', 'entry' => $entry],
            ['query' => 'harbor online', 'entry' => $online],
            ['query' => 'harbor prague', 'entry' => $online],
        ];

        self::assertSame(
            [true, true, true, true, true, false, false, true, false],
            $this->runInNode(['queries' => $queries])['queries'],
        );
    }

    /**
     * The server folds the index (SearchText::fold()), the browser only what is typed - accents and letters like ø, ß
     * must meet in the middle
     */
    public function testAPhpFoldedEntryIsFoundByATypedQueryWithAccents(): void
    {
        $entry = ['x' => SearchText::fold('Ærø Straße Puzzle Cup Søby Dánsko Denmark 2025')];

        self::assertSame([true, true, true, true, false], $this->runInNode(['queries' => [
            ['query' => 'Ærø', 'entry' => $entry],
            ['query' => 'straße', 'entry' => $entry],
            ['query' => 'SØBY dánsko', 'entry' => $entry],
            ['query' => 'aero strasse', 'entry' => $entry],
            ['query' => 'sobyx', 'entry' => $entry],
        ]])['queries']);
    }

    public function testDaysAndMonthsIncludingLongRunningEntries(): void
    {
        $oneDay = ['f' => '2026-11-05', 't' => null];
        $weekend = ['f' => '2026-10-31', 't' => '2026-11-01'];
        $longRunning = ['f' => '2026-09-01', 't' => '2027-02-01', 'lr' => true];
        $series = ['f' => null, 't' => null];

        $results = $this->runInNode([
            'days' => [
                ['entry' => $oneDay, 'day' => '2026-11-05'],
                ['entry' => $oneDay, 'day' => '2026-11-06'],
                ['entry' => $weekend, 'day' => '2026-11-01'],
                ['entry' => $longRunning, 'day' => '2026-12-24'],
                ['entry' => $longRunning, 'day' => '2027-02-02'],
                ['entry' => $series, 'day' => '2026-11-05'],
            ],
            'months' => [
                ['entry' => $oneDay, 'year' => 2026, 'month0' => 10],
                ['entry' => $oneDay, 'year' => 2026, 'month0' => 9],
                ['entry' => $weekend, 'year' => 2026, 'month0' => 9],
                ['entry' => $weekend, 'year' => 2026, 'month0' => 10],
                ['entry' => $longRunning, 'year' => 2027, 'month0' => 0],
                ['entry' => $longRunning, 'year' => 2027, 'month0' => 2],
                ['entry' => $series, 'year' => 2026, 'month0' => 10],
            ],
        ]);

        self::assertSame([true, false, true, true, false, false], $results['days']);
        self::assertSame([true, false, true, true, true, false, false], $results['months']);
    }

    public function testDaysAreFormattedInThePageLanguage(): void
    {
        $results = $this->runInNode(['formatted' => [
            ['entry' => ['f' => '2026-03-03', 't' => null], 'locale' => 'en-GB', 'withYear' => false],
            ['entry' => ['f' => '2026-03-03', 't' => null], 'locale' => 'en-GB', 'withYear' => true],
            ['entry' => ['f' => null, 't' => null], 'locale' => 'en-GB', 'withYear' => false],
        ]])['formatted'];

        self::assertSame(['3 Mar', '3 Mar 2026', ''], $results);
    }

    /**
     * Server and browser write the same dates in all six languages (EventsPageDates, formatDate()/formatDayRange()) -
     * the skeletons both sides use; weekday ones are server-only (ICU versions differ on their commas)
     */
    public function testServerAndBrowserWriteTheSameDates(): void
    {
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
        $dates = new EventsPageDates($translator);
        $utc = new DateTimeZone('UTC');

        $cases = [];

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $lang) {
            foreach (['yMMMM', 'yMMM', 'MMMd', 'yMMMd', 'MMM'] as $skeleton) {
                $cases[] = ['from' => '2026-10-12', 'lang' => $lang, 'skeleton' => $skeleton];
            }

            foreach (
                [
                ['2026-10-10', '2026-10-11', 'MMMd'],
                ['2025-10-10', '2025-10-11', 'yMMMd'],
                ['2025-10-30', '2025-11-02', 'yMMMd'],
                ['2025-10-30', '2025-11-02', 'MMMd'],
                ['2025-12-30', '2026-01-02', 'yMMMd'],
                ['2025-03-03', '2025-06-24', 'MMM'],
                ['2025-03-03', '2025-06-24', 'yMMM'],
                ['2025-03-03', '2025-03-24', 'MMM'],
                ] as [$from, $to, $skeleton]
            ) {
                $cases[] = ['from' => $from, 'to' => $to, 'lang' => $lang, 'skeleton' => $skeleton];
            }
        }

        $expected = array_map(static function (array $case) use ($dates, $utc): string {
            $from = new DateTimeImmutable($case['from'], $utc);

            return isset($case['to'])
                ? $dates->range($from, new DateTimeImmutable($case['to'], $utc), $case['skeleton'], $case['lang'])
                : $dates->format($from, $case['skeleton'], $case['lang']);
        }, $cases);

        self::assertSame($expected, $this->runInNode(['dates' => $cases])['dates']);

        // And they read like the language writes them
        self::assertSame(
            ['October 2026', '10–11 Oct', 'Oktober 2026', '10.–11. Okt.', 'říjen 2026', '30. 10. – 2. 11. 2025', '2026年10月', '2025年10月10日–11日'],
            [
                $dates->format(new DateTimeImmutable('2026-10-01', $utc), 'yMMMM', 'en'),
                $dates->range(new DateTimeImmutable('2026-10-10', $utc), new DateTimeImmutable('2026-10-11', $utc), 'MMMd', 'en'),
                $dates->format(new DateTimeImmutable('2026-10-01', $utc), 'yMMMM', 'de'),
                $dates->range(new DateTimeImmutable('2026-10-10', $utc), new DateTimeImmutable('2026-10-11', $utc), 'MMMd', 'de'),
                $dates->format(new DateTimeImmutable('2026-10-01', $utc), 'yMMMM', 'cs'),
                $dates->range(new DateTimeImmutable('2025-10-30', $utc), new DateTimeImmutable('2025-11-02', $utc), 'yMMMd', 'cs'),
                $dates->format(new DateTimeImmutable('2026-10-01', $utc), 'yMMMM', 'ja'),
                $dates->range(new DateTimeImmutable('2025-10-10', $utc), new DateTimeImmutable('2025-10-11', $utc), 'yMMMd', 'ja'),
            ],
        );
    }

    /**
     * The detail pages' times (detail-pages.md "Times and time zones"): the browser writes a start time like the server
     * (EventsPageDates::time()) in all six languages - fixed instants only, never the fixtures' moving dates
     */
    public function testServerAndBrowserWriteTheSameTimes(): void
    {
        $dates = new EventsPageDates(self::translator());
        $cases = [];

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $lang) {
            foreach ([['2026-06-17T02:00:00Z', 'America/New_York'], ['2026-06-17T02:05:00Z', 'Europe/Prague'], ['2026-12-31T23:30:00Z', 'Asia/Tokyo']] as [$instant, $zone]) {
                $cases[] = ['instant' => $instant, 'zone' => $zone, 'lang' => $lang];
            }
        }

        $expected = array_map(
            static fn (array $case): string => $dates->time(new DateTimeImmutable($case['instant']), $case['zone'], $case['lang']),
            $cases,
        );

        self::assertSame($expected, $this->runInNode(['times' => $cases])['times']);
        self::assertSame(['22:00', '04:05', '08:30'], array_slice($expected, 0, 3));
    }

    public function testTheVisitorsOwnTime(): void
    {
        // 22:00 on 16 June in New York
        $instant = '2026-06-17T02:00:00Z';

        $results = $this->runInNode(['visitor' => [
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => 'America/New_York'],
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => 'Europe/Prague'],
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => 'America/Los_Angeles'],
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => 'Asia/Tokyo'],
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => 'Not/A_Zone'],
            ['instant' => $instant, 'eventZone' => 'America/New_York', 'lang' => 'en', 'visitorZone' => ''],
            // 01:00 in Prague is 19:00 the day before in New York
            ['instant' => '2026-06-16T23:00:00Z', 'eventZone' => 'Europe/Prague', 'lang' => 'en', 'visitorZone' => 'America/New_York'],
        ]])['visitor'];

        self::assertNull($results[0], 'the same zone - nothing added');
        self::assertSame(['time' => '04:00', 'zone' => 'Central European Time', 'dayShift' => 1], $results[1]);
        self::assertSame(['time' => '19:00', 'zone' => 'Pacific Time', 'dayShift' => 0], $results[2]);
        self::assertNotNull($results[3]);
        self::assertSame(1, $results[3]['dayShift']);
        self::assertSame('11:00', $results[3]['time']);
        self::assertNull($results[4], 'an invalid zone');
        self::assertNull($results[5], 'no zone');
        self::assertSame(['time' => '19:00', 'zone' => 'Eastern Time', 'dayShift' => -1], $results[6]);
    }

    public function testZoneLabels(): void
    {
        $results = $this->runInNode(['zones' => [
            ['zone' => 'Europe/Prague', 'lang' => 'en', 'instant' => '2026-06-17T02:00:00Z'],
            ['zone' => 'America/New_York', 'lang' => 'en', 'instant' => '2026-06-17T02:00:00Z'],
            ['zone' => 'Europe/Prague', 'lang' => 'de', 'instant' => '2026-06-17T02:00:00Z'],
            // No generic name of its own: the zone id's last segment, not an offset
            ['zone' => 'Etc/GMT-3', 'lang' => 'en', 'instant' => '2026-06-17T02:00:00Z'],
            ['zone' => 'Nowhere/Port_Town', 'lang' => 'en', 'instant' => '2026-06-17T02:00:00Z'],
        ]])['zones'];

        $dates = new EventsPageDates(self::translator());

        self::assertSame(['Central European Time', 'Eastern Time'], array_slice($results, 0, 2));
        // The same names as the server's (ICU)
        self::assertSame([$dates->zoneName('Europe/Prague', 'en'), $dates->zoneName('America/New_York', 'en'), $dates->zoneName('Europe/Prague', 'de')], array_slice($results, 0, 3));
        self::assertSame(['GMT-3', 'Port Town'], array_slice($results, 3, 2));
    }

    private static function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    /**
     * @param array<string, list<array<string, mixed>>> $input
     *
     * @return array{scopes: list<bool>, queries: list<bool>, days: list<bool>, months: list<bool>, formatted: list<string>, dates: list<string>, times: list<string>, zones: list<string>, visitor: list<null|array{time: string, zone: string, dayShift: int}>}
     */
    private function runInNode(array $input): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/events-index-harness.mjs']);
        $process->setInput(json_encode($input + ['scopes' => [], 'queries' => [], 'days' => [], 'months' => [], 'formatted' => [], 'dates' => [], 'times' => [], 'zones' => [], 'visitor' => []], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{scopes: list<bool>, queries: list<bool>, days: list<bool>, months: list<bool>, formatted: list<string>, dates: list<string>, times: list<string>, zones: list<string>, visitor: list<null|array{time: string, zone: string, dayShift: int}>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
