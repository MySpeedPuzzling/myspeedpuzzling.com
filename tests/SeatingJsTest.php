<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The seating page's pure half (assets/seating.js) under node: the lists' order and the one write each action sends -
 * docs/features/competitions-management/seating.md. Every action is one AssignTableNumbers call validated as a whole,
 * so what these functions build is exactly what gets applied - or nothing.
 */
final class SeatingJsTest extends TestCase
{
    public function testUnseatedEntriesComeFirstByNameThenTheSeatedByTable(): void
    {
        self::assertSame([
            ['unseated' => ['p:anna', 'p:zofie'], 'seated' => ['p:dan', 'p:ben', 'p:cara']],
        ], $this->runInNode([[
            'op' => 'split',
            'locale' => 'en',
            'entries' => [
                self::entry('ben', 7),
                self::entry('zofie', null, 'Žofie'),
                self::entry('cara', 12),
                self::entry('anna', null),
                self::entry('dan', 2),
            ],
        ]]));
    }

    public function testAnOrderOfTheOrganisersOwnSurvivesOtherChanges(): void
    {
        self::assertSame([
            // Gone: ben; new: eva (no table) on top, fred (table 9) at the seated end
            ['unseated' => ['p:eva', 'p:anna'], 'seated' => ['p:cara', 'p:dan', 'p:fred']],
        ], $this->runInNode([[
            'op' => 'merge',
            'unseated' => ['p:anna'],
            'seated' => ['p:cara', 'p:ben', 'p:dan'],
            'entries' => [self::entry('anna', null), self::entry('cara', 3), self::entry('dan', 1), self::entry('eva', null), self::entry('fred', 9)],
        ]]));
    }

    public function testMoveUpAndDownCrossBetweenTheLists(): void
    {
        $lists = ['unseated' => ['p:a', 'p:b'], 'seated' => ['p:c', 'p:d']];

        self::assertSame([
            ['unseated' => ['p:a', 'p:b'], 'seated' => ['p:d', 'p:c']],
            ['unseated' => ['p:a', 'p:b', 'p:c'], 'seated' => ['p:d']],
            ['unseated' => ['p:a'], 'seated' => ['p:b', 'p:c', 'p:d']],
            ['unseated' => ['p:b', 'p:a'], 'seated' => ['p:c', 'p:d']],
            // The ends stay where they are
            $lists,
            $lists,
        ], $this->runInNode([
            ['op' => 'move', ...$lists, 'ref' => 'p:c', 'direction' => 'down'],
            ['op' => 'move', ...$lists, 'ref' => 'p:c', 'direction' => 'up'],
            ['op' => 'move', ...$lists, 'ref' => 'p:b', 'direction' => 'down'],
            ['op' => 'move', ...$lists, 'ref' => 'p:b', 'direction' => 'up'],
            ['op' => 'move', ...$lists, 'ref' => 'p:a', 'direction' => 'up'],
            ['op' => 'move', ...$lists, 'ref' => 'p:d', 'direction' => 'down'],
        ]));
    }

    public function testRenumberingWritesOnlyWhatChanges(): void
    {
        $entries = [self::entry('a', 101), self::entry('b', 102), self::entry('c', 103), self::entry('d', null), self::entry('e', 104)];

        self::assertSame([
            101,
            [
                // d dragged between a and b, c moved to "No table yet"; a keeps 101
                ['entry' => 'p:d', 'number' => 102],
                ['entry' => 'p:b', 'number' => 103],
                ['entry' => 'p:c', 'number' => null],
            ],
            1,
        ], $this->runInNode([
            ['op' => 'renumberStart', 'seated' => ['p:a', 'p:d', 'p:b', 'p:e'], 'entries' => $entries],
            ['op' => 'renumber', 'unseated' => ['p:c'], 'seated' => ['p:a', 'p:d', 'p:b', 'p:e'], 'entries' => $entries, 'start' => 101],
            ['op' => 'renumberStart', 'seated' => ['p:d'], 'entries' => $entries],
        ]));
    }

    public function testLatecomersSwapsTakeOversAndClearing(): void
    {
        $entries = [self::entry('a', 1), self::entry('b', 7), self::entry('c', null), self::entry('d', null)];

        self::assertSame([
            [['entry' => 'p:c', 'number' => 8], ['entry' => 'p:d', 'number' => 9]],
            [['entry' => 'p:a', 'number' => 7], ['entry' => 'p:b', 'number' => 1]],
            [['entry' => 'p:a', 'number' => null], ['entry' => 'p:c', 'number' => 1]],
            // c typed in table 7 - b takes c's (none)
            [['entry' => 'p:c', 'number' => 7], ['entry' => 'p:b', 'number' => null]],
            [['entry' => 'p:a', 'number' => null], ['entry' => 'p:b', 'number' => null]],
            'p:b',
            null,
        ], $this->runInNode([
            ['op' => 'seatRest', 'unseated' => ['p:c', 'p:d'], 'entries' => $entries],
            ['op' => 'swap', 'a' => $entries[0], 'b' => $entries[1]],
            ['op' => 'swap', 'a' => $entries[0], 'b' => $entries[2]],
            ['op' => 'takeOver', 'entry' => $entries[2], 'number' => 7, 'holder' => $entries[1]],
            ['op' => 'clear', 'entries' => $entries],
            ['op' => 'holder', 'entries' => $entries, 'number' => 7, 'except' => 'p:a'],
            ['op' => 'holder', 'entries' => $entries, 'number' => 7, 'except' => 'p:b'],
        ]));
    }

    public function testAProposalAppliesOnlyToExactlyTheRoundsEntrantsAndCanBeUndone(): void
    {
        $entries = [self::entry('a', 1), self::entry('b', 2), self::entry('c', null)];
        $rows = [['entry' => 'p:b', 'tableNumber' => 1], ['entry' => 'p:a', 'tableNumber' => 2], ['entry' => 'p:c', 'tableNumber' => 3]];
        $assignments = [['entry' => 'p:b', 'number' => 1], ['entry' => 'p:a', 'number' => 2], ['entry' => 'p:c', 'number' => 3]];

        self::assertSame([
            $assignments,
            true,
            false,
            false,
            [['entry' => 'p:b', 'number' => 2], ['entry' => 'p:a', 'number' => 1], ['entry' => 'p:c', 'number' => null]],
        ], $this->runInNode([
            ['op' => 'proposal', 'rows' => $rows, 'entries' => $entries],
            ['op' => 'covers', 'rows' => $rows, 'entries' => $entries],
            // Somebody joined the round meanwhile
            ['op' => 'covers', 'rows' => $rows, 'entries' => [...$entries, self::entry('d', null)]],
            // Somebody left and somebody else joined
            ['op' => 'covers', 'rows' => $rows, 'entries' => [$entries[0], $entries[1], self::entry('d', null)]],
            ['op' => 'undo', 'assignments' => $assignments, 'entries' => $entries],
        ]));
    }

    public function testTypedTableNumbers(): void
    {
        self::assertSame([
            ['number' => null],
            ['number' => 12],
            ['number' => 9999],
            ['error' => true],
            ['error' => true],
            ['error' => true],
            ['error' => true],
        ], $this->runInNode(array_map(static fn (string $text): array => ['op' => 'parse', 'text' => $text], ['  ', ' 12 ', '9999', '0', '10000', '1.5', 'x3'])));
    }

    public function testTheFindBoxMatchesTablesNamesMembersAndCodes(): void
    {
        $team = [
            ...self::entry('t', 12, 'Puzzle Sharks'),
            'members' => [['name' => 'Žofie Nováková', 'playerName' => null, 'playerCode' => 'zofka'], ['name' => 'Ben', 'playerName' => 'Benjamin', 'playerCode' => null]],
        ];

        self::assertSame([true, true, true, true, true, true, false, false, 'zofie novakova'], $this->runInNode([
            ['op' => 'matches', 'entry' => $team, 'query' => '12'],
            ['op' => 'matches', 'entry' => $team, 'query' => 'sharks'],
            ['op' => 'matches', 'entry' => $team, 'query' => 'zofie'],
            ['op' => 'matches', 'entry' => $team, 'query' => '#ZOF'],
            ['op' => 'matches', 'entry' => $team, 'query' => 'benjamin'],
            ['op' => 'matches', 'entry' => $team, 'query' => ''],
            ['op' => 'matches', 'entry' => $team, 'query' => '1'],
            ['op' => 'matches', 'entry' => $team, 'query' => 'anna'],
            ['op' => 'fold', 'text' => ' Žofie NOVÁKOVÁ '],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $id, null|int $tableNumber, null|string $name = null): array
    {
        return [
            'ref' => 'p:' . $id,
            'id' => $id,
            'kind' => 'person',
            'name' => $name ?? $id,
            'displayName' => $name ?? $id,
            'playerName' => null,
            'playerCode' => null,
            'members' => [],
            'tableNumber' => $tableNumber,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<mixed>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/seating-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
