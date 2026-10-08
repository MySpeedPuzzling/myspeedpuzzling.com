<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participants spreadsheet's round tabs in the browser (assets/participants_sheet/{sheet_results,round_paste,
 * round_common}.js, stream D), executed by node through tests/participants-sheet-round-harness.mjs - the suites under
 * tests/participants-sheet-round/ (node:assert) and a few facts asserted here:
 * - results: the result cell's grammar (times via parseResultTime, pieces placed, did not start), table numbers, the
 *   "Swap them" write, RecordRoundResults changes with their exact inverse, ranks as the page shows them;
 * - paste: rows of pairs/teams (by name, by members, ambiguous names and people, new people, moves), positional pastes,
 *   results pastes matched only to the round's entries;
 * - common: O1 labels, the pickers' options, sizes in words (O7), row order, member slots, results columns (O3).
 */
final class ParticipantsSheetRoundScriptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function suites(): iterable
    {
        foreach (['results', 'paste', 'common'] as $suite) {
            yield $suite => [$suite];
        }
    }

    #[DataProvider('suites')]
    public function testSuitePasses(string $suite): void
    {
        [$result] = $this->runInNode([['suite' => $suite]]);

        self::assertIsArray($result);
        self::assertArrayHasKey('failures', $result);
        self::assertArrayHasKey('passed', $result);

        $failures = $result['failures'];
        self::assertIsArray($failures);
        self::assertSame([], $failures, $suite . ": \n" . json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(5, $result['passed'], 'the suite ran its tests');
    }

    public function testAResultCellReadsTimesPiecesPlacedAndDidNotStart(): void
    {
        [$parsed] = $this->runInNode([[
            'fn' => 'parseResultInput',
            'inputs' => ['1:23:45', '58:12', '12345', '479p', '479 pcs', '479/500', 'DNS', '-', '', '500p', 'soon'],
            'options' => ['piecesCount' => 500],
        ]]);

        self::assertSame([
            ['kind' => 'result', 'result' => ['seconds' => 5025]],
            ['kind' => 'result', 'result' => ['seconds' => 3492]],
            ['kind' => 'result', 'result' => ['seconds' => 5025]],
            ['kind' => 'result', 'result' => ['piecesPlaced' => 479]],
            ['kind' => 'result', 'result' => ['piecesPlaced' => 479]],
            ['kind' => 'result', 'result' => ['piecesPlaced' => 479]],
            ['kind' => 'result', 'result' => ['didNotStart' => true]],
            ['kind' => 'result', 'result' => ['didNotStart' => true]],
            ['kind' => 'empty'],
            // A finished puzzle gets a time - pieces placed are below the piece count
            ['kind' => 'error', 'reason' => 'pieces_range', 'max' => 499],
            ['kind' => 'error', 'reason' => 'invalid'],
        ], $parsed);
    }

    public function testSixPairsPastedFromExcelBecomeOneGroupEach(): void
    {
        [$paste] = $this->runInNode([[
            'fn' => 'pasteTeams',
            'state' => self::pairsState(),
            'roundId' => 'r-pairs',
            'text' => "Corners\tAnn Example\tPat Sample\r\nOwls\tKim Example\tJo New\r\n\tLee Mock\tMax Demo\r\nBats\tPat Sample\tAna Fictive\r\nCorners\tKim Example\r\nRavens\tUma Test\tVic Test\r\n",
        ]]);

        self::assertIsArray($paste);
        self::assertSame([
            // the existing pair, unchanged people
            ['match' => 'existing', 'teamId' => 't-corners', 'name' => 'Corners', 'members' => ['one', 'one']],
            // Jo New is not on the list - offered as a new participant (D9)
            ['match' => 'new', 'teamId' => null, 'name' => 'Owls', 'members' => ['one', 'none']],
            // no name, the people of exactly one pair: that pair, no duplicate
            ['match' => 'existing', 'teamId' => 't-lee', 'name' => null, 'members' => ['one', 'one']],
            ['match' => 'new', 'teamId' => null, 'name' => 'Bats', 'members' => ['one', 'none']],
            ['match' => 'existing', 'teamId' => 't-corners', 'name' => 'Corners', 'members' => ['one']],
            ['match' => 'new', 'teamId' => null, 'name' => 'Ravens', 'members' => ['none', 'none']],
        ], $paste['lines']);
        // Moves = people of pairs that exist now (Pat leaves Corners for Bats); Kim comes from the tray
        self::assertSame(['newTeams' => 3, 'moves' => 1, 'newPeople' => 4, 'ambiguousTeams' => 0, 'ambiguousPeople' => 0, 'sharedNames' => 0, 'renames' => 0], $paste['counts']);
        self::assertSame([], $paste['errors']);

        self::assertIsArray($paste['groups']);
        // Line 1 (Corners as it is) and line 3 (Lee + Max as they are) change nothing - 4 groups for the other rows
        self::assertCount(4, $paste['groups']);
        self::assertSame([
            ['op' => 'newParticipant', 'id' => 'id1', 'name' => 'Jo New', 'country' => null, 'externalId' => null],
            ['op' => 'newTeam', 'id' => 'id2', 'round' => 'r-pairs', 'name' => 'Owls'],
            ['op' => 'place', 'participant' => 'p-kim', 'round' => 'r-pairs', 'from' => 'in', 'to' => 'team:id2'],
            ['op' => 'place', 'participant' => 'id1', 'round' => 'r-pairs', 'from' => 'out', 'to' => 'team:id2'],
        ], $paste['groups'][0]['changes']);
        // The later Corners line: Kim moves back from Owls, Ann and Pat (not listed there) go to the tray
        self::assertSame([
            ['op' => 'place', 'participant' => 'p-kim', 'round' => 'r-pairs', 'from' => 'team:id2', 'to' => 'team:t-corners'],
            ['op' => 'place', 'participant' => 'p-ann', 'round' => 'r-pairs', 'from' => 'team:t-corners', 'to' => 'in'],
        ], $paste['groups'][2]['changes']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pairsState(): array
    {
        $person = static fn (string $id, string $name): array => ['id' => $id, 'name' => $name, 'country' => null, 'removedAt' => null, 'player' => null, 'playerResultRounds' => []];
        $place = static fn (string $id, string $participant, null|string $team): array => ['id' => $id, 'participantId' => $participant, 'roundId' => 'r-pairs', 'teamId' => $team, 'result' => null, 'qualified' => false];

        return [
            'version' => 'v1',
            'competition' => ['id' => 'c1', 'isOnline' => false],
            'rounds' => [['id' => 'r-pairs', 'name' => 'Pairs', 'category' => 'duo', 'teamSize' => null]],
            'people' => [$person('p-ann', 'Ann Example'), $person('p-kim', 'Kim Example'), $person('p-lee', 'Lee Mock'), $person('p-max', 'Max Demo'), $person('p-pat', 'Pat Sample')],
            'places' => [$place('e1', 'p-ann', 't-corners'), $place('e2', 'p-pat', 't-corners'), $place('e3', 'p-kim', null), $place('e4', 'p-lee', 't-lee'), $place('e5', 'p-max', 't-lee')],
            'teams' => [
                ['id' => 't-corners', 'roundId' => 'r-pairs', 'name' => 'Corners', 'result' => null, 'qualified' => false],
                ['id' => 't-lee', 'roundId' => 'r-pairs', 'name' => null, 'result' => null, 'qualified' => false],
            ],
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

        $process = new Process([$node, __DIR__ . '/participants-sheet-round-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
