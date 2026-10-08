<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The participants spreadsheet's browser core (assets/participants_sheet/*.js, stream C), executed by node through
 * tests/participants-sheet-core-harness.mjs - the suites under tests/participants-sheet-core/ (node:assert) and a few
 * protocol facts asserted here:
 * - tsv: what Excel, Google Sheets, Numbers and LibreOffice put on the clipboard (CRLF, trailing line end, quoted
 *   multi-line cells, HTML tables), and what the sheet copies;
 * - keys: the APG grid keyboard model incl. IME, AltGr and non-Latin layouts;
 * - model: indexes, sizes and problem counts (O7), the optimistic overlay, merging a fetched state and live results;
 * - changes: `from` = what the organiser saw, client refusals with the server's reason codes, exact inverses;
 * - queue: debounce, one request in flight, the version protocol (contract §3.2), retries, signed out / forbidden /
 *   gone, conflicts and refusals as problems, RecordRoundResults and AssignTableNumbers writes, leave guards;
 * - undo: only what went through is undone, a refused undo is reported, Keep mine is a step, a deleted pair's table
 *   number comes back, people removed meanwhile are left out;
 * - live: the sheet topic's version, result updates, version polling, the tab coming back, our own echo;
 * - grid / people / controller (jsdom - a dev dependency in package-lock.json): an editor's `from` is what it showed when
 *   it opened (review B1), the "Changed meanwhile" notice, Tab never unlinks a profile, a refused checkbox snaps back,
 *   typed text survives a rebuilt grid, the setup checklist, ⌘ on a Mac, a view that failed to load;
 * - perf: a bulk action over 1,000 people is one model rebuild and one re-render, its answer too.
 */
final class ParticipantsSheetCoreScriptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function suites(): iterable
    {
        foreach (['tsv', 'keys', 'model', 'changes', 'queue', 'undo', 'live', 'grid', 'people', 'controller', 'perf'] as $suite) {
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
        self::assertGreaterThan(1, $result['passed'], 'the suite ran its tests');
    }

    public function testAnExcelBlockIsReadAsTheSpreadsheetMeantIt(): void
    {
        [$excel, $sheets, $copy] = $this->runInNode([
            ['fn' => 'parseClipboardText', 'text' => "Night Owls\tKim Example\r\n\"Corner\nPieces\"\tPat Sample\r\n"],
            ['fn' => 'parseClipboardText', 'text' => "Night Owls\tKim Example\nSky\tPat Sample"],
            ['fn' => 'toTsv', 'rows' => [['Kim', 'two words'], ['say "hi"', "line\nbreak"]]],
        ]);

        self::assertSame([['Night Owls', 'Kim Example'], ["Corner\nPieces", 'Pat Sample']], $excel);
        self::assertSame([['Night Owls', 'Kim Example'], ['Sky', 'Pat Sample']], $sheets);
        self::assertSame("Kim\ttwo words\n\"say \"\"hi\"\"\"\t\"line\nbreak\"", $copy);
    }

    public function testTypingIntoACellStartsAnEditAndTheBrowserTypesTheCharacter(): void
    {
        $state = ['mode' => 'nav', 'row' => 3, 'col' => 0, 'rowCount' => 10, 'colCount' => 4, 'columns' => [['kind' => 'text']]];

        [$typed, $composing] = $this->runInNode([
            ['fn' => 'nextAction', 'state' => $state, 'event' => ['key' => 'k']],
            ['fn' => 'nextAction', 'state' => ['mode' => 'edit', 'editKind' => 'type'] + $state, 'event' => ['key' => 'Enter', 'isComposing' => true]],
        ]);

        self::assertSame(['action' => 'startEdit', 'replace' => true, 'preventDefault' => false], $typed);
        // A Japanese input method composing: Enter belongs to it, nothing is committed
        self::assertSame(['action' => 'none', 'preventDefault' => false], $composing);
    }

    public function testTypingAPairIntoTheNewRowIsOneGroupThatUndoesExactly(): void
    {
        [$pair] = $this->runInNode([[
            'fn' => 'newTeamRow',
            'state' => self::pairsState(),
            'roundId' => 'r-pairs',
            'row' => ['name' => '  Night   Owls ', 'members' => ['p-kim', ['name' => 'Jo Do', 'country' => 'cz']]],
        ]]);

        self::assertSame([
            'groups' => [[
                'id' => 'id3',
                'changes' => [
                    ['op' => 'newParticipant', 'id' => 'id2', 'name' => 'Jo Do', 'country' => 'cz', 'externalId' => null],
                    ['op' => 'newTeam', 'id' => 'id1', 'round' => 'r-pairs', 'name' => 'Night Owls'],
                    ['op' => 'place', 'participant' => 'p-kim', 'round' => 'r-pairs', 'from' => 'in', 'to' => 'team:id1'],
                    ['op' => 'place', 'participant' => 'id2', 'round' => 'r-pairs', 'from' => 'out', 'to' => 'team:id1'],
                ],
            ]],
            'inverse' => [[
                'id' => 'id4',
                'changes' => [
                    ['op' => 'place', 'participant' => 'id2', 'round' => 'r-pairs', 'from' => 'team:id1', 'to' => 'out'],
                    ['op' => 'place', 'participant' => 'p-kim', 'round' => 'r-pairs', 'from' => 'team:id1', 'to' => 'in'],
                    ['op' => 'deleteTeam', 'team' => 'id1'],
                    ['op' => 'remove', 'participant' => 'id2'],
                ],
            ]],
            'errors' => [],
        ], $pair);
    }

    public function testUndoOfADeletedPairCreatesItAgainWithItsMembers(): void
    {
        [$inverse] = $this->runInNode([[
            'fn' => 'invertGroups',
            'state' => self::pairsState(),
            'groups' => [['id' => 'g1', 'changes' => [['op' => 'deleteTeam', 'team' => 't-corners']]]],
        ]]);

        self::assertSame([[
            'id' => 'inv1',
            'changes' => [
                ['op' => 'newTeam', 'id' => 't-corners', 'round' => 'r-pairs', 'name' => 'Corners'],
                ['op' => 'place', 'participant' => 'p-ann', 'round' => 'r-pairs', 'from' => 'in', 'to' => 'team:t-corners'],
                ['op' => 'place', 'participant' => 'p-pat', 'round' => 'r-pairs', 'from' => 'in', 'to' => 'team:t-corners'],
            ],
        ]], $inverse);
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
            'competition' => ['id' => 'c1'],
            'rounds' => [['id' => 'r-pairs', 'name' => 'Pairs', 'category' => 'duo', 'teamSize' => null]],
            'people' => [$person('p-ann', 'Ann Example'), $person('p-kim', 'Kim Example'), $person('p-pat', 'Pat Sample')],
            'places' => [$place('e1', 'p-ann', 't-corners'), $place('e2', 'p-pat', 't-corners'), $place('e3', 'p-kim', null)],
            'teams' => [['id' => 't-corners', 'roundId' => 'r-pairs', 'name' => 'Corners', 'result' => null, 'qualified' => false]],
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

        $process = new Process([$node, __DIR__ . '/participants-sheet-core-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
