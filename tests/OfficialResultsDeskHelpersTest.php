<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The results desk's browser logic, executed by node (tests/official-results-desk-harness.mjs):
 * - the qualification helpers only pre-select - Top N with the entries tied at the cut, best of each country with
 *   pairs/teams counting for every member's country and entries without a country left to the organiser;
 * - the diff a helper applies, and "Seat them now" numbering after advancing;
 * - PendingChanges: nothing looks saved until the server answered `applied`/`unchanged` for exactly that value, the
 *   `from` sent is what the organiser saw, conflicts keep both values, replays keep their change id.
 */
final class OfficialResultsDeskHelpersTest extends TestCase
{
    private const array ENTRIES = [
        ['ref' => 'A', 'rank' => 1, 'qualified' => true, 'countries' => ['cz']],
        ['ref' => 'B', 'rank' => 2, 'qualified' => false, 'countries' => ['de']],
        ['ref' => 'C', 'rank' => 2, 'qualified' => true, 'countries' => ['us']],
        ['ref' => 'D', 'rank' => 4, 'qualified' => false, 'countries' => ['cz']],
        ['ref' => 'E', 'rank' => 5, 'qualified' => false, 'countries' => ['cz']],
        ['ref' => 'F', 'rank' => null, 'qualified' => false, 'countries' => ['sk']],
        ['ref' => 'G', 'rank' => null, 'qualified' => false, 'countries' => ['cz']],
        ['ref' => 'H', 'rank' => 6, 'qualified' => false, 'countries' => []],
        ['ref' => 'T', 'rank' => 7, 'qualified' => false, 'countries' => ['cz', 'de']],
    ];

    public function testTopNProposesByRankAndHighlightsTheTieAtTheCut(): void
    {
        [$three, $two, $four, $none, $all] = $this->runInNode([
            ['fn' => 'topN', 'entries' => self::ENTRIES, 'count' => 3],
            ['fn' => 'topN', 'entries' => self::ENTRIES, 'count' => 2],
            ['fn' => 'topN', 'entries' => self::ENTRIES, 'count' => 4],
            ['fn' => 'topN', 'entries' => self::ENTRIES, 'count' => 0],
            ['fn' => 'topN', 'entries' => self::ENTRIES, 'count' => 100],
        ]);

        self::assertSame(['selected' => ['A', 'B', 'C'], 'tiedAtCut' => []], $three);
        // Place 2 is shared - both proposed, both highlighted: the organiser decides
        self::assertSame(['selected' => ['A', 'B', 'C'], 'tiedAtCut' => ['B', 'C']], $two);
        self::assertSame(['selected' => ['A', 'B', 'C', 'D'], 'tiedAtCut' => []], $four);
        self::assertSame(['selected' => [], 'tiedAtCut' => []], $none);
        // Did not start and no result yet are never proposed
        self::assertSame(['selected' => ['A', 'B', 'C', 'D', 'E', 'H', 'T'], 'tiedAtCut' => []], $all);
    }

    public function testBestOfEachCountryCountsPairsForEveryMembersCountryAndLeavesNoCountryToTheOrganiser(): void
    {
        $withTie = [...self::ENTRIES, ['ref' => 'I', 'rank' => 4, 'qualified' => false, 'countries' => ['cz']]];

        [$one, $two, $tie] = $this->runInNode([
            ['fn' => 'bestOfEachCountry', 'entries' => self::ENTRIES, 'perCountry' => 1],
            ['fn' => 'bestOfEachCountry', 'entries' => self::ENTRIES, 'perCountry' => 2],
            ['fn' => 'bestOfEachCountry', 'entries' => $withTie, 'perCountry' => 2],
        ]);

        self::assertSame([
            'countries' => [
                ['country' => 'cz', 'selected' => ['A'], 'tiedAtCut' => []],
                ['country' => 'de', 'selected' => ['B'], 'tiedAtCut' => []],
                ['country' => 'us', 'selected' => ['C'], 'tiedAtCut' => []],
            ],
            'selected' => ['A', 'B', 'C'],
            'tiedAtCut' => [],
            'withoutCountry' => ['H'],
        ], $one);

        // The pair T (cz + de) is Germany's second best
        self::assertSame(['A', 'D', 'B', 'T', 'C'], self::path($two, 'selected'));

        self::assertSame(['country' => 'cz', 'selected' => ['A', 'D', 'I'], 'tiedAtCut' => ['D', 'I']], self::path($tie, 'countries', 0));
        self::assertSame(['D', 'I'], self::path($tie, 'tiedAtCut'));
    }

    public function testTheDiffMarksTheSelectionAndUnmarksTheRestUnlessKept(): void
    {
        [$replace, $keep] = $this->runInNode([
            ['fn' => 'qualificationDiff', 'entries' => self::ENTRIES, 'selected' => ['A', 'B'], 'keepOthers' => false],
            ['fn' => 'qualificationDiff', 'entries' => self::ENTRIES, 'selected' => ['A', 'B'], 'keepOthers' => true],
        ]);

        self::assertSame(['mark' => ['B'], 'unmark' => ['C']], $replace);
        self::assertSame(['mark' => ['B'], 'unmark' => []], $keep);
    }

    public function testSeatThemNowGivesTheLowestFreeNumbersInSeedOrder(): void
    {
        $target = [
            ['ref' => 'x', 'tableNumber' => 1],
            ['ref' => 'n1', 'tableNumber' => null],
            ['ref' => 'n2', 'tableNumber' => null],
            ['ref' => 'n3', 'tableNumber' => 3],
            ['ref' => 'n4', 'tableNumber' => null],
            ['ref' => 'y', 'tableNumber' => null],
        ];

        [$fastest, $slowest, $empty] = $this->runInNode([
            ['fn' => 'seatAdvanced', 'targetEntries' => $target, 'refs' => ['n1', 'n2', 'n3', 'n4', 'gone']],
            ['fn' => 'seatAdvanced', 'targetEntries' => $target, 'refs' => ['n1', 'n2', 'n3', 'n4', 'gone'], 'slowestFirst' => true],
            ['fn' => 'seatAdvanced', 'targetEntries' => [['ref' => 'a', 'tableNumber' => null], ['ref' => 'b', 'tableNumber' => null]], 'refs' => ['b', 'a']],
        ]);

        // x keeps 1, n3 (seated meanwhile) keeps 3, y was there before - not part of the plan
        self::assertSame([['entry' => 'n1', 'from' => null, 'number' => 2], ['entry' => 'n2', 'from' => null, 'number' => 4], ['entry' => 'n4', 'from' => null, 'number' => 5]], $fastest);
        self::assertSame([['entry' => 'n4', 'from' => null, 'number' => 2], ['entry' => 'n2', 'from' => null, 'number' => 4], ['entry' => 'n1', 'from' => null, 'number' => 5]], $slowest);
        self::assertSame([['entry' => 'b', 'from' => null, 'number' => 1], ['entry' => 'a', 'from' => null, 'number' => 2]], $empty);
    }

    public function testAChangeIsSavedOnlyWhenTheServerSaysSo(): void
    {
        [$applied, $unchanged, $backToBase] = $this->runInNode([
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'result', 'to' => ['seconds' => 100], 'server' => null],
                ['op' => 'take'],
                ['op' => 'snapshot'],
                ['op' => 'settle', 'index' => 0, 'status' => 'applied'],
                ['op' => 'snapshot'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'unchanged'],
                ['op' => 'snapshot'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'set', 'ref' => 'r1', 'field' => 'qualified', 'to' => false, 'server' => false],
                ['op' => 'snapshot'],
            ]],
        ]);

        self::assertSame([['clientChangeId' => 'c1', 'entry' => 'r1', 'field' => 'result', 'from' => null, 'to' => ['seconds' => 100]]], self::path($applied, 0, 'taken'));
        self::assertSame('sending', self::path($applied, 1, 'cells', 0, 'status'));
        self::assertSame([], self::path($applied, 2, 'cells'));
        self::assertSame([], self::path($unchanged, 1, 'cells'));
        self::assertSame([], self::path($backToBase, 0, 'cells'));
    }

    public function testNothingIsSavedWhenTheRequestFailsAndAReplayKeepsItsChangeId(): void
    {
        [$offline, $refused, $wholeRequestRefused] = $this->runInNode([
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'table_number', 'to' => 12, 'server' => null],
                ['op' => 'take'],
                ['op' => 'retryInFlight'],
                ['op' => 'snapshot'],
                ['op' => 'take'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'table_number', 'to' => 3, 'server' => null],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'rejected', 'message' => 'Another entrant of this round has this table number.'],
                ['op' => 'snapshot'],
                ['op' => 'retry', 'ref' => 'r1', 'field' => 'table_number'],
                ['op' => 'snapshot'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'result', 'to' => ['didNotStart' => true], 'server' => null],
                ['op' => 'take'],
                ['op' => 'failInFlight', 'message' => 'refused'],
                ['op' => 'snapshot'],
            ]],
        ]);

        self::assertSame('queued', self::path($offline, 1, 'cells', 0, 'status'));
        self::assertSame('c1', self::path($offline, 2, 'taken', 0, 'clientChangeId'));

        self::assertSame('error', self::path($refused, 1, 'cells', 0, 'status'));
        self::assertSame('Another entrant of this round has this table number.', self::path($refused, 1, 'cells', 0, 'message'));
        self::assertSame(['queued' => 0, 'sending' => 0, 'conflict' => 0, 'error' => 1, 'total' => 1], self::path($refused, 1, 'counts'));
        self::assertSame('queued', self::path($refused, 2, 'cells', 0, 'status'));

        self::assertSame('error', self::path($wholeRequestRefused, 1, 'cells', 0, 'status'));
        self::assertSame('refused', self::path($wholeRequestRefused, 1, 'cells', 0, 'message'));
    }

    /**
     * review2-b m6: 5 → 6 is refused while B holds 6 - "Swap them" sends both changes in one request, so the server
     * checks the numbers after the whole set.
     */
    public function testASwapOfTwoTablesGoesInOneRequest(): void
    {
        [$swap] = $this->runInNode([
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'a', 'field' => 'table_number', 'to' => 6, 'server' => 5],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'rejected', 'reason' => 'table_number_taken', 'message' => 'Another entrant of this round has this table number.'],
                // What "Swap them" does: the holder gets A's old number, A's refused change goes again
                ['op' => 'set', 'ref' => 'b', 'field' => 'table_number', 'to' => 5, 'server' => 6],
                ['op' => 'retry', 'ref' => 'a', 'field' => 'table_number'],
                ['op' => 'take'],
            ]],
        ]);

        self::assertSame([
            ['clientChangeId' => 'c1', 'entry' => 'a', 'field' => 'table_number', 'from' => 5, 'to' => 6],
            ['clientChangeId' => 'c2', 'entry' => 'b', 'field' => 'table_number', 'from' => 6, 'to' => 5],
        ], self::path($swap, 1, 'taken'));
    }

    public function testAConflictKeepsBothValuesUntilTheOrganiserDecides(): void
    {
        [$keepMine, $takeTheirs, $typedOver] = $this->runInNode([
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'result', 'to' => ['seconds' => 100], 'server' => null],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'conflict', 'current' => ['seconds' => 90], 'enteredBy' => ['playerId' => 'p', 'name' => 'Eva']],
                ['op' => 'snapshot', 'shown' => ['ref' => 'r1', 'field' => 'result', 'server' => ['seconds' => 90]]],
                ['op' => 'keepMine', 'ref' => 'r1', 'field' => 'result'],
                ['op' => 'take'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'conflict', 'current' => true],
                ['op' => 'discard', 'ref' => 'r1', 'field' => 'qualified'],
                ['op' => 'snapshot'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'result', 'to' => ['seconds' => 100], 'server' => null],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'conflict', 'current' => ['seconds' => 90]],
                ['op' => 'set', 'ref' => 'r1', 'field' => 'result', 'to' => ['seconds' => 110], 'server' => ['seconds' => 90]],
                ['op' => 'snapshot'],
            ]],
        ]);

        self::assertSame('conflict', self::path($keepMine, 1, 'cells', 0, 'status'));
        self::assertSame(['current' => ['seconds' => 90], 'enteredBy' => ['playerId' => 'p', 'name' => 'Eva'], 'enteredAt' => null], self::path($keepMine, 1, 'cells', 0, 'conflict'));
        // The desk keeps showing the organiser's value next to theirs
        self::assertSame(['seconds' => 100], self::path($keepMine, 1, 'shown'));
        // Keep mine = sent again, now from what somebody else saved
        self::assertSame(['clientChangeId' => 'c2', 'entry' => 'r1', 'field' => 'result', 'from' => ['seconds' => 90], 'to' => ['seconds' => 100]], self::path($keepMine, 2, 'taken', 0));

        self::assertSame([], self::path($takeTheirs, 1, 'cells'));

        self::assertSame(['seconds' => 90], self::path($typedOver, 1, 'cells', 0, 'base'));
        self::assertSame(['seconds' => 110], self::path($typedOver, 1, 'cells', 0, 'to'));
        self::assertSame('queued', self::path($typedOver, 1, 'cells', 0, 'status'));
    }

    public function testAValueChangedWhileTheFirstIsOnItsWayFollowsIt(): void
    {
        [$chained, $limited] = $this->runInNode([
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'table_number', 'to' => 5, 'server' => null],
                ['op' => 'take'],
                ['op' => 'set', 'ref' => 'r1', 'field' => 'table_number', 'to' => 7, 'server' => null],
                ['op' => 'take'],
                ['op' => 'settle', 'index' => 0, 'status' => 'applied'],
                ['op' => 'take'],
            ]],
            ['fn' => 'pending', 'steps' => [
                ['op' => 'set', 'ref' => 'r1', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'set', 'ref' => 'r2', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'set', 'ref' => 'r3', 'field' => 'qualified', 'to' => true, 'server' => false],
                ['op' => 'take', 'limit' => 2],
                ['op' => 'take', 'limit' => 2],
            ]],
        ]);

        // Nothing goes twice at once; after the first answer the second value goes, from the first
        self::assertSame([], self::path($chained, 1, 'taken'));
        self::assertSame([['clientChangeId' => 'c2', 'entry' => 'r1', 'field' => 'table_number', 'from' => 5, 'to' => 7]], self::path($chained, 2, 'taken'));

        self::assertSame(['r1', 'r2'], array_column(self::rows(self::path($limited, 0, 'taken')), 'entry'));
        self::assertSame(['r3'], array_column(self::rows(self::path($limited, 1, 'taken')), 'entry'));
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<mixed>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/official-results-desk-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
    private static function path(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<array<mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        foreach ($value as $row) {
            self::assertIsArray($row);
        }

        /** @var array<array<mixed>> $value */
        return $value;
    }
}
