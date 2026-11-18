<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SandboxDemoEvent;
use SpeedPuzzling\Web\Value\SandboxDemoGroup;
use SpeedPuzzling\Web\Value\SandboxDemoRound;

/**
 * The two made-up events Jan demos to organisers on the sandbox (docs/features/sandbox/README.md "Demo events"): a
 * World-Series-like championship and a winter-carnival-like weekend. Invented names, people and results - modelled on
 * the formats only (world-series-rules.md, shared-puzzles-and-divisions.md), never on real data.
 *
 * Day 0 is the demo day (--date): the first blocks happened the day before and are published, the second blocks run
 * on the demo day with some results in.
 */
final readonly class SandboxDemoEventDefinitions
{
    public const string SPWS_SLUG = 'speed-puzzle-world-series-demo';
    public const string CARNIVAL_SLUG = 'winter-carnival-jigsaw-demo';

    public const string SENIOR = 'Senior 55+';
    public const string JUNIOR = 'Junior under 16';
    public const string REGULAR = 'Regular';

    /**
     * @return list<SandboxDemoEvent>
     */
    public static function all(): array
    {
        return [self::worldSeries(), self::winterCarnival()];
    }

    public static function worldSeries(): SandboxDemoEvent
    {
        $published = SandboxDemoRound::PUBLISHED;
        $running = SandboxDemoRound::RUNNING;
        $planned = SandboxDemoRound::PLANNED;
        $duo = RoundCategory::Duo;
        $team = RoundCategory::Team;
        $solo = RoundCategory::Solo;

        $rounds = [
            new SandboxDemoRound('i1', 'Individual - Challenge 1', $solo, -1, '08:30', 75, 500, firstTable: 201, state: $published, baseSeconds: 2500),
            new SandboxDemoRound('p12', 'Pairs - Challenges 1 + 2', $duo, -1, '10:30', 90, 500, puzzles: 2, solveAll: true, labels: ['Challenge 1', 'Challenge 2'], firstTable: 1, state: $published, baseSeconds: 1750),
            new SandboxDemoRound('t12', 'Teams - Challenges 1 + 2', $team, -1, '14:00', 90, 1000, puzzles: 2, solveAll: true, labels: ['Challenge 1', 'Challenge 2'], firstTable: 101, state: $published, baseSeconds: 2050),
            new SandboxDemoRound('i2', 'Individual - Challenge 2', $solo, 0, '08:30', 75, 500, tablesFrom: 'i1', state: $running, baseSeconds: 2500),
            new SandboxDemoRound('p34', 'Pairs - Challenges 3 + 4', $duo, 0, '10:30', 90, 500, puzzles: 2, solveAll: true, labels: ['Challenge 3', 'Challenge 4'], tablesFrom: 'p12', state: $running, baseSeconds: 1750),
            new SandboxDemoRound('t34', 'Teams - Challenges 3 + 4', $team, 0, '14:00', 90, 1000, puzzles: 2, solveAll: true, labels: ['Challenge 3', 'Challenge 4'], tablesFrom: 't12', state: $running, baseSeconds: 2050),
            new SandboxDemoRound('p5', 'Pairs - Challenge 5', $duo, 0, '16:30', 45, 250, labels: ['Challenge 5'], unboxed: true, woodenPuzzle: true, tablesFrom: 'p12', state: $planned, baseSeconds: 1150),
            new SandboxDemoRound('i3', 'Individual - Challenge 3', $solo, 1, '08:30', 75, 500, tablesFrom: 'i1', state: $planned, baseSeconds: 2500),
            new SandboxDemoRound('i4', 'Individual - Challenge 4', $solo, 1, '10:30', 75, 500, tablesFrom: 'i1', state: $planned, baseSeconds: 2500),
            new SandboxDemoRound('i5', 'Individual - Challenge 5', $solo, 1, '13:00', 75, 500, tablesFrom: 'i1', state: $planned, baseSeconds: 2500),
        ];

        return new SandboxDemoEvent(
            slug: self::SPWS_SLUG,
            name: 'Speed Puzzle World Series - demo',
            code: 'spws',
            location: 'Toronto',
            countryCode: 'ca',
            timezone: 'America/Toronto',
            description: 'A made-up demo event on the MySpeedPuzzling sandbox: three championships (individual, pairs, teams of 4), back-to-back challenge blocks, one cumulative standing per championship. Every participant and result is invented.',
            rounds: $rounds,
            groups: [
                new SandboxDemoGroup('pairs', 24, 2, ['p12', 'p34', 'p5'], teamNames: SandboxDemoGroup::NAMES_FIRST_NAMES),
                new SandboxDemoGroup('teams', 12, 4, ['t12', 't34']),
                new SandboxDemoGroup('individual', 30, 1, ['i1', 'i2', 'i3', 'i4', 'i5']),
            ],
            standings: true,
            displays: true,
        );
    }

    public static function winterCarnival(): SandboxDemoEvent
    {
        $published = SandboxDemoRound::PUBLISHED;
        $running = SandboxDemoRound::RUNNING;
        $team = RoundCategory::Team;

        $rounds = [
            // Saturday
            new SandboxDemoRound('challenge', '500 Teams CHALLENGE', $team, -1, '09:00', 90, 500, firstTable: 1, state: $published, baseSeconds: 1500),
            new SandboxDemoRound('t1000', '1000 Teams', $team, -1, '11:30', 180, 1000, tablesFrom: 'challenge', state: $published, baseSeconds: 3000),
            new SandboxDemoRound('open1', '500 Teams OPEN #1', $team, -1, '15:30', 120, 500, sharesPuzzleOf: 'challenge', firstTable: 101, state: $published, baseSeconds: 2300),
            // Sunday
            new SandboxDemoRound('pairs', '500 PAIRS', RoundCategory::Duo, 0, '09:00', 120, 500, firstTable: 201, state: $published, baseSeconds: 2900, divisions: [self::REGULAR, self::SENIOR]),
            new SandboxDemoRound('solo', '500 SOLO', RoundCategory::Solo, 0, '11:30', 120, 500, firstTable: 301, state: $published, baseSeconds: 3700, divisions: [self::REGULAR, self::SENIOR, self::JUNIOR]),
            new SandboxDemoRound('open2', '500 Teams OPEN #2', $team, 0, '15:00', 120, 500, sharesPuzzleOf: 'challenge', firstTable: 401, state: $running, baseSeconds: 2300),
            new SandboxDemoRound('family', '500 FAMILY', $team, 0, '15:00', 120, 500, firstTable: 501, state: $running, baseSeconds: 3000),
        ];

        return new SandboxDemoEvent(
            slug: self::CARNIVAL_SLUG,
            name: 'Winter Carnival Jigsaw - demo',
            code: 'wcj',
            location: 'Saint Paul, MN',
            countryCode: 'us',
            timezone: 'America/Chicago',
            description: 'A made-up demo event on the MySpeedPuzzling sandbox: a weekend of team rounds (three of them on one shared puzzle), pairs and solo rounds with age divisions and a family round. Every participant and result is invented.',
            rounds: $rounds,
            groups: [
                new SandboxDemoGroup('a', 20, 4, ['challenge', 't1000']),
                new SandboxDemoGroup('b', 20, 4, ['open1']),
                new SandboxDemoGroup('c', 12, 4, ['open2']),
                new SandboxDemoGroup('family', 8, 4, ['family'], teamNames: SandboxDemoGroup::NAMES_FAMILY),
                new SandboxDemoGroup('pairs', 16, 2, ['pairs'], reuseOf: 'a', teamNames: SandboxDemoGroup::NAMES_FIRST_NAMES, divisions: [self::SENIOR => 4]),
                new SandboxDemoGroup('solo', 25, 1, ['solo'], reuseOf: 'b', divisions: [self::JUNIOR => 6, self::SENIOR => 4]),
            ],
            divisions: [
                ['name' => self::REGULAR, 'short' => 'REG'],
                ['name' => self::SENIOR, 'short' => 'S55'],
                ['name' => self::JUNIOR, 'short' => 'U16'],
            ],
        );
    }
}
