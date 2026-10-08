<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SearchText;
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
     * @param array<string, list<array<string, mixed>>> $input
     *
     * @return array{scopes: list<bool>, queries: list<bool>, days: list<bool>, months: list<bool>, formatted: list<string>}
     */
    private function runInNode(array $input): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/events-index-harness.mjs']);
        $process->setInput(json_encode($input + ['scopes' => [], 'queries' => [], 'days' => [], 'months' => [], 'formatted' => []], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{scopes: list<bool>, queries: list<bool>, days: list<bool>, months: list<bool>, formatted: list<string>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
